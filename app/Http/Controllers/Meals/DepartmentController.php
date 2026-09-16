<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $departments = Department::query()
            ->withCount([
                'students',
                'students as active_students_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('departments', 'slug')],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department = Department::create($data);

        return redirect()
            ->route('meals.departments.index')
            ->with('success', "Department \"{$department->name}\" created.");
    }

    public function edit(Department $department)
    {
        return redirect()->route('meals.departments.index');
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('departments', 'slug')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department->update($data);

        return redirect()
            ->route('meals.departments.index')
            ->with('success', "Department \"{$department->name}\" updated.");
    }

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
