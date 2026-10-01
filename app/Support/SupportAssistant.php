<?php

namespace App\Support;

use App\Models\AssistantEscalation;
use App\Models\AssistantKnowledge;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Institution;
use App\Models\User;
use App\Support\Notifier;

/**
 * THE SELF-LEARNING SUPPORT ASSISTANT.
 *
 * WHAT IT IS, AND WHAT IT REFUSES TO BE
 * -------------------------------------
 * It is a RETRIEVAL system over a curated corpus. It does not generate prose, and
 * it does not guess. If nothing in the corpus matches well enough it says so and
 * offers to escalate - because a support bot that invents an answer about a
 * financial figure is worse than no bot at all.
 *
 * WHY NOT A GENERATIVE MODEL
 * --------------------------
 * Two reasons, both practical:
 *   1. This deployment has no LLM runtime. Adding a Python/GPU dependency to
 *      answer "how do I record a deposit" would be absurd.
 *   2. Every answer about money here must be traceable. "Answered from the
 *      NomNomytics documentation" is a claim the UI makes, and it is only honest if
 *      the answer really did come from a specific corpus row - which is exactly
 *      what `assistant_knowledge_id` records.
 *
 * THE LEARNING LOOP (the feature, not an afterthought)
 * ----------------------------------------------------
 *   1. `answer()` finds no match above the confidence floor.
 *   2. It records an `AssistantEscalation` for the SSA and tells the user.
 *   3. The SSA replies; `AssistantEscalation::resolveWith()` creates a NEW
 *      `AssistantKnowledge` row from that reply.
 *   4. The same question next time matches that row and is answered with NO human
 *      involvement.
 *
 * A user can also FLAG a wrong answer, which escalates the same way but records the
 * bad answer alongside the question - so the operator knows whether to add an
 * answer or rewrite one.
 *
 * SCORING
 * -------
 * Deliberately transparent and dependency-free: token overlap with the question and
 * its phrasings, plus a bonus for keyword hits, normalised to 0..1. It is a
 * lexical matcher, which is honest about its limits - and the confidence floor is
 * what stops those limits from producing confident nonsense.
 */
class SupportAssistant
{
    /**
     * The minimum score at which an answer is served WITHOUT hedging.
     *
     * Below this the assistant still offers its best guess, but labels it as
     * uncertain and offers escalation. Above it, the answer is presented plainly.
     */
    public const CONFIDENT_THRESHOLD = 0.45;

    /**
     * The floor beneath which NO answer is served at all.
     *
     * A score this low means the corpus does not really cover the question, and
     * serving the nearest row anyway is how a support bot ends up confidently
     * wrong. Better to say "I don't know" and learn.
     */
    public const MINIMUM_THRESHOLD = 0.12;

    /** Words that carry no retrieval signal. */
    protected const STOPWORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'do', 'does', 'did', 'how', 'what', 'when', 'where', 'who', 'why',
        'i', 'we', 'you', 'my', 'our', 'your', 'it', 'its', 'this', 'that',
        'to', 'of', 'in', 'on', 'at', 'for', 'with', 'and', 'or', 'but', 'if',
        'can', 'could', 'should', 'would', 'will', 'shall', 'may', 'might',
        'me', 'us', 'them', 'they', 'he', 'she', 'there', 'here', 'please',
    ];

    public function __construct(
        protected ?Institution $institution = null,
    ) {
        $this->institution = $institution ?? Institution::current();
    }

    /* ------------------------------------------------------------------ *
     * ASKING
     * ------------------------------------------------------------------ */

    /**
     * Answer a question inside a thread, persisting both turns.
     *
     * @return array{message:AssistantMessage,escalation:?AssistantEscalation,matched:bool}
     */
    public function ask(AssistantThread $thread, string $question): array
    {
        // 1. Record what the user asked.
        $userMessage = $thread->messages()->create([
            'role' => 'user',
            'body' => $question,
        ]);

        // 2. Retrieve.
        $match = $this->search($question);

        // 3. No usable match -> escalate rather than invent.
        if ($match === null) {
            $assistantMessage = $thread->messages()->create([
                'role' => 'assistant',
                'body' => $this->cannotAnswerReply(),
                'confidence' => 0,
            ]);

            $escalation = $this->escalate($thread, $question, 'unanswered', null);

            return [
                'message' => $assistantMessage,
                'escalation' => $escalation,
                'matched' => false,
            ];
        }

        /** @var AssistantKnowledge $knowledge */
        $knowledge = $match['knowledge'];

        // 4. Serve the answer, with its provenance recorded.
        $assistantMessage = $thread->messages()->create([
            'role' => 'assistant',
            'body' => $knowledge->answer,
            'assistant_knowledge_id' => $knowledge->getKey(),
            'confidence' => $match['score'],
        ]);

        $knowledge->recordUse();

        return [
            'message' => $assistantMessage,
            'escalation' => null,
            'matched' => true,
        ];
    }

    /**
     * Find the best corpus row for a question, or null when nothing is close enough.
     *
     * @return array{knowledge:AssistantKnowledge,score:float}|null
     */
    public function search(string $question): ?array
    {
        $tokens = $this->tokenise($question);

        // A question made entirely of stopwords ("how do I") carries no signal, so
        // it must not be matched against anything.
        if ($tokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        $candidates = AssistantKnowledge::searchable($this->institution?->id)->get();

        foreach ($candidates as $knowledge) {
            $score = $this->score($tokens, $knowledge);

            /*
             * A tenant's OWN answer beats a platform answer on a tie, because a
             * workspace that has written its own answer clearly wants it used.
             */
            if ($score > $bestScore || ($score === $bestScore && $best !== null
                && $knowledge->institution_id !== null && $best->institution_id === null)) {
                $bestScore = $score;
                $best = $knowledge;
            }
        }

        if ($best === null || $bestScore < static::MINIMUM_THRESHOLD) {
            return null;
        }

        return ['knowledge' => $best, 'score' => round($bestScore, 4)];
    }

    /**
     * Score one corpus row against the question's tokens, in 0..1.
     *
     * HOW IT WORKS
     *   1. For each of the row's searchable strings, measure what fraction of the
     *      question's meaningful tokens appear in it. Take the BEST string, so an
     *      exact phrasing match is not diluted by the row's other phrasings.
     *   2. Add a small bonus for keyword hits, which catch domain terms the user
     *      typed that are not in the question phrasing ("roll", "invite code").
     *   3. Cap at 1.0.
     */
    protected function score(array $questionTokens, AssistantKnowledge $knowledge): float
    {
        $bestOverlap = 0.0;

        foreach ($knowledge->searchTerms() as $term) {
            $termTokens = array_flip($this->tokenise($term));

            if ($termTokens === []) {
                continue;
            }

            $hits = 0;

            foreach ($questionTokens as $token) {
                if (isset($termTokens[$token])) {
                    $hits++;
                }
            }

            $overlap = $hits / count($questionTokens);

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
            }
        }

        // Keyword bonus: a domain term the user typed that is not in the phrasing.
        $keywordBonus = 0.0;

        foreach ((array) $knowledge->keywords as $keyword) {
            if (! is_string($keyword) || $keyword === '') {
                continue;
            }

            if (in_array(mb_strtolower($keyword), $questionTokens, true)) {
                $keywordBonus = 0.15;

                break;
            }
        }

        return min($bestOverlap + $keywordBonus, 1.0);
    }

    /**
     * Split a question into meaningful, comparable tokens.
     *
     * Punctuation is stripped and stopwords removed, so "How do I add a member?" and
     * "add member" reduce to the same tokens.
     *
     * @return array<int, string>
     */
    protected function tokenise(string $text): array
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text));

        if ($cleaned === null) {
            return [];
        }

        $words = preg_split('/\s+/u', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $meaningful = array_filter(
            $words,
            fn (string $word) => mb_strlen($word) > 1
                && ! in_array($word, static::STOPWORDS, true)
        );

        return array_values(array_unique($meaningful));
    }

    /* ------------------------------------------------------------------ *
     * FLAGGING (the other way to learn)
     * ------------------------------------------------------------------ */

    /**
     * A user says an answer was wrong.
     *
     * This does two things, and both matter:
     *   1. It records the rating on the message, so the answer's quality is tracked.
     *   2. It ESCALATES, carrying the bad answer along - so the operator sees what
     *      was said, not merely that something was rejected.
     */
    public function flag(AssistantMessage $message, ?string $comment = null): ?AssistantEscalation
    {
        // Only an ASSISTANT turn can be flagged: a user flagging their own
        // question is meaningless, and would let a crafted request create
        // escalations with no answer attached.
        if ($message->role !== 'assistant') {
            return null;
        }

        $message->forceFill(['was_helpful' => false])->save();

        // Attribute the quality signal to the corpus row that produced it.
        if ($message->knowledge) {
            $message->knowledge->recordUnhelpful();
        }

        $thread = $message->thread;

        if (! $thread) {
            return null;
        }

        // The question this answer was replying to: the last user turn before it.
        $question = $thread->messages()
            ->where('role', 'user')
            ->where('created_at', '<=', $message->created_at)
            ->orderByDesc('created_at')
            ->value('body') ?? '(question not recorded)';

        if ($comment !== null && trim($comment) !== '') {
            $question .= "\n\n[User added]: ".trim($comment);
        }

        return $this->escalate($thread, $question, 'flagged', $message->body);
    }

    /** A user confirms an answer was helpful. */
    public function markHelpful(AssistantMessage $message): void
    {
        $message->forceFill(['was_helpful' => true])->save();
    }

    /* ------------------------------------------------------------------ *
     * ESCALATION
     * ------------------------------------------------------------------ */

    /**
     * Route a question to the Software Super Admin and record it for learning.
     *
     * Idempotent per (thread, question): asking the same unanswered thing twice does
     * not stack duplicate queue entries, which would make the SSA's inbox useless.
     */
    public function escalate(
        AssistantThread $thread,
        string $question,
        string $reason,
        ?string $previousAnswer,
    ): ?AssistantEscalation {
        $existing = AssistantEscalation::query()
            ->where('assistant_thread_id', $thread->getKey())
            ->where('question', $question)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return $existing;
        }

        $user = $thread->user;

        $escalation = AssistantEscalation::create([
            'assistant_thread_id' => $thread->getKey(),
            'user_id' => $user?->getKey(),
            // A guest thread has no institution; a dashboard thread inherits it.
            'institution_id' => $thread->institution_id ?? $this->institution?->id,
            'asked_by_name' => $user?->name,
            'asked_by_email' => $user?->email,
            'question' => $question,
            'reason' => $reason,
            'previous_answer' => $previousAnswer,
            'status' => 'pending',
        ]);

        /*
         * Tell the platform owners, because an escalation is the assistant's
         * TRAINING INPUT rather than just a support ticket: the sooner it is
         * answered, the sooner this question is handled autonomously. Best-effort -
         * the escalation row already exists, so a notification failure must not
         * lose it.
         */
        try {
            Notifier::supportEscalated($escalation);
        } catch (\Throwable $e) {
            report($e);
        }

        return $escalation;
    }

    /* ------------------------------------------------------------------ *
     * THREADS
     * ------------------------------------------------------------------ */

    /**
     * Find or open a thread for the current visitor.
     *
     * A signed-in user gets one thread per surface, so closing and reopening the
     * panel continues the same conversation. A guest's thread is tracked by its
     * token, which the client holds for the session.
     */
    public function openThread(string $surface, ?User $user = null, ?string $token = null): AssistantThread
    {
        $user ??= auth()->user();

        if ($user) {
            return AssistantThread::firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'surface' => $surface,
                ],
                [
                    'institution_id' => $user->institution_id ?? $this->institution?->id,
                ]
            );
        }

        // A guest: continue the thread their token names, or start a fresh one.
        if ($token !== null) {
            $existing = AssistantThread::where('token', $token)->first();

            if ($existing) {
                return $existing;
            }
        }

        return AssistantThread::create([
            'user_id' => null,
            'institution_id' => null,
            'surface' => $surface,
        ]);
    }

    /** The reply shown when nothing in the corpus covers the question. */
    protected function cannotAnswerReply(): string
    {
        return "I don't have a confident answer for that yet, so I've passed it to the "
            .'platform team rather than guess. Once they reply, I\'ll know the answer '
            .'for next time.';
    }
}
