<?php

namespace App\Models\Concerns;

use App\Support\TenantManager;

/**
 * Applied to every model whose rows belong to a single institution.
 *
 * It does three things, all of them automatic so a controller cannot forget:
 *
 *   1. GLOBAL SCOPE  - every query through the model is filtered to the active
 *      tenant's `institution_id` (see TenantManager). This is the hard guarantee
 *      against cross-tenant leakage: even a bare `Student::all()` inside a
 *      scoped request returns ONLY that institution's rows.
 *
 *   2. AUTO-STAMPING  - on create, a NULL `institution_id` is filled from the
 *      active tenant, so new rows can never be orphaned into "no institution".
 *
 *   3. ESCAPE HATCH    - `withoutTenantScope()` removes the filter for the rare,
 *      deliberate cross-tenant query (SSA platform dashboards). It must be an
 *      explicit, greppable call so a reviewer can see exactly where isolation is
 *      lifted.
 *
 * NULL-SAFETY
 * -----------
 * Pre-multi-tenancy rows have `institution_id = NULL`. A NULL-scope query (the
 * global/console context) still returns them, and a scoped query does NOT - so
 * legacy rows become visible to the SSA (who can then assign them) without ever
 * leaking into a specific institution's view.
 */
trait BelongsToInstitution
{
    /**
     * Boot the trait: register the global scope and the create-time stamp.
     */
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope('institution', function ($query) {
            $tenantId = app(TenantManager::class)->resolveTenantId();

            // No active tenant => global context (SSA platform view / console):
            // leave the query unfiltered so the SSA sees everything.
            if ($tenantId === null) {
                return;
            }

            $query->where($query->getModel()->getTable() . '.institution_id', $tenantId);
        });

        // Stamp the active tenant onto new rows that did not set one explicitly.
        static::creating(function ($model) {
            if ($model->getAttribute('institution_id') === null) {
                $tenantId = app(TenantManager::class)->resolveTenantId();

                if ($tenantId !== null) {
                    $model->setAttribute('institution_id', $tenantId);
                }
            }
        });
    }

    /**
     * Run a query with the tenant filter removed. Use ONLY for SSA platform-wide
     * aggregation; every other caller should stay scoped.
     */
    public static function withoutTenantScope()
    {
        return static::withoutGlobalScope('institution');
    }

    /**
     * Explicitly restrict a query to one institution. Useful in the SSA
     * monitoring screens, which iterate over many institutions in a single
     * request and must pin each query to the row's own institution.
     */
    public static function forInstitution($query, ?int $institutionId)
    {
        return $query->withoutTenantScope()
            ->where('institution_id', $institutionId);
    }
}
