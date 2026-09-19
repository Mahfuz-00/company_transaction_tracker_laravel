<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealRate;
use App\Models\Student;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        /*
         * SOFTWARE SUPER ADMIN -> GLOBAL BUSINESS DASHBOARD.
         *
         * The SSA is the SaaS operator, not a tenant operator. Landing them in
         * a single institution's meal/expense sheets was the reported bug: it
         * looked like they "belonged" to whichever workspace happened to be the
         * current tenant.
         *
         * Rule: an SSA ALWAYS lands on the platform dashboard - UNLESS they have
         * deliberately switched INTO a workspace via the Institution Registry's
         * "Access Dashboard", in which case they see that tenant's dashboard on
         * purpose (and the amber "switched view" banner lets them leave).
         */
        if ($user && $user->isSuperAdmin()) {
            // A session tenant is set only by an explicit "Access Dashboard"
            // switch. With none set, keep them on the global view.
            $switchedInto = Institution::sessionTenantId() !== null;

            if (! $switchedInto) {
                return redirect()->route('ssa.dashboard');
            }

            // Intentional tenant view: fall through to the scoped dashboard.
        }

        // Members get their OWN dashboard, not the manager's pooled overview.
        // Sending them there also keeps the org-wide figures off their screen.
        if ($user && $user->isMember() && ! $user->isSuperAdmin() && ! $user->isInstitutionAdmin()) {
            return redirect()->route('member.dashboard');
        }

        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $weekStart = $now->copy()->startOfWeek();

        $costPerMeal = $this->currentCostPerMeal();

        /* -------------------------------------------------------------- *
         * Pool financials
         *
         * The mess pool is shared, so these are system-wide totals, not
         * scoped to the signed-in user. That was the core mismatch in the
         * old dashboard, which summed only the viewer's own transactions.
         * -------------------------------------------------------------- */
        $totalDeposits = (float) Transaction::query()->deposits()->sum('amount');
        $totalExpenses = (float) Transaction::query()->expenses()->sum('amount');
        $poolBalance = round($totalDeposits - $totalExpenses, 2);

        $monthDeposits = (float) Transaction::query()
            ->deposits()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $monthExpenses = (float) Transaction::query()
            ->expenses()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('amount');

        /* -------------------------------------------------------------- *
         * Meal metrics
         * -------------------------------------------------------------- */
        $monthMeals = (int) $this->mealSum($monthStart, $monthEnd);
        $weekMeals = (int) $this->mealSum($weekStart, $now);
        $todayMeals = (int) $this->mealSum($now->copy()->startOfDay(), $now->copy()->endOfDay());

        $monthMealCost = round($monthMeals * $costPerMeal, 2);

        /* -------------------------------------------------------------- *
         * Roster + per-student balances
         * -------------------------------------------------------------- */
        $activeStudents = Student::active()->count();
        $totalStudents = Student::count();

        // Meal counts per student for the current month, one grouped query.
        $mealCounts = MealEntry::query()
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as meals')
            ->groupBy('student_id')
            ->pluck('meals', 'student_id');

        $depositsByStudent = Deposit::query()
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $studentBalances = Student::query()
            ->with('department:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (Student $student) use ($mealCounts, $depositsByStudent, $costPerMeal) {
                $meals = (int) ($mealCounts->get($student->id) ?? 0);
                $deposited = (float) ($depositsByStudent->get($student->id) ?? 0);
                $mealCost = round($meals * $costPerMeal, 2);

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'roll' => $student->roll,
                    'department' => $student->department?->name,
                    'status' => $student->status,
                    'meals' => $meals,
                    'deposited' => $deposited,
                    'meal_cost' => $mealCost,
                    'balance' => round($deposited - $mealCost, 2),
                ];
            });

        $studentsWithDues = $studentBalances->where('balance', '<', 0);
        $topBalances = $studentBalances->sortByDesc('meals')->take(8)->values();

        /* -------------------------------------------------------------- *
         * Charts
         * -------------------------------------------------------------- */

        // Daily meals + spend for the last 14 days.
        $chartStart = $now->copy()->subDays(13)->startOfDay();

        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', date)"
            : 'DATE(date)';
        $txDateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : 'DATE(created_at)';

        $mealsByDay = MealEntry::query()
            ->where('date', '>=', $chartStart)
            ->select(
                DB::raw("{$dateExpr} as day"),
                DB::raw('COALESCE(SUM(breakfast), 0) as breakfast'),
                DB::raw('COALESCE(SUM(lunch), 0) as lunch'),
                DB::raw('COALESCE(SUM(dinner), 0) as dinner'),
                DB::raw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $expensesByDay = Transaction::query()
            ->expenses()
            ->where('created_at', '>=', $chartStart)
            ->select(
                DB::raw("{$txDateExpr} as day"),
                DB::raw('COALESCE(SUM(amount), 0) as total')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $depositsByDay = Transaction::query()
            ->deposits()
            ->where('created_at', '>=', $chartStart)
            ->select(
                DB::raw("{$txDateExpr} as day"),
                DB::raw('COALESCE(SUM(amount), 0) as total')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Fill every day in the window so the chart has no gaps.
        $dailyTrend = collect(range(0, 13))->map(function ($offset) use ($chartStart, $mealsByDay, $expensesByDay, $depositsByDay) {
            $day = $chartStart->copy()->addDays($offset);
            $key = $day->toDateString();
            $meals = $mealsByDay->get($key);

            return [
                'date' => $key,
                'label' => $day->format('M j'),
                'breakfast' => (int) ($meals->breakfast ?? 0),
                'lunch' => (int) ($meals->lunch ?? 0),
                'dinner' => (int) ($meals->dinner ?? 0),
                'meals' => (int) ($meals->total ?? 0),
                'expenses' => (float) ($expensesByDay->get($key)->total ?? 0),
                'deposits' => (float) ($depositsByDay->get($key)->total ?? 0),
            ];
        })->values();

        // Spend by category for the current month.
        $expenseBreakdown = Transaction::query()
            ->expenses()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->select('category', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category ?: 'Uncategorised',
                'total' => (float) $row->total,
            ]);

        return Inertia::render('Dashboard', [
            'metrics' => [
                'pool_balance' => $poolBalance,
                'total_deposits' => $totalDeposits,
                'total_expenses' => $totalExpenses,
                'month_deposits' => $monthDeposits,
                'month_expenses' => $monthExpenses,
                'month_meals' => $monthMeals,
                'week_meals' => $weekMeals,
                'today_meals' => $todayMeals,
                'month_meal_cost' => $monthMealCost,
                'cost_per_meal' => $costPerMeal,
                'active_students' => $activeStudents,
                'total_students' => $totalStudents,
                'students_with_dues' => $studentsWithDues->count(),
                'total_dues' => round($studentsWithDues->sum(fn ($s) => abs($s['balance'])), 2),
                'month_label' => $now->format('F Y'),
            ],
            'dailyTrend' => $dailyTrend,
            'expenseBreakdown' => $expenseBreakdown,
            'topStudents' => $topBalances,
            'recentTransactions' => $this->recentTransactions(),
                    'hasMealRate' => $costPerMeal > 0,
                'reconciliation' => $this->reconciliation($totalDeposits, $totalExpenses),
            ]);
    }

    /**
     * Total meals in a window. Uses SUM of the meal columns, not COUNT of
     * rows - a student can eat multiple meals in a single day's row.
     */
    protected function mealSum(Carbon $from, Carbon $to): int
    {
        $row = MealEntry::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->first();

        return (int) ($row->total ?? 0);
    }

    protected function currentCostPerMeal(): float
    {
        $rate = MealRate::query()
            ->whereNotNull('cost_per_meal')
            ->orderByDesc('to_date')
            ->orderByDesc('created_at')
            ->first();

        return (float) ($rate?->cost_per_meal ?? 0);
    }

    /**
     * Detects drift between the meal module's own tables and the ledger.
     *
     * Deposits/expenses are recorded in BOTH the module table and
     * `transactions`. Rows created before the two were linked have no
     * transaction, so the pool figure here can disagree with Meal Reports.
     * Surfacing the gap beats silently picking one number.
     */
    protected function reconciliation(float $ledgerDeposits, float $ledgerExpenses): array
    {
        $moduleDeposits = (float) Deposit::query()->sum('amount');

        $moduleExpenses = (float) Transaction::query()
            ->where('source', 'meal_expense')
            ->sum('amount');

        $depositGap = round($moduleDeposits - $ledgerDeposits, 2);
        $unlinked = Deposit::query()->whereNull('transaction_id')->count();

        return [
            'module_deposits' => $moduleDeposits,
            'ledger_deposits' => $ledgerDeposits,
            'module_expenses' => $moduleExpenses,
            'deposit_gap' => $depositGap,
            'unlinked_deposits' => $unlinked,
            // Only flag a problem when there is a real, material difference.
            'has_drift' => abs($depositGap) > 0.01 || $unlinked > 0,
        ];
    }

    protected function recentTransactions()
    {
        return Transaction::query()
            ->with(['student:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'item' => $tx->item,
                'category' => $tx->category,
                'payee' => $tx->payee,
                'student' => $tx->student?->name,
                'recorded_by' => $tx->user?->name,
                'date' => $tx->created_at->format('M j, Y'),
            ]);
    }
}
