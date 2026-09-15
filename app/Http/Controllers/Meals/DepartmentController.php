<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DepartmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:departments.manage');
    }

    public function index()
    {
        $departments = Department::orderBy('name')->paginate(20);
        return Inertia::render('Meals/Departments/Index', ['departments' => $departments]);
    }

    public function create()
    {
        return Inertia::render('Meals/Departments/Create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:departments,name',
            'slug' => 'nullable|string|max:255|unique:departments,slug',
            'description' => 'nullable|string',
        ]);

        Department::create($data);

        return redirect()->route('meals.departments.index')->with('success', 'Department created.');
    }

    public function edit(Department $department)
    {
        return Inertia::render('Meals/Departments/Edit', ['department' => $department]);
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:departments,name,' . $department->id,
            'slug' => 'nullable|string|max:255|unique:departments,slug,' . $department->id,
            'description' => 'nullable|string',
        ]);

        $department->update($data);

        return redirect()->route('meals.departments.index')->with('success', 'Department updated.');
    }

    public function destroy(Department $department)
    {
        $department->delete();
        return redirect()->route('meals.departments.index')->with('success', 'Department deleted.');
    }
}
