<?php

namespace App\Http\Controllers;

use App\Models\AssistantEscalation;
use App\Models\AssistantMessage;
use App\Support\AuditLogger;
use App\Support\SupportAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * THE SUPPORT ASSISTANT — the user-facing side.
 *
 * WHERE IT LIVES
 *   - PUBLICLY on the landing page (a visitor with no account can ask a question
 *     before signing up — that is often the moment the question exists).
 *   - INSIDE the dashboard for every authenticated role.
 *
 * WHY IT IS NOT PERMISSION-GATED
 *   Asking for help is not a privileged act. The routes are open to guests and to
 *   every role; the controller's only rules are that a question must be non-empty
 *   and rate-limited, and that a thread token must match the conversation being
 *   continued.
 *
 * THREAD OWNERSHIP
 *   A signed-in user's thread is resolved from their own account, so one user can
 *   never read or continue another's conversation. A guest's thread is keyed by a
 *   random token the client holds — never a sequential id — so conversations are
 *   not enumerable.
 *
 * NO LLM, AND THAT IS THE POINT
 *   Answers come from a curated corpus (`assistant_knowledge`). When nothing matches
 *   well enough the assistant says so and ESCALATES, which is what makes it learn:
 *   the SSA's reply becomes a new corpus row and the same question is answered
 *   autonomously next time.
 */
class AssistantController extends Controller
{
    /** How many questions one client may ask per minute. */
    protected const ASK_THROTTLE = 20;

    /* ------------------------------------------------------------------ *
     * Conversation
     * ------------------------------------------------------------------ */

    /**
     * POST /assistant/ask
     *
     * Ask a question and receive the assistant's reply. Returns the reply, whether
     * it was matched, and whether an escalation was opened.
     */
    public function ask(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'surface' => ['nullable', 'string', 'in:landing,dashboard'],
            'token' => ['nullable', 'string', 'max:64'],
        ]);

        // Throttle by IP: a support bot is an obvious target for scraping the
        // corpus, and a human asking genuine questions never approaches this rate.
        $key = 'assistant-ask:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, static::ASK_THROTTLE)) {
            return response()->json([
                'message' => 'You are asking very quickly. Please wait a moment and try again.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $assistant = new SupportAssistant;

        $surface = $data['surface'] ?? ($request->user() ? 'dashboard' : 'landing');

        $thread = $assistant->openThread(
            $surface,
            $request->user(),
            $data['token'] ?? null
        );

        $result = $assistant->ask($thread, $data['question']);

        return response()->json([
            'token' => $thread->token,
            'matched' => $result['matched'],
            'escalated' => $result['escalation'] !== null,
            'confidence' => (float) ($result['message']->confidence ?? 0),
            'answer' => $result['message']->body,
            'message_id' => $result['message']->id,
            // The UI shows this so the user knows the answer is grounded in the
            // documentation rather than generated.
            'source' => $result['matched'] ? 'docs' : null,
        ]);
    }

    /**
     * POST /assistant/messages/{message}/flag
     *
     * "That answer was wrong." Records the rating AND escalates, carrying the bad
     * answer along so the operator knows whether to ADD an answer or REWRITE one.
     */
    public function flag(Request $request, AssistantMessage $message)
    {
        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $thread = $message->thread;

        abort_if($thread === null, 404);

        // A signed-in user may only flag their OWN conversation.
        if ($request->user() && $thread->user_id !== null && $thread->user_id !== $request->user()->id) {
            abort(403);
        }

        $escalation = (new SupportAssistant)->flag($message, $data['comment'] ?? null);

        if ($escalation === null) {
            return response()->json([
                'message' => 'That message could not be flagged.',
            ], 422);
        }

        AuditLogger::log('created', 'flagged a support answer as unhelpful', $escalation, [
            'question' => $escalation->question,
        ], ['subject_label' => $escalation->asked_by_name, 'institution_id' => $escalation->institution_id]);

        return response()->json([
            'escalated' => true,
            'message' => 'Thanks — we have passed this to the platform team and will get back to you.',
        ]);
    }

    /**
     * POST /assistant/messages/{message}/helpful
     *
     * Confirms an answer worked. This is the positive half of the quality signal:
     * without it, the corpus only ever learns from complaints.
     */
    public function helpful(Request $request, AssistantMessage $message)
    {
        $thread = $message->thread;

        abort_if($thread === null, 404);

        if ($request->user() && $thread->user_id !== null && $thread->user_id !== $request->user()->id) {
            abort(403);
        }

        (new SupportAssistant)->markHelpful($message);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /assistant/history
     *
     * The current user's conversation, so reopening the panel continues where they
     * left off rather than starting blank.
     */
    public function history(Request $request)
    {
        $user = $request->user();

        // A guest has no persisted history to return; their thread lives in the
        // page's own state for the session.
        if (! $user) {
            return response()->json(['messages' => []]);
        }

        $assistant = new SupportAssistant;
        $thread = $assistant->openThread('dashboard', $user);

        return response()->json([
            'token' => $thread->token,
            'messages' => $thread->messages()
                ->orderBy('created_at')
                ->get()
                ->map(fn (AssistantMessage $m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'body' => $m->body,
                    'confidence' => (float) ($m->confidence ?? 0),
                    'flagable' => $m->isFlagable(),
                    'helpful' => $m->was_helpful,
                    'at' => $m->created_at?->format('H:i'),
                ])
                ->all(),
        ]);
    }
}
