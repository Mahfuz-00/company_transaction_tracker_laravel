<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Institutional subsidy - money injected by an authority (university, company,
 * college) rather than by a member. Tracked separately from deposits so the two
 * never blur in reporting.
 */
class Subsidy extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'source',
        'source_label',
        'department_id',
        'student_id',
        'apply_mode',
        'amount',
        'period_month',
        'percentage',
        'period_start',
        'period_end',
        'recorded_by',
        'transaction_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    /** Who can inject subsidy money, per institution type. */
    public const SOURCES = [
        'university_authority' => 'University Authority',
        'company_management' => 'Company Management',
        'college_administration' => 'College Administration',
        'government_grant' => 'Government Grant',
        'donation' => 'Donation',
        'other' => 'Other',
    ];

    /** How the money is applied against balances. */
    public const APPLY_MODES = [
        // Adds straight to the common pool everyone shares.
        'pool' => 'Into the common pool',
        // Distributed evenly across active members.
        'per_member' => 'Split per active member',
        // Held as reserve; only tapped once a member's own deposit is exhausted.
        'credit_behind' => 'Reserve (applied after member funds)',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Restrict to one month (YYYY-MM). Subsidies are month-scoped by policy,
     * so reporting never has to reason about arbitrary date ranges.
     */
    public function scopeForMonth($query, ?string $month)
    {
        if (! $month || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $query;
        }

        return $query->where('period_month', $month);
    }

    /** The managed funding-source row this subsidy draws on (matched by key). */
    public function fundingSource()
    {
        return $this->belongsTo(SubsidySource::class, 'source', 'key');
    }

    /** Prefer the explicit funder name, then the managed source, then the key. */
    public function getSourceNameAttribute(): string
    {
        return $this->source_label
            ?: ($this->fundingSource?->name ?? self::SOURCES[$this->source] ?? ucfirst((string) $this->source));
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst(str_replace('_', ' ', (string) $this->source));
    }

    public function getApplyModeLabelAttribute(): string
    {
        return self::APPLY_MODES[$this->apply_mode] ?? ucfirst((string) $this->apply_mode);
    }

    /**
     * Total subsidy money available to a member, given the strict balance rule:
     * a member's own deposits are always consumed first, and only reserve
     * ("credit_behind") subsidy funds top up the remainder.
     *
     * @param  float  $ownDeposits  personal deposits the member already holds
     * @param  float  $mealCost  the member's accumulated meal cost
     */
    public static function creditAvailableFor(float $ownDeposits, float $mealCost): float
    {
        // Reserve subsidies only kick in once personal funds are exhausted.
        $shortfall = max(0, $mealCost - $ownDeposits);

        if ($shortfall <= 0) {
            return 0.0;
        }

        $reservePool = (float) static::query()
            ->active()
            ->where('apply_mode', 'credit_behind')
            ->sum('amount');

        return round(min($reservePool, $shortfall), 2);
    }
}
