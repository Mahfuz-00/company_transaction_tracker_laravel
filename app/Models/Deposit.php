<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id', 'student_id', 'amount', 'kind', 'subsidy_id',
        'payment_method', 'recorded_by', 'transaction_id', 'notes',
        'reversed_at', 'reversed_by', 'reversal_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** personal | subsidy | credit */
    public const KINDS = [
        'personal' => 'Personal deposit',
        'subsidy' => 'Institutional subsidy',
        'credit' => 'Credit adjustment',
    ];

    /** A reversed deposit no longer counts toward any balance. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Only deposits that have not been reversed. */
    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function scopeReversed($query)
    {
        return $query->whereNotNull('reversed_at');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** The administrator who reversed this deposit. */
    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
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
