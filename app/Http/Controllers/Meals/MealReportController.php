<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\FinanceCalculator;
use App\Support\Money;
use App\Support\ReportExporter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MealReportController extends Controller
{
    /**
     * Reports default strictly to the CURRENT MONTH. A Month selector replaces
     * the old from/to range, which is what a mess manager actually reasons in.
     */
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->input('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);
        $members = $finance->memberBreakdown($month);

        return Inertia::render('Meals/Reports/Index', [
            'summary' => $this->summaryPayload($snapshot, $members),
            'students' => $members,
            'months' => $this->monthOptions(),
            'filters' => ['month' => $month],
        ]);
    }

    /**
     * Shape the finance snapshot into the field names the report UI expects.
     */
    protected function summaryPayload(array $snapshot, $members): array
    {
        $duesTotal = $members->where('is_due', true)
            ->sum(fn ($m) => abs($m['balance']));

        return [
            'total_meals' => $snapshot['meals'],
            'breakfast' => (int) $members->sum('breakfast'),
            'lunch' => (int) $members->sum('lunch'),
            'dinner' => (int) $members->sum('dinner'),
            'total_deposits' => $snapshot['deposits'],
            'total_subsidies' => $snapshot['subsidies'],
            'total_expenses' => $snapshot['expenses'],
            'total_meal_cost' => $snapshot['meal_cost'],
            'cost_per_meal' => $snapshot['per_meal_rate'],
            'pool_balance' => $snapshot['pool_balance'],
            'subsidy_coverage_pct' => $snapshot['subsidy_coverage_pct'],
            'students_with_dues' => $members->where('is_due', true)->count(),
            'total_dues' => round($duesTotal, 2),
            'daily_meals' => $snapshot['daily_meals'],
            'daily_cost' => $snapshot['daily_cost'],
            'month_label' => $snapshot['label'],
        ];
    }

    /**
     * PDF / Excel export of exactly the figures the screen shows.
     */
    public function export(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->input('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);
        $students = $finance->memberBreakdown($month);
        $summary = $this->summaryPayload($snapshot, $students);
        $label = $snapshot['label'];
        $format = $request->input('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'meal-report-'.$month,
            title: 'Meal Report - '.$label,
            columns: [
                'name' => 'Name',
                'roll' => 'Roll ID',
                'department' => 'Group',
                'breakfast' => 'Breakfast',
                'lunch' => 'Lunch',
                'dinner' => 'Dinner',
                'meals' => 'Total Meals',
                'meal_cost' => 'Meal Cost',
                'deposited' => 'Deposited',
                'subsidy_share' => 'Subsidy Share',
                'balance' => 'Balance',
            ],
            rows: $students,
            meta: [
                'Institution' => Institution::current()?->name ?? '-',
                'Period' => $label,
                'Total meals' => $summary['total_meals'],
                'Per-meal rate' => Money::format($summary['cost_per_meal'], null, false),
                'Total spent' => Money::format($summary['total_expenses']),
                'Total deposited' => Money::format($summary['total_deposits']),
                'Subsidies' => Money::format($summary['total_subsidies']),
            ],
            formatter: fn ($value, $key) => in_array($key, ['meal_cost', 'deposited', 'subsidy_share', 'balance'], true)
                ? Money::format((float) $value)
                : $value,
        );

        ActivityLogController::recordExport($request, 'Meal Report - '.$label, [
            'month' => $month,
            'format' => $format,
        ]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
    }

    /** The last 18 months, newest first, for the selector. */
    protected function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 18; $i++) {
            $options[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
                'current' => $i === 0,
            ];
            $cursor->subMonth();
        }

        return $options;
    }
}
