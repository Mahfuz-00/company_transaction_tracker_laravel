<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'amount', 'kind', 'subsidy_id',
        'payment_method', 'recorded_by', 'transaction_id', 'notes',
    ];

    /** personal | subsidy | credit */
    public const KINDS = [
        'personal' => 'Personal deposit',
        'subsidy' => 'Institutional subsidy',
        'credit' => 'Credit adjustment',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subsidy()
    {
        return $this->belongsTo(Subsidy::class);
    }

    /**
     * Only genuine personal deposits count toward a member's own funds. Subsidy
     * money is tracked separately so balance rules stay strict.
     */
    public function scopePersonal($query)
    {
        return $query->where('kind', 'personal');
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
