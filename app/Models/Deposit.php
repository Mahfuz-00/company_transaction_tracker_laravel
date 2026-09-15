<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'amount', 'payment_method', 'recorded_by', 'transaction_id', 'notes'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
