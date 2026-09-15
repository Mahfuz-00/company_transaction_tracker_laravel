<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealRate extends Model
{
    use HasFactory;

    protected $fillable = ['from_date', 'to_date', 'total_cost', 'cost_per_meal', 'created_by'];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'total_cost' => 'decimal:2',
        'cost_per_meal' => 'decimal:4',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
