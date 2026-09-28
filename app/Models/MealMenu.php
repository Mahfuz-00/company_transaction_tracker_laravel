<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A PROPOSED MEAL MENU.
 *
 * The lifecycle is the point of the module: a menu is proposed, voted on, and then
 * APPROVED by an Admin or Meal Manager before it becomes the active menu. Nothing
 * unapproved is ever presented to members as "the menu".
 */
class MealMenu extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'meal_type',
        'menu_date',
        'title',
        'description',
        'status',
        'voting_opens_at',
        'voting_closes_at',
        'allow_vote_changes',
        'created_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'menu_date' => 'date',
        'voting_opens_at' => 'datetime',
        'voting_closes_at' => 'datetime',
        'approved_at' => 'datetime',
        'allow_vote_changes' => 'boolean',
    ];

    /**
     * The lifecycle, in order.
     *
     * draft -> voting -> approved | rejected, with `cancelled` reachable from any
     * pre-approval state by the proposer.
     */
    public const STATUSES = ['draft', 'voting', 'approved', 'rejected', 'cancelled'];

    /** Statuses a menu can still be edited or approved from. */
    public const OPEN_STATUSES = ['draft', 'voting'];

    public const MEAL_TYPES = [
        'breakfast' => 'Breakfast',
        'lunch' => 'Lunch',
        'dinner' => 'Dinner',
        'snack' => 'Snack',
    ];

    public function options()
    {
        return $this->hasMany(MealMenuOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function votes()
    {
        return $this->hasMany(MealMenuVote::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'approved' => 'emerald',
            'voting' => 'sky',
            'rejected' => 'rose',
            'cancelled' => 'slate',
            default => 'amber',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'voting' => 'Voting open',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) $this->status),
        };
    }

    public function mealTypeLabel(): string
    {
        return self::MEAL_TYPES[$this->meal_type] ?? ucfirst((string) $this->meal_type);
    }

    /** May members cast a vote right now? */
    public function isVotingOpen(): bool
    {
        if ($this->status !== 'voting') {
            return false;
        }

        $now = now();

        if ($this->voting_opens_at && $now->lt($this->voting_opens_at)) {
            return false;
        }

        if ($this->voting_closes_at && $now->gt($this->voting_closes_at)) {
            return false;
        }

        return true;
    }

    /** Is this menu still editable / approvable? */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * The vote tally per option, with each option's share.
     *
     * @return array<int, array{option_id:int, name:string, votes:int, percentage:float, is_winner:bool}>
     */
    public function tally(): array
    {
        // One grouped query, then map onto the options so every option appears even
        // with zero votes (a leaderboard that hides the losers is misleading).
        $counts = $this->votes()
            ->select('meal_menu_option_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('meal_menu_option_id')
            ->pluck('total', 'meal_menu_option_id');

        $total = (int) $counts->sum();

        $rows = $this->options->map(function (MealMenuOption $option) use ($counts, $total) {
            $votes = (int) ($counts->get($option->id) ?? 0);

            return [
                'option_id' => $option->id,
                'name' => $option->name,
                'description' => $option->description,
                'estimated_cost' => (float) $option->estimated_cost,
                'is_recommended' => (bool) $option->is_recommended,
                'votes' => $votes,
                'percentage' => $total > 0 ? round(($votes / $total) * 100, 1) : 0.0,
                'is_winner' => false,
            ];
        })->all();

        // Mark the winner(s) - ties are allowed and both are flagged, rather than
        // arbitrarily picking one.
        $maxVotes = collect($rows)->max('votes');

        if ($total > 0 && $maxVotes > 0) {
            foreach ($rows as $index => $row) {
                $rows[$index]['is_winner'] = $row['votes'] === $maxVotes;
            }
        }

        // Most popular first: the tally is read top-down.
        usort($rows, fn ($a, $b) => $b['votes'] <=> $a['votes']);

        return $rows;
    }

    /** The single option leading the vote, or null when there is no clear leader. */
    public function leadingOption(): ?array
    {
        $tally = $this->tally();

        if ($tally === [] || ($tally[0]['votes'] ?? 0) === 0) {
            return null;
        }

        // A TIE means there is no single winner to approve automatically.
        $leaders = array_filter($tally, fn (array $row) => $row['is_winner']);

        return count($leaders) === 1 ? $tally[0] : null;
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'MENU-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
        } while (static::withoutTenantScope()->where('title', $reference)->exists());

        return $reference;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeVoting($query)
    {
        return $query->where('status', 'voting');
    }
}
