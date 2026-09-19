<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
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
    use BelongsToInstitution, HasFactory;

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
            // Without scoping, firstOrCreate could match a row belonging to a
            // DIFFERENT institution that happens to share the default name.
            // The explicit scope keeps the create/lookup pinned to $institutionId.
            static::withoutTenantScope()->firstOrCreate(
                ['institution_id' => $institutionId, 'name' => $source['name']],
                [
                    'key' => $source['key'],
                    'percentage' => $source['percentage'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Subsidy sources are SLIGHTLY special: the platform ships a set of global
     * default sources with a NULL institution_id, and each institution may add
     * its own. A scoped query must therefore see "mine OR the shared defaults"
     * rather than ONLY its own rows.
     *
     * We override the trait's blanket scope for this model to express exactly
     * that: no cross-institution leakage (another institution's custom sources
     * stay hidden), while the shared, institution-less defaults remain visible
     * to everyone.
     */
    protected static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope('institution', function ($query) {
            $tenantId = app(\App\Support\TenantManager::class)->resolveTenantId();

            // Global/SSA context: no filter at all.
            if ($tenantId === null) {
                return;
            }

            $column = $query->getModel()->getTable().'.institution_id';

            $query->where(function ($sub) use ($column, $tenantId) {
                $sub->where($column, $tenantId)->orWhereNull($column);
            });
        });
    }
}
