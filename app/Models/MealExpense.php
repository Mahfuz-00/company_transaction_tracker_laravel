<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealExpense extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'transaction_id',
        'vendor_id',
        'description',
        'category',
        'amount',
        'payment_status',
        'recorded_by',
        'reversed_at',
        'reversed_by',
        'reversal_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** A reversed expense no longer counts toward any total. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /** The administrator who reversed this expense. */
    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
