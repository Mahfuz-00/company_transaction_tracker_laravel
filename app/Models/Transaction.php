<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'vendor_id',
        'item',
        'type',
        'amount',
        'category',
        'payment_method',
        'by_whom',
        'payee',
        'reason',
        'source',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The student who paid this in (Cash In / deposit rows only).
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Set when this Cash Out row was created by the meal expense module.
     */
    public function mealExpense()
    {
        return $this->hasOne(MealExpense::class);
    }

    /**
     * The supplier this money was paid to (Cash Out rows).
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deposit()
    {
        return $this->hasOne(Deposit::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /** Money in - student contributions to the pool. */
    public function scopeDeposits($query)
    {
        return $query->where('type', 'in');
    }

    /** Money out - meal manager spending. */
    public function scopeExpenses($query)
    {
        return $query->where('type', 'out');
    }

    /** Rows tied to a specific student rather than a generic entry. */
    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeInRange($query, $from, $to)
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
    }

    /** Money paid to a specific supplier. */
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    public function isDeposit(): bool
    {
        return $this->type === 'in';
    }

    public function isExpense(): bool
    {
        return $this->type === 'out';
    }

    /** Signed value: +in, -out. Used for running balances. */
    public function signedAmount(): float
    {
        return $this->isDeposit()
            ? (float) $this->amount
            : -1 * (float) $this->amount;
    }
}
