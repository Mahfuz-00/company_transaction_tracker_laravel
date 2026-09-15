<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:students.manage')->except(['index', 'show']);
        $this->middleware('permission:students.view')->only(['index', 'show']);
    }

    public function index()
    {
        $students = Student::with('department')->orderBy('name')->paginate(20);
        return Inertia::render('Meals/Students/Index', ['students' => $students]);
    }

    public function create()
    {
        $departments = Department::orderBy('name')->get();
        return Inertia::render('Meals/Students/Create', ['departments' => $departments]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'name' => 'required|string|max:255',
            'roll' => 'nullable|string|max:100',
            'department_id' => 'nullable|exists:departments,id',
            'join_date' => 'nullable|date',
            'status' => 'nullable|in:active,inactive',
        ]);

        Student::create($data);

        return redirect()->route('meals.students.index')->with('success', 'Student added.');
    }

    public function edit(Student $student)
    {
        $departments = Department::orderBy('name')->get();
        return Inertia::render('Meals/Students/Edit', ['student' => $student, 'departments' => $departments]);
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'name' => 'required|string|max:255',
            'roll' => 'nullable|string|max:100',
            'department_id' => 'nullable|exists:departments,id',
            'join_date' => 'nullable|date',
            'status' => 'nullable|in:active,inactive',
        ]);

        $student->update($data);

        return redirect()->route('meals.students.index')->with('success', 'Student updated.');
    }

    public function destroy(Student $student)
    {
        $student->delete();
        return redirect()->route('meals.students.index')->with('success', 'Student removed.');
    }
}
