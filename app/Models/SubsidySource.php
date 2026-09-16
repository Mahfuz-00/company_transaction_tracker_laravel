<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A funding source an institution can attribute subsidy money to.
 *
 * Sources differ per institution (a university has a "University Authority",
 * a company has "Company Management", a college has grants), so they are
 * admin-managed rows rather than a hard-coded list. Each carries a default
 * percentage: the share of the funding pool it is expected to carry.
 */
class SubsidySource extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'key',
        'percentage',
        'description',
        'is_active',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function subsidies()
    {
        return $this->hasMany(Subsidy::class, 'source', 'key');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The built-in sources seeded for every institution on first boot. Kept
     * here so the seeder and the "restore defaults" action agree.
     */
    public const DEFAULTS = [
        ['name' => 'University Authority', 'key' => 'university_authority', 'percentage' => 50],
        ['name' => 'Company Management', 'key' => 'company_management', 'percentage' => 50],
        ['name' => 'College Administration', 'key' => 'college_administration', 'percentage' => 50],
        ['name' => 'Government Grant', 'key' => 'government_grant', 'percentage' => 20],
        ['name' => 'Donation', 'key' => 'donation', 'percentage' => 10],
        ['name' => 'Other', 'key' => 'other', 'percentage' => 0],
    ];

    /** Ensure the active institution has the default sources. */
    public static function ensureDefaults(?int $institutionId): void
    {
        foreach (self::DEFAULTS as $source) {
            static::firstOrCreate(
                ['institution_id' => $institutionId, 'name' => $source['name']],
                [
                    'key' => $source['key'],
                    'percentage' => $source['percentage'],
                    'is_active' => true,
                ]
            );
        }
    }
}
