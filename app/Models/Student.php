<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
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
     * The user or meal manager responsible for maintaining this member record.
     * Distinct from user_id (the member's own login, once invited).
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
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
     * The person who manages this record: the explicit manager, else the linked
     * user account, else nothing.
     */
    public function getManagerLabelAttribute(): ?string
    {
        return $this->manager?->name ?? $this->user?->name;
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function entries()
    {
        return $this->hasMany(MealEntry::class);
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
        if (! $this->relationLoaded('deposits')) {
            return (float) $this->deposits()->sum('amount');
        }

        return (float) $this->deposits->sum('amount');
    }

    /**
     * Money remaining for this student: deposits minus the cost of the
     * meals they ate, priced at the given per-meal rate.
     * Positive = credit left, negative = amount owed.
     */
    public function balance(float $costPerMeal = 0): float
    {
        return round($this->total_deposits - ($this->total_meals * $costPerMeal), 2);
    }

    /**
     * Subsidy-aware balance applying the strict rule: a member's own deposits
     * are always spent first, and reserve subsidy credit only covers what is
     * left over. Returns the effective balance plus the subsidy portion used.
     */
    public function balanceWithSubsidy(float $costPerMeal = 0): array
    {
        $mealCost = $this->total_meals * $costPerMeal;
        $own = $this->total_deposits;
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
        return $query->with(['department:id,name,slug', 'user:id,name,email', 'deposits:id,student_id,amount', 'entries:id,student_id,breakfast,lunch,dinner']);
    }
}
