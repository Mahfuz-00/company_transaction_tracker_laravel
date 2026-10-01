<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * ONE SUPPORT CONVERSATION.
 *
 * WHY A THREAD AND NOT A FLAT LOG
 * -------------------------------
 * A follow-up like "and how do I reverse it?" is meaningless without the question
 * before it, and the assistant uses the thread's earlier turns to resolve pronouns
 * and context. A flat log would force the user to re-state everything.
 *
 * GUESTS ARE FIRST-CLASS
 * ----------------------
 * `user_id` is nullable because the assistant is available on the PUBLIC landing
 * page, before anyone has an account. Such a thread is identified by a random
 * `token` (never the sequential id), so a guest's conversation can be continued
 * across requests without exposing how many conversations exist platform-wide.
 */
class AssistantThread extends Model
{
    protected $fillable = [
        'user_id',
        'institution_id',
        'token',
        'surface',
    ];

    public const SURFACES = ['landing', 'dashboard'];

    protected static function booted(): void
    {
        // Every thread gets an unguessable token on creation, so a caller never has
        // to remember to set one (and cannot accidentally reuse a predictable id).
        static::creating(function (AssistantThread $thread) {
            if (blank($thread->token)) {
                $thread->token = Str::random(48);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function messages()
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('created_at');
    }

    public function escalations()
    {
        return $this->hasMany(AssistantEscalation::class);
    }

    /**
     * The most recent user questions, oldest first.
     *
     * Bounded deliberately: a long conversation would otherwise grow the prompt
     * without limit. Six turns is enough to resolve "it" and "that one" while
     * keeping retrieval cheap and predictable.
     *
     * @return array<int, string>
     */
    public function recentUserQuestions(int $limit = 6): array
    {
        return $this->messages()
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('body')
            ->reverse()
            ->values()
            ->all();
    }
}
