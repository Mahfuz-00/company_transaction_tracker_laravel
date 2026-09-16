<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Models\MealRate;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MealReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from');
        $to = $request->input('to');

        /* -------------------------------------------------------------- *
         * Totals
         *
         * Each aggregate needs its own builder. Previously one $query was
         * reused across three sum() calls, and the date filters were applied
         * to it repeatedly - the numbers were wrong as soon as a range was set.
         * -------------------------------------------------------------- */

        // Meals eaten = SUM(breakfast + lunch + dinner), not COUNT(rows).
        $mealTotals = $this->mealEntryQuery($from, $to)
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->first();

        $breakfastTotal = (int) ($mealTotals->breakfast ?? 0);
        $lunchTotal = (int) ($mealTotals->lunch ?? 0);
        $dinnerTotal = (int) ($mealTotals->dinner ?? 0);
        $totalMeals = $breakfastTotal + $lunchTotal + $dinnerTotal;

        $totalDeposits = (float) Deposit::query()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');

        $totalExpenses = (float) \App\Models\Transaction::query()
            ->where('type', 'out')
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');

        /* -------------------------------------------------------------- *
         * Meal rate
         *
         * Prefer a configured rate. If none exists, fall back to the figure
         * the mess actually spent per meal - deposits are money pooled, not
         * money spent, so dividing deposits by meals would overstate the rate.
         * -------------------------------------------------------------- */
        $rate = MealRate::query()
            ->whereNotNull('cost_per_meal')
            ->when($from, fn ($q) => $q->where('to_date', '>=', $from))
            ->orderByDesc('to_date')
            ->orderByDesc('created_at')
            ->first();

        $costPerMeal = $rate
            ? (float) $rate->cost_per_meal
            : ($totalMeals > 0 ? round($totalExpenses / $totalMeals, 4) : 0.0);

        /* -------------------------------------------------------------- *
         * Per-student breakdown
         *
         * One grouped query for meal counts + one for deposits, then stitch
         * them together - avoids N+1 across the roster.
         * -------------------------------------------------------------- */
        $mealCounts = $this->mealEntryQuery($from, $to)
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total_meals')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $depositTotals = Deposit::query()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_deposits')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $students = Student::query()
            ->with('department:id,name,slug')
            ->orderBy('name')
            ->get()
            ->map(function (Student $student) use ($mealCounts, $depositTotals, $costPerMeal) {
                $meals = $mealCounts->get($student->id);
                $deposited = (float) ($depositTotals->get($student->id)->total_deposits ?? 0);

                $totalMeals = (int) ($meals->total_meals ?? 0);
                $mealCost = round($totalMeals * $costPerMeal, 2);
                $balance = round($deposited - $mealCost, 2);

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'roll' => $student->roll,
                    'status' => $student->status,
                    'department' => $student->department?->name,
                    'breakfast' => (int) ($meals->breakfast ?? 0),
                    'lunch' => (int) ($meals->lunch ?? 0),
                    'dinner' => (int) ($meals->dinner ?? 0),
                    'total_meals' => $totalMeals,
                    'total_deposits' => $deposited,
                    'meal_cost' => $mealCost,
                    'balance' => $balance,
                    // Negative balance = this student still owes the mess.
                    'is_due' => $balance < 0,
                ];
            })
            // Most meals first - the manager usually wants the top consumers.
            ->sortByDesc('total_meals')
            ->values();

        $totalMealCost = round($students->sum('meal_cost'), 2);

        return Inertia::render('Meals/Reports/Index', [
            'summary' => [
                'total_meals' => $totalMeals,
                'breakfast' => $breakfastTotal,
                'lunch' => $lunchTotal,
                'dinner' => $dinnerTotal,
                'total_deposits' => $totalDeposits,
                'total_expenses' => $totalExpenses,
                'total_meal_cost' => $totalMealCost,
                'cost_per_meal' => $costPerMeal,
                'pool_balance' => round($totalDeposits - $totalExpenses, 2),
                'students_with_dues' => $students->where('is_due', true)->count(),
                'total_dues' => round($students->where('is_due', true)->sum(fn ($s) => abs($s['balance'])), 2),
            ],
            'students' => $students,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * A fresh MealEntry query with the date range applied.
     * Deliberately a new instance per call so aggregates can't share state.
     */
    protected function mealEntryQuery(?string $from, ?string $to)
    {
        return MealEntry::query()
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));
    }
}
