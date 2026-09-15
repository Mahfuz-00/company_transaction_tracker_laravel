<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Models\MealRate;
use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MealReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:meals.reports');
    }

    public function index(Request $request)
    {
        $from = $request->input('from');
        $to = $request->input('to');

        $query = MealEntry::query();
        if ($from) $query->where('date', '>=', $from);
        if ($to) $query->where('date', '<=', $to);

        $totalMeals = $query->sum('breakfast') + $query->sum('lunch') + $query->sum('dinner');
        $totalDeposits = Deposit::when($from, fn($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn($q) => $q->where('created_at', '<=', $to))
            ->sum('amount');

        $latestRate = MealRate::orderBy('created_at', 'desc')->first();
        $costPerMeal = $latestRate ? $latestRate->cost_per_meal : ($totalMeals > 0 ? ($totalDeposits / $totalMeals) : null);

        $students = Student::withCount(['entries as meals_count' => function ($q) use ($from, $to) {
            if ($from) $q->where('date', '>=', $from);
            if ($to) $q->where('date', '<=', $to);
        }])->orderByDesc('meals_count')->limit(50)->get();

        return Inertia::render('Meals/Reports/Index', [
            'totalMeals' => $totalMeals,
            'totalDeposits' => $totalDeposits,
            'costPerMeal' => $costPerMeal,
            'students' => $students,
        ]);
    }
}
