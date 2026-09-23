<?php

namespace App\Http\Requests\Api;

use App\Support\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base class for every mobile-API Form Request.
 *
 * WHAT IT IS
 * ----------
 * A Form Request is Laravel's way of moving validation OUT of the controller
 * into its own class. Coming from mobile development, think of it as the typed
 * request/DTO plus its validator: instead of the controller reading raw input
 * and hand-checking it, the framework validates first and hands the controller
 * a clean, already-validated array (`$request->validated()`). A failed check
 * throws before the controller body ever runs, so no half-created rows.
 *
 * WHY IT EXISTS HERE (the multi-tenancy security fix)
 * --------------------------------------------------
 * The original API controllers used plain rules like:
 *
 *     'student_id' => ['required', 'exists:students,id']
 *
 * `exists:students,id` checks the WHOLE table - every institution's rows. A
 * caller in Institution A could therefore pass a student_id belonging to
 * Institution B. Depending on the endpoint that either leaked a cross-tenant
 * 404, or (in the meals day-grid) wrote a row pointing at a foreign member.
 *
 * `tenantExists()` closes that hole: it scopes the `exists` rule to the ACTIVE
 * tenant's `institution_id`, so a foreign id is rejected as a normal 422
 * validation error instead of ever reaching the database.
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * The mobile API has no interactive form, so there is nothing to authorise
     * here - the surrounding `auth:sanctum` middleware is what gates access.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The institution id the current request is acting inside, or null for the
     * deliberate global (Software Super Admin) context.
     */
    protected function tenantId(): ?int
    {
        return app(TenantManager::class)->resolveTenantId();
    }

    /**
     * An `exists` rule restricted to the active tenant's rows.
     *
     * When a tenant is active the reference is only valid if the target row
     * belongs to the SAME institution - the tenant-isolation guarantee applied
     * at the validation layer, before any write. In the global (SSA) context
     * there is no scope to apply, so the rule stays table-wide on purpose.
     */
    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        $tenantId = $this->tenantId();

        if ($tenantId !== null) {
            $rule->where('institution_id', $tenantId);
        }

        return $rule;
    }
}
