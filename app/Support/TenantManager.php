<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The single source of truth for "which tenant is the current request acting
 * inside of, and is it allowed to see across tenants?".
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this class, controllers each resolved the active institution with
 * `Institution::current()` and then (sometimes) remembered to add a
 * `where('institution_id', ...)` clause. Any controller that forgot the clause -
 * or any query built through a model relationship - happily returned rows from
 * EVERY institution. That is exactly the cross-tenant leak: expenses, members
 * and deposits saved in "Touch and Solve Ltd." showed up in "North South
 * University".
 *
 * The fix is to make scoping the DEFAULT, not an opt-in. Every tenant-owned
 * model uses the `BelongsToInstitution` trait, whose global scope asks THIS
 * manager for the id to enforce. A controller therefore cannot forget to scope:
 * the moment a query runs, the active tenant's id is added automatically.
 *
 * THREE CONTEXTS
 * --------------
 *   - resolveTenantId()  null  -> GLOBAL context (Software Super Admin on the
 *                                 platform view, or console/queue work with no
 *                                 user). The global scope adds no clause, so the
 *                                 SSA sees everything - which is its whole job.
 *   - resolveTenantId()  int   -> SCOPED context. Every tenant-owned query is
 *                                 filtered to that institution id. This is the
 *                                 normal case for Institution Admins / Meal
 *                                 Managers / Members AND for an SSA who has
 *                                 switched into one institution via the registry.
 *
 * The SSA "global oversight" requirement is satisfied without ever weakening a
 * bound user: an Institution Admin / Meal Manager / Member can NEVER produce a
 * null (global) context, because resolveTenantId() is derived from their own
 * `institution_id`, which the ResolveTenant middleware has already validated.
 */
class TenantManager
{
    /**
     * A forced tenant id for the current request. When set, it wins over
     * everything else - used by the middleware after it validates the session
     * tenant, and by background jobs that must act as a specific institution.
     */
    protected ?int $forcedTenantId = null;

    /** True when the forced id represents a deliberate "no scoping" state. */
    protected bool $globalMode = false;

    /**
     * The institution id every tenant-owned query must be filtered to, or null
     * for the global (platform) context.
     */
    public function resolveTenantId(): ?int
    {
        // An explicitly forced tenant ALWAYS wins - it is how a service pins its
        // own queries (e.g. FinanceCalculator for one institution) even while the
        // request as a whole runs in a global, cross-tenant context.
        if ($this->forcedTenantId !== null) {
            return $this->forcedTenantId;
        }

        if ($this->globalMode) {
            return null;
        }

        $user = Auth::user();

        // A Software Super Admin with no session tenant is in the GLOBAL view.
        if ($user instanceof User && $user->isSuperAdmin()) {
            return Institution::sessionTenantId();
        }

        // Everyone else is permanently bound to their own institution.
        if ($user instanceof User && $user->institution_id) {
            return (int) $user->institution_id;
        }

        // Console / queue / guest: fall back to the resolved current institution
        // so seeders and importers behave predictably.
        return Institution::current()?->id;
    }

    /**
     * Is this request running in the global (cross-tenant) context? Only ever
     * true for a Software Super Admin who has NOT switched into an institution.
     */
    public function isGlobal(): bool
    {
        return $this->resolveTenantId() === null;
    }

    /**
     * Force the active tenant for this request/instance. Passing null puts the
     * manager in explicit global mode (SSA platform view).
     */
    public function force(?int $tenantId): static
    {
        $this->forcedTenantId = $tenantId;

        return $this;
    }

    /**
     * Enter the deliberate, cross-tenant global context (SSA platform view).
     * Intended for the registry / monitoring screens only.
     */
    public function withoutScope(): static
    {
        $this->globalMode = true;

        return $this;
    }

    /**
     * Run a callback with scoping disabled, then restore the previous state.
     * Used by SSA-only aggregate screens that must roll up EVERY institution.
     */
    public function runGlobally(callable $callback): mixed
    {
        $previousGlobal = $this->globalMode;
        $previousForced = $this->forcedTenantId;

        $this->globalMode = true;
        $this->forcedTenantId = null;

        try {
            return $callback();
        } finally {
            $this->globalMode = $previousGlobal;
            $this->forcedTenantId = $previousForced;
        }
    }

    /**
     * Run a callback pinned to one tenant, then restore the previous state.
     *
     * This is the correct way for a service to compute one institution's figures
     * inside a request that is otherwise global (e.g. the SSA registry iterating
     * several institutions) WITHOUT leaking that pin into the surrounding query
     * stream. The previous context is always restored, even on exception.
     */
    public function withTenant(?int $tenantId, callable $callback): mixed
    {
        $previousGlobal = $this->globalMode;
        $previousForced = $this->forcedTenantId;

        $this->globalMode = false;
        $this->forcedTenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->globalMode = $previousGlobal;
            $this->forcedTenantId = $previousForced;
        }
    }

    /**
     * May the given user reach the given institution's data? SSA always may;
     * everyone else only their own. Used by controllers for explicit,
     * defence-in-depth checks on top of the automatic query scope.
     */
    public function canAccess(?User $user, ?int $institutionId): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $institutionId !== null
            && $user->institution_id !== null
            && (int) $user->institution_id === (int) $institutionId;
    }
}
