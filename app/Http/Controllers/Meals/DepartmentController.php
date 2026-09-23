<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Meal-department ("group") management.
 *
 * WHAT THIS IS
 * ------------
 * A department groups members (e.g. "Computer Science") for reporting and bulk
 * operations such as rate assignment. Institutions rename this concept through
 * the terminology setting ("department", "group", "class", ...), but the code and
 * the table stay exactly the same.
 *
 * MULTI-TENANCY
 * -------------
 * `Department` uses `BelongsToInstitution`: reads are filtered to the active
 * institution by the global scope, and new rows inherit its id. Note that the
 * UNIQUE validation rules below must add `where('institution_id', ...)` BY HAND -
 * `Rule::unique()` issues a direct table query that bypasses Eloquent's global
 * scope, so a bare unique rule would wrongly stop two institutions from each
 * having a "Kitchen".
 *
 * ROUTE-MODEL BINDING & SLUGS
 * ---------------------------
 * `Department::getRouteKeyName()` returns `slug`, so a parameter of
 * `Department $department` resolves from the URL slug (not an id). Because
 * binding runs through the tenant scope, a slug from another institution 404s.
 */
class DepartmentController extends Controller
{
    /**
     * Paginated, searchable list of departments.
     *
     * `withCount` adds two counters in ONE query: the total members and, via a
     * constrained alias, just the active ones - so the list renders both numbers
     * without loading every student row. `withQueryString()` keeps the current
     * filters attached to the pagination links.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $departments = Department::query()
            ->withCount([
                'students',
                'students as active_students_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Meals/Departments/Index', [
            'departments' => $departments,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Creation happens in a modal on the index screen, so these dedicated
     * views just send the user back to where the work actually happens.
     */
    public function create()
    {
        return redirect()->route('meals.departments.index');
    }

    /**
     * Create a department.
     *
     * The name/slug uniqueness rules are scoped to the current institution (see
     * the class note on why a bare `Rule::unique` is wrong here). `institution_id`
     * is stamped from `Institution::current()` SERVER-SIDE, never taken from the
     * request.
     */
    public function store(Request $request)
    {
        $institution = Institution::current();

        /*
         * Names/slugs must be unique WITHIN an institution, not across the whole
         * platform. A plain Rule::unique() checks every row in the table (it does
         * not run through the model's tenant scope), so two institutions could
         * not both have a "Kitchen" department. Scoping the rule with
         * where('institution_id', ...) restores per-tenant uniqueness.
         */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where('institution_id', $institution?->id)],
            'slug' => ['nullable', 'string', 'max:255',
                Rule::unique('departments', 'slug')->where('institution_id', $institution?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department = Department::create($data + ['institution_id' => $institution?->id]);

        return redirect()
            ->route('meals.departments.index')
            ->with('success', "Department \"{$department->name}\" created.");
    }

    public function edit(Department $department)
    {
        return redirect()->route('meals.departments.index');
    }

    /**
     * Update a department.
     *
     * `$department` is resolved by ROUTE-MODEL BINDING through the tenant scope,
     * so a cross-institution row can never reach this method. The unique rules are
     * scoped to the department's OWN institution and ignore the row itself, so
     * renaming a department (or saving it unchanged) does not trip its own
     * uniqueness check.
     */
    public function update(Request $request, Department $department)
    {
        // Route-model binding already ran through the tenant scope, so a
        // cross-institution department cannot reach here. Uniqueness is checked
        // within the department's own institution.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where('institution_id', $department->institution_id)->ignore($department->id)],
            'slug' => ['nullable', 'string', 'max:255',
                Rule::unique('departments', 'slug')->where('institution_id', $department->institution_id)->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department->update($data);

        return redirect()
            ->route('meals.departments.index')
            ->with('success', "Department \"{$department->name}\" updated.");
    }

    /**
     * Delete a department - unless members are still assigned to it.
     *
     * Members are the source of truth for balances, so deleting a department out
     * from under them would orphan meal history. The guard refuses the delete and
     * tells the user how many members must be reassigned first, rather than
     * silently cascading.
     */
    public function destroy(Department $department)
    {
        // Students are the source of truth for balances; deleting a department
        // out from under them would silently orphan meal history.
        if ($department->students()->exists()) {
            return back()->with(
                'error',
                "Cannot delete \"{$department->name}\" - it still has {$department->students()->count()} student(s). Reassign them first."
            );
        }

        $name = $department->name;
        $department->delete();

        return redirect()
            ->route('meals.departments.index')
            ->with('success', "Department \"{$name}\" deleted.");
    }
}
