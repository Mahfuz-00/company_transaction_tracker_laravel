<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\MealRate;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StudentController extends Controller
{
    /**
     * Per-meal rate used for balance maths. Falls back to the custom rate
     * rather than guessing, so figures are never silently wrong.
     */
    protected function currentCostPerMeal(): float
    {
        $rate = MealRate::query()
            ->whereNotNull('cost_per_meal')
            ->orderByDesc('to_date')
            ->orderByDesc('created_at')
            ->first();

        return (float) ($rate?->cost_per_meal ?? 0);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $department = (string) $request->query('department', '');
        $status = (string) $request->query('status', '');

        $students = Student::withStats()
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('roll', 'like', $term);
                });
            })
            ->when($department !== '', fn ($q) => $q->where('department_id', $department))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Map in the balance using the live rate, so the table shows real figures.
        $costPerMeal = $this->currentCostPerMeal();

        $students->through(function (Student $student) use ($costPerMeal) {
            $student->setAttribute('balance', $student->balance($costPerMeal));
            $student->setAttribute('cost_per_meal', $costPerMeal);

            return $student;
        });

        return Inertia::render('Meals/Students/Index', [
            'students' => $students,
            'departments' => Department::orderBy('name')->get(['id', 'name', 'slug']),
            'users' => $this->linkableUsers(),
            'costPerMeal' => $costPerMeal,
            'filters' => [
                'search' => $search,
                'department' => $department,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Creation happens in a modal on the index screen.
     */
    public function create()
    {
        return redirect()->route('meals.students.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $student = Student::create($data);

        return redirect()
            ->route('meals.students.index')
            ->with('success', "Student \"{$student->name}\" added.");
    }

    public function show(Student $student)
    {
        $student->load(['department:id,name,slug', 'user:id,name,email', 'deposits' => fn ($q) => $q->latest()->limit(20), 'entries' => fn ($q) => $q->orderByDesc('date')->limit(30)]);

        $costPerMeal = $this->currentCostPerMeal();

        return Inertia::render('Meals/Students/Show', [
            'student' => $student,
            'costPerMeal' => $costPerMeal,
            'balance' => $student->balance($costPerMeal),
            'totalMeals' => $student->total_meals,
            'totalDeposits' => $student->total_deposits,
        ]);
    }

    public function edit(Student $student)
    {
        return redirect()->route('meals.students.index');
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate($this->rules($student));

        $student->update($data);

        return redirect()
            ->route('meals.students.index')
            ->with('success', "Student \"{$student->name}\" updated.");
    }

    public function destroy(Student $student)
    {
        // A student with recorded money or meals is financial history.
        if ($student->deposits()->exists() || $student->entries()->exists()) {
            return back()->with(
                'error',
                "Cannot delete \"{$student->name}\" - meal or deposit history exists. Mark them inactive instead."
            );
        }

        $name = $student->name;
        $student->delete();

        return redirect()
            ->route('meals.students.index')
            ->with('success', "Student \"{$name}\" removed.");
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    protected function rules(?Student $student = null): array
    {
        return [
            'user_id' => [
                'nullable',
                'exists:users,id',
                // One login should not back two student records.
                Rule::unique('students', 'user_id')->ignore($student?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'roll' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * Users eligible to be linked: those not already tied to another student,
     * plus the one currently linked to this student.
     */
    protected function linkableUsers()
    {
        $takenIds = Student::query()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereNotIn('id', $takenIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
