<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;

/**
 * MEMBER SELF-SERVICE API — the personal area of the mobile app.
 *
 * This is the single most important mobile surface: the Member role is the
 * PRIMARY mobile user, and every screen here is strictly personal. Each method
 * resolves the caller's own member record via `$request->user()->studentRecord()`
 * and filters on that id — never on a client-supplied member id. A member cannot
 * request another member's figures because the endpoint never accepts one.
 *
 * That is a deliberate contrast with MemberApiController (the staff roster), which
 * DOES accept member ids but is permission-gated to staff. Here there is nothing
 * to authorise beyond being signed in: you can only ever see yourself.
 *
 * Mirrors web: MemberDashboardController (dashboard/meals/deposits/analytics).
 */
class MemberSelfApiController extends Controller
{
    /**
     * GET /api/me/dashboard
     *
     * The member's own month summary plus lifetime totals and recent history —
     * everything the home screen needs in one call (the mobile equivalent of the
     * web Member/Dashboard page).
     */
    public function dashboard(Request $request)
    {
        $student = $request->user()->studentRecord();

        // A login with no roster row yet: a clear, non-crashing empty state rather
        // than a 404. Staff who were mis-routed here also land on this branch.
        if (! $student) {
            return response()->json([
                'data' => [
                    'has_member_record' => false,
                    'message' => 'Your account is not linked to a member record yet. Ask your manager to link it.',
                ],
            ]);
        }

        $finance = new FinanceCalculator;
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $costPerMeal = $finance->perMealRate($month);

        $row = $finance->memberBreakdown($month)->firstWhere('id', $student->id) ?? [
            'meals' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0,
            'meal_cost' => 0, 'deposited' => 0, 'subsidy_share' => 0, 'balance' => 0,
        ];

        $lifetimeDeposits = (float) Deposit::query()
            ->where('student_id', $student->id)
            ->whereNull('reversed_at')
            ->sum('amount');

        $lifetimeMeals = (int) MealEntry::query()
            ->where('student_id', $student->id)
            ->selectRaw('COALESCE(SUM(breakfast + lunch + dinner), 0) as total')
            ->value('total');

        return response()->json([
            'data' => [
                'has_member_record' => true,
                'month' => $month,
                'member' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'roll' => $student->roll,
                    'status' => $student->status,
                    'department' => $student->department?->name,
                    'manager' => $student->manager_label,
                    'institution' => $student->institution?->name,
                ],
                'summary' => [
                    'balance' => (float) ($row['balance'] ?? 0),
                    'is_due' => (float) ($row['balance'] ?? 0) < 0,
                    'month_meals' => (int) ($row['meals'] ?? 0),
                    'month_breakfast' => (int) ($row['breakfast'] ?? 0),
                    'month_lunch' => (int) ($row['lunch'] ?? 0),
                    'month_dinner' => (int) ($row['dinner'] ?? 0),
                    'month_meal_cost' => (float) ($row['meal_cost'] ?? 0),
                    'month_deposited' => (float) ($row['deposited'] ?? 0),
                    'subsidy_share' => (float) ($row['subsidy_share'] ?? 0),
                    'lifetime_deposits' => $lifetimeDeposits,
                    'lifetime_meals' => $lifetimeMeals,
                    // An ESTIMATE at the current rate, not exact historical pricing.
                    // The client labels it as such (see docs/API.md).
                    'lifetime_meal_cost_estimate' => round($lifetimeMeals * $costPerMeal, 2),
                    'cost_per_meal' => $costPerMeal,
                ],
                'recent_entries' => $this->recentEntries($student),
                'recent_deposits' => $this->recentDeposits($student),
                'claims_pending' => Claim::query()
                    ->where('student_id', $student->id)
                    ->where('status', 'pending')
                    ->count(),
            ],
        ]);
    }

    /**
     * GET /api/me/meals?month=&per_page=
     *
     * The member's own meal entries for a month, paginated with month totals.
     */
    public function meals(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json(['data' => [], 'meta' => ['has_member_record' => false]]);
        }

        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $perPage = min((int) $request->query('per_page', 50), 200);

        $entries = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereYear('date', substr($month, 0, 4))
            ->whereMonth('date', substr($month, 5, 2))
            ->orderByDesc('date')
            ->paginate($perPage);

        $entries->through(fn (MealEntry $e) => [
            'id' => $e->id,
            'date' => $e->date?->toDateString(),
            'breakfast' => (int) $e->breakfast,
            'lunch' => (int) $e->lunch,
            'dinner' => (int) $e->dinner,
            'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
        ]);

        // Month totals across ALL of the member's rows, not just this page, so the
        // header figures do not shift as the user scrolls.
        $totals = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereYear('date', substr($month, 0, 4))
            ->whereMonth('date', substr($month, 5, 2))
            ->selectRaw('COALESCE(SUM(breakfast),0) as breakfast, COALESCE(SUM(lunch),0) as lunch, COALESCE(SUM(dinner),0) as dinner')
            ->first();

        $breakfast = (int) ($totals->breakfast ?? 0);
        $lunch = (int) ($totals->lunch ?? 0);
        $dinner = (int) ($totals->dinner ?? 0);

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'month' => $month,
                'has_member_record' => true,
                'totals' => [
                    'breakfast' => $breakfast,
                    'lunch' => $lunch,
                    'dinner' => $dinner,
                    'total' => $breakfast + $lunch + $dinner,
                ],
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                    'per_page' => $entries->perPage(),
                    'total' => $entries->total(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/me/deposits?month=&per_page=
     *
     * The member's own deposit history, with month and lifetime totals.
     */
    public function deposits(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json(['data' => [], 'meta' => ['has_member_record' => false]]);
        }

        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $perPage = min((int) $request->query('per_page', 25), 100);

        $deposits = Deposit::query()
            ->where('student_id', $student->id)
            ->whereYear('created_at', substr($month, 0, 4))
            ->whereMonth('created_at', substr($month, 5, 2))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $deposits->through(fn (Deposit $d) => [
            'id' => $d->id,
            'amount' => (float) $d->amount,
            'kind' => $d->kind,
            'kind_label' => Deposit::KINDS[$d->kind]['label'] ?? $d->kind,
            'payment_method' => $d->payment_method,
            'notes' => $d->notes,
            'date' => $d->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $deposits->items(),
            'meta' => [
                'month' => $month,
                'has_member_record' => true,
                'month_total' => (float) Deposit::query()
                    ->where('student_id', $student->id)
                    ->whereNull('reversed_at')
                    ->whereYear('created_at', substr($month, 0, 4))
                    ->whereMonth('created_at', substr($month, 5, 2))
                    ->sum('amount'),
                'lifetime_total' => (float) Deposit::query()
                    ->where('student_id', $student->id)
                    ->whereNull('reversed_at')
                    ->sum('amount'),
                'pagination' => [
                    'current_page' => $deposits->currentPage(),
                    'last_page' => $deposits->lastPage(),
                    'per_page' => $deposits->perPage(),
                    'total' => $deposits->total(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/me/analytics
     *
     * The member's personal trend: month-by-month meals and cost, newest first.
     * Deliberately limited to a recent window — a long tenure is many rows, and
     * the mobile client only charts the recent trend (historical months are
     * explicitly NOT cached offline; see the mobile spec §5.1).
     */
    public function analytics(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json(['data' => ['has_member_record' => false, 'months' => []]]);
        }

        $finance = new FinanceCalculator;
        $months = min((int) $request->query('months', 6), 24);

        $history = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $rate = $finance->perMealRate($month);
            $row = $finance->memberBreakdown($month)->firstWhere('id', $student->id);

            $mealCount = (int) ($row['meals'] ?? 0);

            $history[] = [
                'month' => $month,
                'label' => now()->subMonths($i)->format('M Y'),
                'meals' => $mealCount,
                'breakfast' => (int) ($row['breakfast'] ?? 0),
                'lunch' => (int) ($row['lunch'] ?? 0),
                'dinner' => (int) ($row['dinner'] ?? 0),
                'meal_cost' => (float) ($row['meal_cost'] ?? 0),
                'deposited' => (float) ($row['deposited'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'per_meal_rate' => $rate,
                'cost_per_meal' => $mealCount > 0
                    ? round((float) ($row['meal_cost'] ?? 0) / $mealCount, 2)
                    : 0.0,
            ];
        }

        return response()->json([
            'data' => [
                'has_member_record' => true,
                'member' => ['id' => $student->id, 'name' => $student->name, 'roll' => $student->roll],
                // Newest first, matching the web chart's x-axis order.
                'months' => array_reverse($history),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** Up to 30 of the member's latest meal entries (newest first). */
    protected function recentEntries($student): array
    {
        return MealEntry::query()
            ->where('student_id', $student->id)
            ->orderByDesc('date')
            ->limit(30)
            ->get()
            ->map(fn (MealEntry $e) => [
                'date' => $e->date?->toDateString(),
                'breakfast' => (int) $e->breakfast,
                'lunch' => (int) $e->lunch,
                'dinner' => (int) $e->dinner,
                'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
            ])
            ->all();
    }

    /** Up to 10 of the member's latest deposits (newest first). */
    protected function recentDeposits($student): array
    {
        return Deposit::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Deposit $d) => [
                'id' => $d->id,
                'amount' => (float) $d->amount,
                'kind' => $d->kind,
                'payment_method' => $d->payment_method,
                'date' => $d->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
