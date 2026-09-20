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
 */
class DashboardApiController extends Controller
{
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        $snapshot = $finance->monthSnapshot($month);
        $members = $finance->memberBreakdown($month);

        // Members who still owe the pool, biggest debt first.
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
                    'total_dues' => round($dues->sum(fn ($d) => abs($d['balance'])), 2),
                ],
                'top_dues' => $dues,
                'recent_activity' => $this->recentTransactions(),
            ],
        ]);
    }

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
