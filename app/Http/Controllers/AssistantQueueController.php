<?php

namespace App\Http\Controllers;

use App\Models\AssistantEscalation;
use App\Models\AssistantKnowledge;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * THE ASSISTANT'S ESCALATION QUEUE — Software Super Admin only.
 *
 * THIS IS WHERE THE ASSISTANT LEARNS
 * ----------------------------------
 * A question the assistant could not answer (or answered wrongly) lands here.
 * When the SSA answers it, `AssistantEscalation::resolveWith()` writes the reply
 * into the corpus as a NEW knowledge row — and the same question is answered
 * autonomously from then on, with no human involvement.
 *
 * The queue is therefore not merely a support inbox; it is the training input.
 * That is why resolving is the PRIMARY action and dismissing is the exception, and
 * why the UI shows what the assistant previously said: an operator needs to know
 * whether to ADD an answer or REWRITE one.
 *
 * WHY THIS SPANS EVERY TENANT
 *   Like bug reports, the queue is cross-tenant by design — the SSA reviews it in
 *   one place, and a tenant-scoped model would hide other institutions' questions,
 *   which are frequently about the same shared product surface.
 */
class AssistantQueueController extends Controller
{
    /**
     * GET /platform/assistant
     *
     * The escalation inbox: pending first (oldest-first, so the longest-waiting
     * user wins), plus the corpus quality view.
     */
    public function index(Request $request)
    {
        $status = (string) $request->query('status', 'pending');
        $reason = (string) $request->query('reason', '');
        $institutionId = $request->query('institution');

        $escalations = AssistantEscalation::query()
            ->with(['institution:id,name', 'resolver:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($reason !== '', fn ($q) => $q->where('reason', $reason))
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            /*
             * `flagged` first: an answer that was WRONG is actively misleading
             * someone right now, whereas an unanswered question merely lacks help.
             * Within a reason, oldest first so nobody is starved.
             */
            ->orderByRaw("CASE reason WHEN 'flagged' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AssistantEscalation $e) => $this->present($e));

        $base = fn () => AssistantEscalation::query()->when(
            $institutionId,
            fn ($q) => $q->where('institution_id', $institutionId)
        );

        return Inertia::render('SSA/Assistant/Index', [
            'escalations' => $escalations,

            'stats' => [
                'pending' => $base()->where('status', 'pending')->count(),
                'flagged' => $base()->where('status', 'pending')->where('reason', 'flagged')->count(),
                'answered' => $base()->where('status', 'answered')->count(),
                'dismissed' => $base()->where('status', 'dismissed')->count(),
                'learned_total' => AssistantKnowledge::where('source', 'learned')->count(),
            ],

            /*
             * CORPUS QUALITY.
             *
             * Answers that users repeatedly reject are the ones worth rewriting.
             * Surfacing them here closes the loop on QUALITY, not just coverage:
             * adding answers is not enough if an existing one is misleading.
             */
            'unreliable' => AssistantKnowledge::query()
                ->where('is_active', true)
                ->where('times_unhelpful', '>=', 3)
                ->orderByDesc('times_unhelpful')
                ->limit(10)
                ->get()
                ->map(fn (AssistantKnowledge $k) => [
                    'id' => $k->id,
                    'question' => $k->question,
                    'answer' => $k->answer,
                    'source' => $k->source,
                    'times_used' => $k->times_used,
                    'times_unhelpful' => $k->times_unhelpful,
                    'institution' => $k->institution?->name,
                ]),

            'institutions' => Institution::query()->orderBy('name')->get(['id', 'name']),

            'filters' => [
                'status' => $status,
                'reason' => $reason,
                'institution' => $institutionId,
            ],
        ]);
    }

    /**
     * PATCH /platform/assistant/{escalation}
     *
     * Answer (which TEACHES the assistant) or dismiss the escalation.
     */
    public function update(Request $request, AssistantEscalation $escalation)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['answer', 'dismiss'])],
            // Required when answering: an empty reply would create a knowledge row
            // that answers nothing, which is worse than leaving it pending.
            'resolution' => ['required_if:action,answer', 'nullable', 'string', 'max:5000'],
        ]);

        $operator = $request->user();

        if ($data['action'] === 'answer') {
            $knowledge = $escalation->resolveWith($data['resolution'], $operator);

            AuditLogger::log('updated', 'answered a support escalation and taught the assistant', $escalation, [
                'question' => $escalation->question,
                'knowledge_id' => $knowledge->id,
            ], ['subject_label' => $escalation->asked_by_name, 'institution_id' => $escalation->institution_id]);

            /*
             * Tell the user who was waiting. Best-effort: the answer is already
             * learned and stored, so a notification failure must not undo that.
             */
            try {
                Notifier::supportAnswered($escalation, $knowledge);
            } catch (\Throwable $e) {
                report($e);
            }

            return back()->with(
                'success',
                "Answered. The assistant will now handle this question on its own (knowledge #{$knowledge->id})."
            );
        }

        $escalation->dismissWith($data['resolution'] ?? 'Dismissed by the platform team.', $operator);

        AuditLogger::log('updated', 'dismissed a support escalation', $escalation, [
            'reason' => $escalation->reason,
        ], ['subject_label' => $escalation->asked_by_name, 'institution_id' => $escalation->institution_id]);

        return back()->with('success', 'Escalation dismissed. The assistant was not changed.');
    }

    /**
     * POST /platform/assistant/knowledge
     *
     * Add a corpus answer directly, without waiting for an escalation.
     *
     * Used to pre-empt questions the operator knows are coming (a new feature, a
     * change in process) rather than only reacting to them.
     */
    public function storeKnowledge(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:5000'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'phrasings' => ['nullable', 'array'],
            'phrasings.*' => ['string', 'max:300'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:60'],
        ]);

        $knowledge = AssistantKnowledge::create([
            // NULL means platform-wide, which is the sensible default.
            'institution_id' => $data['institution_id'] ?? null,
            'question' => $data['question'],
            'answer' => $data['answer'],
            'phrasings' => array_values($data['phrasings'] ?? []),
            'keywords' => array_values($data['keywords'] ?? []),
            'source' => 'learned',
            'authored_by' => $request->user()->id,
            'is_active' => true,
        ]);

        AuditLogger::log('created', 'added a support answer to the assistant corpus', $knowledge, [
            'question' => $knowledge->question,
        ], ['subject_label' => $knowledge->question, 'institution_id' => $knowledge->institution_id]);

        return back()->with('success', 'Answer added to the assistant corpus.');
    }

    /* ------------------------------------------------------------------ */

    /** Serialise an escalation for the React queue. */
    protected function present(AssistantEscalation $escalation): array
    {
        return [
            'id' => $escalation->id,
            'question' => $escalation->question,
            'reason' => $escalation->reason,
            'reason_label' => $escalation->reasonLabel(),
            'reason_tone' => $escalation->reasonTone(),
            'status' => $escalation->status,
            'is_pending' => $escalation->isPending(),
            'previous_answer' => $escalation->previous_answer,
            'resolution' => $escalation->resolution,
            'learned_knowledge_id' => $escalation->learned_knowledge_id,
            'asked_by' => [
                'name' => $escalation->asked_by_name,
                'email' => $escalation->asked_by_email,
            ],
            'institution' => $escalation->institution?->name,
            'institution_id' => $escalation->institution_id,
            'resolved_by' => $escalation->resolver?->name,
            'resolved_at' => $escalation->resolved_at?->format('j M Y, H:i'),
            'created_at' => $escalation->created_at?->format('j M Y, H:i'),
            'created_human' => $escalation->created_at?->diffForHumans(),
        ];
    }
}
