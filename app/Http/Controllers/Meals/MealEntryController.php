<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\MealEntry;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MealEntryController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        $studentId = (string) $request->query('student', '');

        $entries = MealEntry::query()
            ->with(['student:id,name,roll', 'recorder:id,name'])
            ->when($date, fn ($q) => $q->whereDate('date', $date))
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->orderByDesc('date')
            ->orderBy('student_id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Meals/Entries/Index', [
            'entries' => $entries,
            'students' => Student::active()->orderBy('name')->get(['id', 'name', 'roll']),
            'filters' => [
                'date' => $date,
                'student' => $studentId,
            ],
            'dayTotals' => $this->dayTotals($date),
        ]);
    }

    /**
     * The daily grid: every active student for one day, pre-filled with
     * whatever has already been recorded. This is how a manager actually
     * works - one pass after each meal rather than 30 separate forms.
     */
    public function create(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        $existing = MealEntry::query()
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        $students = Student::active()
            ->orderBy('name')
            ->get(['id', 'name', 'roll'])
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'roll' => $student->roll,
                'breakfast' => (int) ($existing->get($student->id)->breakfast ?? 0),
                'lunch' => (int) ($existing->get($student->id)->lunch ?? 0),
                'dinner' => (int) ($existing->get($student->id)->dinner ?? 0),
            ]);

        return Inertia::render('Meals/Entries/Create', [
            'date' => $date,
            'students' => $students,
        ]);
    }

    /**
     * Accepts a whole day's grid in one request.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'entries' => ['required', 'array'],
            'entries.*.student_id' => ['required', 'exists:students,id'],
            'entries.*.breakfast' => ['nullable', 'integer', 'min:0', 'max:10'],
            'entries.*.lunch' => ['nullable', 'integer', 'min:0', 'max:10'],
            'entries.*.dinner' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $saved = 0;

        DB::transaction(function () use ($data, &$saved) {
            foreach ($data['entries'] as $row) {
                $breakfast = (int) ($row['breakfast'] ?? 0);
                $lunch = (int) ($row['lunch'] ?? 0);
                $dinner = (int) ($row['dinner'] ?? 0);

                // Skip untouched rows entirely rather than writing zero-rows
                // for students who ate nothing - keeps the table meaningful.
                if ($breakfast === 0 && $lunch === 0 && $dinner === 0) {
                    MealEntry::where('student_id', $row['student_id'])
                        ->whereDate('date', $data['date'])
                        ->delete();

                    continue;
                }

                // Look up by DATE, not by an exact datetime match. The column
                // stores "2026-09-16 00:00:00" while the request sends
                // "2026-09-16", so an updateOrCreate() on `date` never matched
                // and every save tripped the unique(student_id, date) index.
                $entry = MealEntry::query()
                    ->where('student_id', $row['student_id'])
                    ->whereDate('date', $data['date'])
                    ->first();

                $attributes = [
                    'breakfast' => $breakfast,
                    'lunch' => $lunch,
                    'dinner' => $dinner,
                    'recorded_by' => auth()->id(),
                ];

                if ($entry) {
                    $entry->update($attributes);
                } else {
                    MealEntry::create($attributes + [
                        'student_id' => $row['student_id'],
                        'date' => $data['date'],
                    ]);
                }

                $saved++;
            }
        });

        return redirect()
            ->route('meals.entries.index', ['date' => $data['date']])
            ->with('success', "Meal entries saved for {$saved} student(s).");
    }

    /**
     * Meal tallies for a single day.
     */
    protected function dayTotals(string $date): array
    {
        $row = MealEntry::query()
            ->whereDate('date', $date)
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->first();

        $breakfast = (int) ($row->breakfast ?? 0);
        $lunch = (int) ($row->lunch ?? 0);
        $dinner = (int) ($row->dinner ?? 0);

        return [
            'breakfast' => $breakfast,
            'lunch' => $lunch,
            'dinner' => $dinner,
            'total' => $breakfast + $lunch + $dinner,
        ];
    }
}
