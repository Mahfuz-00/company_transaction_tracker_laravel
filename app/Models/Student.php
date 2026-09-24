<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'manager_id',
        'name',
        'roll',
        'department_id',
        'join_date',
        'status',
    ];

    protected $casts = [
        'join_date' => 'date',
    ];

    /**
     * Append the computed figures so the frontend can render balances
     * without issuing its own requests.
     */
    protected $appends = ['total_meals', 'total_deposits', 'balance'];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Meal Manager / Admin who OVERSEES this member record.
     *
     * This is deliberately distinct from `user()` (the member's OWN login, once
     * invited). `manager_id` must always point at a staff account, never at the
     * member's own user account - that confusion was the "member is managed by
     * themselves" bug.
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Is this member overseen by the given user (as their manager)? */
    public function isManagedBy(?User $user): bool
    {
        return $user !== null
            && $this->manager_id !== null
            && (int) $this->manager_id === (int) $user->id;
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The name of the person who OVERSEES this member.
     *
     * Falls back to the institution admin only when no explicit manager is set -
     * it must NOT fall back to the member's own user account, otherwise the
     * roster would show the member as being managed by themselves.
     */
    public function getManagerLabelAttribute(): ?string
    {
        return $this->manager?->name;
    }

    protected static function booted(): void
    {
        /*
         * DUAL-ROLE SUPPORT.
         *
         * A member is a `students` row, but the member AREA is gated by the
         * `role:Member` route middleware. When a roster record is linked to a
         * login that already holds a staff role (Institution Admin / Meal
         * Manager), that staff member never had the Member role - so their own
         * member area 403'd. Linking a member record to a user now guarantees
         * they hold the Member role TOO, without stripping their staff roles.
         * The `hasRole` guard keeps this a no-op on every subsequent save.
         */
        static::saved(function (Student $student) {
            try {
                $user = $student->user;

                if ($user && ! $user->hasRole('Member')) {
                    $user->assignInstitutionRole('Member');
                }
            } catch (\Throwable $e) {
                // Never let a role-table problem break saving a member record
                // (e.g. a seeder running before the RBAC roles exist).
                report($e);
            }
        });
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    /** Balance refunds paid back to this member (the mirror of deposits). */
    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function entries()
    {
        return $this->hasMany(MealEntry::class);
    }

    /** Claims and disputes this member has raised. */
    public function claims()
    {
        return $this->hasMany(Claim::class);
    }

    /* ------------------------------------------------------------------ *
     * Accessors
     * ------------------------------------------------------------------ */

    /**
     * Sum the meal components. Uses the loaded relation when available so
     * listing N students does not fire N extra queries.
     */
    public function getTotalMealsAttribute(): int
    {
        if (! $this->relationLoaded('entries')) {
            return (int) $this->entries()->sum('total_meals');
        }

        return (int) $this->entries->sum(
            fn ($entry) => ($entry->breakfast ?? 0) + ($entry->lunch ?? 0) + ($entry->dinner ?? 0)
        );
    }

    public function getTotalDepositsAttribute(): float
    {
        // Reversed deposits are kept for history but must never count toward a
        // member's balance, so both branches filter them out.
        if (! $this->relationLoaded('deposits')) {
            return (float) $this->deposits()->whereNull('reversed_at')->sum('amount');
        }

        return (float) $this->deposits
            ->whereNull('reversed_at')
            ->sum('amount');
    }

    /**
     * Total refunds paid back OUT of this member's wallet. Reversed refunds are
     * excluded, exactly like reversed deposits - a corrected refund stops
     * reducing the balance.
     */
    public function getTotalRefundsAttribute(): float
    {
        if (! $this->relationLoaded('refunds')) {
            return (float) $this->refunds()->whereNull('reversed_at')->sum('amount');
        }

        return (float) $this->refunds
            ->whereNull('reversed_at')
            ->sum('amount');
    }

    /**
     * Money remaining for this student: deposits, MINUS anything refunded, minus
     * the cost of the meals they ate, priced at the given per-meal rate.
     * Positive = credit left, negative = amount owed.
     */
    public function balance(float $costPerMeal = 0): float
    {
        return round(
            $this->total_deposits - $this->total_refunds - ($this->total_meals * $costPerMeal),
            2
        );
    }

    /**
     * Subsidy-aware balance applying the strict rule: a member's own deposits
     * (net of refunds) are always spent first, and reserve subsidy credit only
     * covers what is left over. Returns the effective balance plus the subsidy
     * portion used.
     */
    public function balanceWithSubsidy(float $costPerMeal = 0): array
    {
        $mealCost = $this->total_meals * $costPerMeal;
        $own = round($this->total_deposits - $this->total_refunds, 2);
        $raw = round($own - $mealCost, 2);

        // Reserve subsidy tops up a shortfall only.
        $subsidyCredit = $raw < 0
            ? Subsidy::creditAvailableFor($own, $mealCost)
            : 0.0;

        return [
            'own_deposits' => round($own, 2),
            'meal_cost' => round($mealCost, 2),
            'subsidy_credit' => $subsidyCredit,
            'balance' => round($raw + $subsidyCredit, 2),
        ];
    }

    public function getBalanceAttribute(): float
    {
        // Attribute form costs nothing (rate 0) - it exists so the appended
        // payload always has a value. Use balance($rate) for real figures.
        return $this->balance(0.0);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Eager-load everything the list/detail screens need in one go.
     */
    public function scopeWithStats($query)
    {
        return $query->with(['department:id,name,slug', 'user:id,name,email', 'deposits:id,student_id,amount', 'refunds:id,student_id,amount,reversed_at', 'entries:id,student_id,breakfast,lunch,dinner']);
    }
}
