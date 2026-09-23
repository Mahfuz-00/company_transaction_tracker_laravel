<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;

/**
 * The mobile dashboard summary. Mirrors the web dashboard's headline figures
 * but returns them as a compact JSON payload.
 *
 * Every figure is tenant-scoped automatically: the models it reads
 * (`Student`, `Transaction`) carry the `BelongsToInstitution` global scope, and
 * `FinanceCalculator` computes its aggregates through those same scoped
 * queries. A token for one institution therefore only ever sees its own totals.
 */
class DashboardApiController extends Controller
{
    /**
     * Month headline: pool snapshot, roster counts, the biggest outstanding
     * balances and recent activity, in one round-trip for the mobile home screen.
     */
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        // Pooled/derived figures come from the shared calculator, never inline
        // here, so the mobile and web dashboards can never disagree.
        $snapshot = $finance->monthSnapshot($month);
        $members = $finance->memberBreakdown($month);

        // Members who still owe the pool, biggest debt first.
        // `sortBy` (ascending) puts the most negative balance first.
        $dues = $members->where('is_due', true)
            ->sortBy('balance')
            ->take(10)
            ->values()
            ->map(fn ($m) => [
                'id' => $m['id'],
                'name' => $m['name'],
                'roll' => $m['roll'],
                'balance' => $m['balance'],
            ]);

        return response()->json([
            'data' => [
                'month' => $month,
                'month_label' => $snapshot['label'],
                'summary' => $snapshot,
                'members' => [
                    'total' => Student::count(),
                    'active' => Student::active()->count(),
                    'with_dues' => $members->where('is_due', true)->count(),
                    // abs(): dues are stored as negative balances; the headline
                    // "total owed" is a positive figure.
                    'total_dues' => round($dues->sum(fn ($d) => abs($d['balance'])), 2),
                ],
                'top_dues' => $dues,
                'recent_activity' => $this->recentTransactions(),
            ],
        ]);
    }

    /** The last ten ledger rows, shaped for the activity feed. */
    protected function recentTransactions()
    {
        return Transaction::query()
            ->with(['student:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'label' => $tx->type === 'in' ? 'Deposit' : 'Expense',
                'item' => $tx->item,
                'amount' => (float) $tx->amount,
                'category' => $tx->category,
                'member' => $tx->student?->name,
                'recorded_by' => $tx->user?->name,
                'date' => $tx->created_at->toIso8601String(),
            ]);
    }
}
