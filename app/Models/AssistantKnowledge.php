<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE ANSWERABLE QUESTION IN THE ASSISTANT'S CORPUS.
 *
 * A row is either SHIPPED (`source = docs`) or LEARNED from an SSA's response to
 * an escalation (`source = learned`). Both are retrieved identically - the point of
 * the learning loop is that a learned answer becomes indistinguishable from a
 * built-in one, so the user never has to be told "ask again later".
 *
 * WHY `phrasings` IS A JSON ARRAY RATHER THAN MANY ROWS
 * -----------------------------------------------------
 * A curator maintains ANSWERS, not phrasings. Ten rows for one answer would drift
 * apart the moment one was edited. Keeping the alternates on the row means there is
 * exactly one place to change what the assistant says about a topic.
 *
 * TENANT SCOPING
 * --------------
 * This model is deliberately NOT tenant-scoped by a global scope. The corpus has
 * two layers - platform-wide rows (`institution_id` NULL) and tenant rows - and the
 * retrieval query must consider BOTH. A global scope would hide the platform layer
 * from every tenant, which would leave a fresh workspace with no answers at all.
 * Access is therefore explicit in `searchable()`, which is the one place the
 * two-layer rule is expressed.
 */
class AssistantKnowledge extends Model
{
    protected $fillable = [
        'institution_id',
        'question',
        'answer',
        'phrasings',
        'keywords',
        'source',
        'source_escalation_id',
        'authored_by',
        'times_used',
        'times_unhelpful',
        'is_active',
    ];

    protected $casts = [
        'phrasings' => 'array',
        'keywords' => 'array',
        'is_active' => 'boolean',
        'times_used' => 'integer',
        'times_unhelpful' => 'integer',
    ];

    public const SOURCES = ['docs', 'learned'];

    /* ------------------------------------------------------------------ *
     * Relations
     * ------------------------------------------------------------------ */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    public function sourceEscalation()
    {
        return $this->belongsTo(AssistantEscalation::class, 'source_escalation_id');
    }

    /* ------------------------------------------------------------------ *
     * Retrieval
     * ------------------------------------------------------------------ */

    /**
     * The corpus visible to one institution: its OWN answers plus the platform's.
     *
     * This is the single place the two-layer rule lives. A tenant row with the same
     * question as a platform row is allowed - and `search()` prefers the tenant's,
     * because a workspace that has written its own answer should get it.
     */
    public static function searchable(?int $institutionId): Builder
    {
        return static::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($institutionId) {
                // The platform corpus, shared by everyone.
                $q->whereNull('institution_id');

                // Plus this workspace's own additions, when there is one.
                if ($institutionId !== null) {
                    $q->orWhere('institution_id', $institutionId);
                }
            });
    }

    /** Every searchable string on this row, lower-cased, for matching. */
    public function searchTerms(): array
    {
        $terms = [(string) $this->question];

        foreach ((array) $this->phrasings as $phrase) {
            if (is_string($phrase) && $phrase !== '') {
                $terms[] = $phrase;
            }
        }

        foreach ((array) $this->keywords as $keyword) {
            if (is_string($keyword) && $keyword !== '') {
                $terms[] = $keyword;
            }
        }

        return array_map(fn (string $t) => mb_strtolower($t), $terms);
    }

    /** Record that this answer was served, so the corpus can be quality-ranked. */
    public function recordUse(): void
    {
        // `increment` issues a single UPDATE rather than a read-modify-write, so
        // concurrent users cannot lose each other's counts.
        static::query()->whereKey($this->getKey())->increment('times_used');
    }

    /** Record that a user flagged this answer as unhelpful. */
    public function recordUnhelpful(): void
    {
        static::query()->whereKey($this->getKey())->increment('times_unhelpful');
    }

    /**
     * Is this answer's quality suspect?
     *
     * Used by the SSA's escalation queue to highlight corpus rows that are
     * repeatedly rejected - the signal that an answer needs rewriting rather than
     * that a new question needs adding.
     */
    public function looksUnreliable(): bool
    {
        return $this->times_unhelpful >= 3
            && $this->times_unhelpful >= $this->times_used / 2;
    }
}
