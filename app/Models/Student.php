<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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

    public function department()
    {
        return $this->belongsTo(Department::class);
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
