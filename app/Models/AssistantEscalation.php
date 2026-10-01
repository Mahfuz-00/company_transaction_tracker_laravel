<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A QUESTION THE ASSISTANT COULD NOT HANDLE, ROUTED TO THE SOFTWARE SUPER ADMIN.
 *
 * THE LEARNING LOOP LIVES HERE
 * ----------------------------
 *   pending  -> an operator answers  -> answered
 *   answered -> a NEW AssistantKnowledge row is created from `resolution`
 *
 * Once that knowledge row exists, the same question is answered AUTONOMOUSLY next
 * time. `learned_knowledge_id` records which row the resolution produced, so the
 * loop is closed and auditable in both directions: an escalation shows what it
 * taught the assistant, and a learned answer shows which escalation produced it.
 *
 * TWO REASONS, NOT ONE
 * --------------------
 *   unanswered - no corpus match above the confidence floor.
 *   flagged    - the assistant DID answer, but the user said it was wrong.
 *
 * The second is the more valuable signal: it means the corpus contains an answer
 * that is misleading, not merely absent. Merging the two into "escalated" would
 * lose the distinction that tells an operator whether to ADD or REWRITE.
 *
 * NOT TENANT-SCOPED
 * -----------------
 * Like BugReport, this queue spans every workspace: the SSA reviews it in one
 * inbox, and a tenant-scoped model would silently hide other institutions'
 * questions - which are often about the same shared product surface.
 */
class AssistantEscalation extends Model
{
    protected $fillable = [
        'assistant_thread_id',
        'user_id',
        'institution_id',
        'asked_by_name',
        'asked_by_email',
        'question',
        'reason',
        'previous_answer',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
        'learned_knowledge_id',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public const REASONS = ['unanswered', 'flagged'];

    public const STATUSES = ['pending', 'answered', 'dismissed'];

    /* ------------------------------------------------------------------ *
     * Relations
     * ------------------------------------------------------------------ */

    public function thread()
    {
        return $this->belongsTo(AssistantThread::class, 'assistant_thread_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function learnedKnowledge()
    {
        return $this->belongsTo(AssistantKnowledge::class, 'learned_knowledge_id');
    }

    /* ------------------------------------------------------------------ *
     * Presentation + state
     * ------------------------------------------------------------------ */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'flagged' => 'Answer was wrong',
            default => 'No answer found',
        };
    }

    /** A UI tone for the reason chip, so the queue is scannable at a glance. */
    public function reasonTone(): string
    {
        return $this->reason === 'flagged' ? 'rose' : 'amber';
    }

    /**
     * Mark this escalation answered and TURN THE REPLY INTO KNOWLEDGE.
     *
     * This is the write side of the learning loop, and it is deliberately the only
     * place a learned knowledge row is created - so "what makes the assistant
     * learn?" has exactly one answer in the codebase.
     *
     * The new row is TENANT-SCOPED to the asking institution when the escalation
     * came from one, and platform-wide when it did not (a landing-page guest). That
     * is the right default: a workspace's process-specific answer should not be
     * served to another workspace, but a general product answer should be.
     */
    public function resolveWith(string $answer, User $operator): AssistantKnowledge
    {
        $knowledge = AssistantKnowledge::create([
            // A guest question (no institution) becomes platform-wide knowledge.
            'institution_id' => $this->institution_id,
            'question' => $this->question,
            'answer' => $answer,
            'phrasings' => [],
            'keywords' => [],
            'source' => 'learned',
            'source_escalation_id' => $this->getKey(),
            'authored_by' => $operator->getKey(),
            'is_active' => true,
        ]);

        $this->forceFill([
            'status' => 'answered',
            'resolution' => $answer,
            'resolved_by' => $operator->getKey(),
            'resolved_at' => now(),
            'learned_knowledge_id' => $knowledge->getKey(),
        ])->save();

        return $knowledge;
    }

    /**
     * Close this escalation without teaching the assistant anything.
     *
     * Used when the question is a duplicate, out of scope, or already covered -
     * so the queue reflects a real decision rather than an unhandled item.
     */
    public function dismissWith(string $reason, User $operator): void
    {
        $this->forceFill([
            'status' => 'dismissed',
            'resolution' => $reason,
            'resolved_by' => $operator->getKey(),
            'resolved_at' => now(),
        ])->save();
    }
}
