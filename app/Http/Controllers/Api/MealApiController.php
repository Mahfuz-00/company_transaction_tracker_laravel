<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMealDayRequest;
use App\Http\Resources\MealEntryResource;
use App\Models\MealEntry;
use App\Models\Student;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meal entries API. Exposes the same day-grid the web UI uses, so a mobile
 * client can fill in a whole day in one request.
 *
 * The day-grid is the interesting part: rather than PUT one row per member, the
 * client sends the entire day in a single payload and the server upserts it.
 * Empty rows (all three meals zero) are DELETED instead of stored, which keeps
 * the table small and makes "no entry" and "zero meals" the same state.
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

        // Month totals across BOTH meal columns, not just the current page.
        $totals = MealEntry::query()
            ->when($start, fn ($q) => $q->whereDate('date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('date', '<=', $end->toDateString()))
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->first();

        return response()->json([
            'data' => MealEntryResource::collection($entries->items())->resolve(),
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

        // One query for the day's existing rows, keyed by member for O(1) lookup
        // as we build the grid (avoids N queries for N members).
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
    public function storeDay(StoreMealDayRequest $request)
    {
        /*
         * SECURITY: the rules in StoreMealDayRequest scope
         * `entries.*.student_id` to the caller's institution, so a crafted
         * request naming another workspace's member is rejected (422) before the
         * loop below runs. Previously this used a table-wide `exists` rule and a
         * foreign student id could be written into the caller's institution.
         */
        $data = $request->validated();

        $saved = 0;

        DB::transaction(function () use ($data, $request, &$saved) {
            foreach ($data['entries'] as $row) {
                $breakfast = (int) ($row['breakfast'] ?? 0);
                $lunch = (int) ($row['lunch'] ?? 0);
                $dinner = (int) ($row['dinner'] ?? 0);

                // Zero rows are removed rather than stored - keeps the table clean
                // and makes "all zero" identical to "no entry".
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

    /** Breakfast/lunch/dinner sums for one date, computed in SQL. */
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
