<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Support\FinanceCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The member / participant dashboard.
 *
 * Strictly personal: every figure is scoped to the signed-in user's own member
 * record. A member can see their own deposits, meals and balance - never another
 * member's, and never the institution's pooled totals (those stay on the
 * manager dashboard).
 */
class MemberDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Resolve the member record behind this login. A member is a User whose
        // students.user_id points at them.
        $student = $user->studentRecord();

        // A user with no member record yet (e.g. a fresh staff account that was
        // mis-routed here) is shown a clear, non-crashing empty state.
        if (! $student) {
            return Inertia::render('Member/Dashboard', [
                'hasMemberRecord' => false,
            ]);
        }

        $finance = new FinanceCalculator;
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $costPerMeal = $finance->perMealRate($month);

        // The month-scoped row for this member (meals, cost, deposited, balance).
        $row = $finance->memberBreakdown($month)->firstWhere('id', $student->id) ?? [
            'meals' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0,
            'meal_cost' => 0, 'deposited' => 0, 'subsidy_share' => 0, 'balance' => 0,
        ];

        // Lifetime deposits, so the member sees a true running total.
        $lifetimeDeposits = (float) Deposit::query()
            ->where('student_id', $student->id)
            ->whereNull('reversed_at')
            ->sum('amount');

        // All-time meals eaten.
        $lifetimeMeals = (int) MealEntry::query()
            ->where('student_id', $student->id)
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->value('total');

        // Lifetime meal cost at the current rate is an estimate; label it as such
        // in the UI rather than pretending it is exact historical pricing.
        $lifetimeMealCost = round($lifetimeMeals * $costPerMeal, 2);

        return Inertia::render('Member/Dashboard', [
            'hasMemberRecord' => true,
            'member' => [
                'id' => $student->id,
                'name' => $student->name,
                'roll' => $student->roll,
                'status' => $student->status,
                'department' => $student->department?->name,
                // The person responsible for this member's account/group.
                'manager' => $student->manager_label,
            ],
            'summary' => [
                'balance' => (float) ($row['balance'] ?? 0),
                'is_due' => (float) ($row['balance'] ?? 0) < 0,
                'month_meals' => (int) ($row['meals'] ?? 0),
                'month_meal_cost' => (float) ($row['meal_cost'] ?? 0),
                'month_deposited' => (float) ($row['deposited'] ?? 0),
                'subsidy_share' => (float) ($row['subsidy_share'] ?? 0),
                'lifetime_deposits' => $lifetimeDeposits,
                'lifetime_meals' => $lifetimeMeals,
                'lifetime_meal_cost_estimate' => $lifetimeMealCost,
                'cost_per_meal' => $costPerMeal,
                'month_label' => $month,
            ],
            // Month-by-month breakdown, newest first, for the history table.
            'history' => $this->monthlyHistory($student, $costPerMeal),
            'recentEntries' => $this->recentEntries($student),
            'recentDeposits' => $this->recentDeposits($student),
            // Claims this member has raised, so they can track their status.
            'claims' => Claim::query()
                ->where('student_id', $student->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Claim $c) => [
                    'id' => $c->id,
                    'kind' => $c->kind,
                    'kind_label' => $c->kindLabel(),
                    'status' => $c->status,
                    'status_label' => $c->statusLabel(),
                    'title' => $c->title,
                    'summary' => $c->summary,
                    'amount' => $c->amount !== null ? (float) $c->amount : null,
                    'created_at' => $c->created_at?->format('j M Y'),
                ]),
            'claims_pending' => Claim::query()
                ->where('student_id', $student->id)
                ->where('status', 'pending')
                ->count(),
            'months' => $this->monthOptions(),
            'month' => $month,
        ]);
    }

    /**
     * A member's own meal entries, day by day. Paginated because a long tenure
     * is many rows - and always scoped to the signed-in member.
     */
    public function meals(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return Inertia::render('Member/Meals', ['hasMemberRecord' => false]);
        }

        $month = FinanceCalculator::resolveMonth($request->query('month'));
        [$start, $end] = FinanceCalculator::monthBounds($month);

        $entries = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->paginate(31)
            ->withQueryString()
            ->through(fn (MealEntry $e) => [
                'id' => $e->id,
                'date' => $e->date?->format('j M Y'),
                'breakfast' => (int) $e->breakfast,
                'lunch' => (int) $e->lunch,
                'dinner' => (int) $e->dinner,
                'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
            ]);

        // Month tallies for the summary strip (own data only).
        $totals = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->first();

        return Inertia::render('Member/Meals', [
            'hasMemberRecord' => true,
            'member' => ['name' => $student->name, 'roll' => $student->roll],
            'entries' => $entries,
            'totals' => [
                'breakfast' => (int) ($totals->breakfast ?? 0),
                'lunch' => (int) ($totals->lunch ?? 0),
                'dinner' => (int) ($totals->dinner ?? 0),
                'total' => (int) ($totals->total ?? 0),
            ],
            'costPerMeal' => (new FinanceCalculator)->perMealRate($month),
            'months' => $this->monthOptions(),
            'month' => $month,
        ]);
    }

    /**
     * A member's own deposit history - every payment recorded against them, with
     * a running summary. Never shows another member's deposits.
     */
    public function deposits(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return Inertia::render('Member/Deposits', ['hasMemberRecord' => false]);
        }

        $deposits = Deposit::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Deposit $d) => [
                'id' => $d->id,
                'amount' => (float) $d->amount,
                'method' => $d->payment_method,
                'kind' => $d->kind,
                'notes' => $d->notes,
                'reversed' => $d->reversed_at !== null,
                'date' => $d->created_at?->format('j M Y'),
            ]);

        $total = (float) Deposit::query()
            ->where('student_id', $student->id)
            ->whereNull('reversed_at')
            ->sum('amount');

        return Inertia::render('Member/Deposits', [
            'hasMemberRecord' => true,
            'member' => ['name' => $student->name, 'roll' => $student->roll],
            'deposits' => $deposits,
            'totalDeposited' => $total,
        ]);
    }

    /**
     * Personal analytics: this member's own meal + money trend, month by month.
     * Deliberately NOT the org-wide analytics - a member must never see pooled
     * institution figures.
     */
    public function analytics(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return Inertia::render('Member/Analytics', ['hasMemberRecord' => false]);
        }

        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator;
        $costPerMeal = $finance->perMealRate($month);
        $history = $this->monthlyHistory($student, $costPerMeal);

        // Meal-type split for the selected month.
        [$start, $end] = FinanceCalculator::monthBounds($month);
        $split = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('COALESCE(SUM(breakfast), 0) as breakfast')
            ->selectRaw('COALESCE(SUM(lunch), 0) as lunch')
            ->selectRaw('COALESCE(SUM(dinner), 0) as dinner')
            ->first();

        $row = $finance->memberBreakdown($month)->firstWhere('id', $student->id) ?? [];

        return Inertia::render('Member/Analytics', [
            'hasMemberRecord' => true,
            'member' => ['name' => $student->name, 'roll' => $student->roll],
            'month' => $month,
            'months' => $this->monthOptions(),
            'summary' => [
                'month_meals' => (int) ($row['meals'] ?? 0),
                'month_meal_cost' => (float) ($row['meal_cost'] ?? 0),
                'month_deposited' => (float) ($row['deposited'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'cost_per_meal' => $costPerMeal,
            ],
            'mealSplit' => [
                'breakfast' => (int) ($split->breakfast ?? 0),
                'lunch' => (int) ($split->lunch ?? 0),
                'dinner' => (int) ($split->dinner ?? 0),
            ],
            // Reverse-chronological history drives the trend chart.
            'history' => $history,
        ]);
    }

    /**
     * The last 12 months of this member's meals / deposits / balance.
     * One FinanceCalculator pass per month is fine at this scale and keeps the
     * monthly figures consistent with every other screen.
     */
    protected function monthlyHistory($student, float $costPerMeal): array
    {
        $rows = [];

        for ($i = 0; $i < 12; $i++) {
            $month = now()->copy()->subMonths($i)->format('Y-m');
            [$start, $end] = FinanceCalculator::monthBounds($month);

            $meals = (int) MealEntry::query()
                ->where('student_id', $student->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
                ->value('total');

            $deposited = (float) Deposit::query()
                ->where('student_id', $student->id)
                ->whereNull('reversed_at')
                ->whereDate('created_at', '>=', $start->toDateString())
                ->whereDate('created_at', '<=', $end->toDateString())
                ->sum('amount');

            $rate = (new FinanceCalculator)->perMealRate($month);
            $mealCost = round($meals * $rate, 2);

            $rows[] = [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->format('F Y'),
                'meals' => $meals,
                'meal_cost' => $mealCost,
                'deposited' => round($deposited, 2),
                'balance' => round($deposited - $mealCost, 2),
                'rate' => $rate,
            ];
        }

        return $rows;
    }

    protected function recentEntries($student)
    {
        return MealEntry::query()
            ->where('student_id', $student->id)
            ->orderByDesc('date')
            ->limit(15)
            ->get()
            ->map(fn (MealEntry $e) => [
                'id' => $e->id,
                'date' => $e->date?->format('j M Y'),
                'breakfast' => (int) $e->breakfast,
                'lunch' => (int) $e->lunch,
                'dinner' => (int) $e->dinner,
                'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
            ]);
    }

    protected function recentDeposits($student)
    {
        return Deposit::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn (Deposit $d) => [
                'id' => $d->id,
                'amount' => (float) $d->amount,
                'method' => $d->payment_method,
                'kind' => $d->kind,
                'notes' => $d->notes,
                'reversed' => $d->reversed_at !== null,
                'date' => $d->created_at?->format('j M Y'),
            ]);
    }

    /** The last 18 months, for the month selector. */
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
