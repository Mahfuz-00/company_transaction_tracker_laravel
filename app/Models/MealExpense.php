<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'vendor_id',
        'description',
        'category',
        'amount',
        'payment_status',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
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
