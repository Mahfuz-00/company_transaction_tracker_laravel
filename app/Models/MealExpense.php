<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealExpense extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'description', 'category', 'recorded_by'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
