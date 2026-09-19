<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A SaaS pricing tier defined by the Software Super Admin.
 *
 * Platform-level (NOT institution-scoped), so it does not use the tenant trait.
 * Institutions reference a plan via `institutions.subscription_plan` (the plan's
 * `key`) and carry the numeric `subscription_amount` for revenue reporting.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'monthly_price',
        'is_free',
        'is_trial_default',
        'member_limit',
        'manager_limit',
        'features',
        'sort_order',
        'is_active',
        'is_public',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'is_free' => 'boolean',
        'is_trial_default' => 'boolean',
        'member_limit' => 'integer',
        'manager_limit' => 'integer',
        'features' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
    ];

    /**
     * Institutions currently assigned to this plan (by key).
     *
     * NOTE: Institution is NOT tenant-scoped (it is the tenant itself), so a
     * plain query is already global - withoutTenantScope() does not exist here.
     */
    public function institutions()
    {
        return Institution::query()->where('subscription_plan', $this->key);
    }

    /** Count of institutions on this plan. */
    public function institutionCount(): int
    {
        return (int) Institution::query()
            ->where('subscription_plan', $this->key)
            ->count();
    }

    /** Monthly revenue this plan currently generates. */
    public function mrr(): float
    {
        return (float) Institution::query()
            ->where('subscription_plan', $this->key)
            ->where('subscription_status', 'paid')
            ->sum('subscription_amount');
    }

    /** The agents the landing page shows (active + public). */
    public static function publicPlans()
    {
        return static::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** "Unlimited" or the numeric cap, for display. */
    public function memberLimitLabel(): string
    {
        return $this->member_limit === -1 ? 'Unlimited' : (string) $this->member_limit;
    }

    public function managerLimitLabel(): string
    {
        return $this->manager_limit === -1 ? 'Unlimited' : (string) $this->manager_limit;
    }
}
