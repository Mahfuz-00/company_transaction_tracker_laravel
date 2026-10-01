<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE TURN IN AN ASSISTANT CONVERSATION.
 *
 * `assistant_knowledge_id` and `confidence` are stored on every ASSISTANT message
 * so an answer can always be traced back to the corpus row that produced it, and so
 * a low-confidence answer is visibly hedged in the UI rather than stated as fact.
 * That traceability is what makes the corpus improvable: a bad answer points at a
 * specific row to rewrite.
 */
class AssistantMessage extends Model
{
    protected $fillable = [
        'assistant_thread_id',
        'role',
        'body',
        'assistant_knowledge_id',
        'confidence',
        'was_helpful',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'was_helpful' => 'boolean',
    ];

    public const ROLES = ['user', 'assistant'];

    public function thread()
    {
        return $this->belongsTo(AssistantThread::class, 'assistant_thread_id');
    }

    public function knowledge()
    {
        return $this->belongsTo(AssistantKnowledge::class, 'assistant_knowledge_id');
    }

    /** Was this a message from the user (rather than the assistant)? */
    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Should the UI offer the "this wasn't helpful" flag?
     *
     * Only for assistant messages that HAVE an answer and have not been rated yet.
     * A "I don't know, shall I escalate?" message already carries its own action, so
     * flagging it would be redundant.
     */
    public function isFlagable(): bool
    {
        return $this->role === 'assistant'
            && $this->assistant_knowledge_id !== null
            && $this->was_helpful === null;
    }
}
