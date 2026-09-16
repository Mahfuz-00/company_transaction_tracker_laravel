<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealEntry;
use App\Models\Student;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meal entries API. Exposes the same day-grid the web UI uses, so a mobile
 * client can fill in a whole day in one request.
 */
class MealApiController extends Controller
{
    /** Historical entries for a month. */
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        [$start, $end] = FinanceCalculator::monthBounds($month);

        $entries = MealEntry::query()
            ->with(['student:id,name,roll'])
            ->when($start, fn ($q) => $q->whereDate('date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('date', '<=', $end->toDateString()))
            ->when($request->filled('member'), fn ($q) => $q->where('student_id', $request->query('member')))
            ->orderByDesc('date')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        $entries->through(fn (MealEntry $e) => [
            'id' => $e->id,
            'member' => $e->student?->name,
            'member_id' => $e->student_id,
            'roll' => $e->student?->roll,
            'date' => $e->date?->toDateString(),
            'breakfast' => $e->breakfast,
            'lunch' => $e->lunch,
            'dinner' => $e->dinner,
            'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
        ]);

        $totals = MealEntry::query()
            ->when($start, fn ($q) => $q->whereDate('date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('date', '<=', $end->toDateString()))
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->first();

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'month' => $month,
                'totals' => [
                    'breakfast' => (int) $totals->breakfast,
                    'lunch' => (int) $totals->lunch,
                    'dinner' => (int) $totals->dinner,
                    'total' => (int) $totals->total,
                ],
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                    'total' => $entries->total(),
                ],
            ],
        ]);
    }

    /**
     * The day grid: every active member with whatever has been recorded for
     * the requested date, plus the day's running totals.
     */
    public function day(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        $existing = MealEntry::query()
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        $members = Student::active()
            ->orderBy('name')
            ->get(['id', 'name', 'roll'])
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'roll' => $s->roll,
                'breakfast' => (int) ($existing->get($s->id)->breakfast ?? 0),
                'lunch' => (int) ($existing->get($s->id)->lunch ?? 0),
                'dinner' => (int) ($existing->get($s->id)->dinner ?? 0),
            ]);

        return response()->json([
            'data' => [
                'date' => $date,
                'members' => $members,
                'totals' => $this->dayTotals($date),
            ],
        ]);
    }

    /** Save a whole day's grid in one request. */
    public function storeDay(Request $request)
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

        DB::transaction(function () use ($data, $request, &$saved) {
            foreach ($data['entries'] as $row) {
                $breakfast = (int) ($row['breakfast'] ?? 0);
                $lunch = (int) ($row['lunch'] ?? 0);
                $dinner = (int) ($row['dinner'] ?? 0);

                // Zero rows are removed rather than stored - keeps the table clean.
                if ($breakfast === 0 && $lunch === 0 && $dinner === 0) {
                    MealEntry::where('student_id', $row['student_id'])
                        ->whereDate('date', $data['date'])
                        ->delete();

                    continue;
                }

                $entry = MealEntry::query()
                    ->where('student_id', $row['student_id'])
                    ->whereDate('date', $data['date'])
                    ->first();

                $attributes = [
                    'breakfast' => $breakfast,
                    'lunch' => $lunch,
                    'dinner' => $dinner,
                    'recorded_by' => $request->user()->id,
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

        return response()->json([
            'data' => ['saved' => $saved, 'date' => $data['date'], 'totals' => $this->dayTotals($data['date'])],
            'message' => "Meal entries saved for {$saved} member(s).",
        ]);
    }

    protected function dayTotals(string $date): array
    {
        $row = MealEntry::query()
            ->whereDate('date', $date)
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->first();

        $b = (int) $row->breakfast;
        $l = (int) $row->lunch;
        $d = (int) $row->dinner;

        return ['breakfast' => $b, 'lunch' => $l, 'dinner' => $d, 'total' => $b + $l + $d];
    }
}
