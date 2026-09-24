<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\AuditLogger;
use App\Support\FinanceCalculator;
use App\Support\Money;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * MEMBER BALANCE REFUNDS.
 *
 * An Institution Admin or Meal Manager pays a member back the credit left in
 * their meal wallet - because they have stopped eating (left the dorm) or are
 * withdrawing funds. Each refund records a `refunds` row AND posts a cash-out
 * ledger transaction, mirroring how a deposit records a cash-in.
 *
 * ACCESS: the routes are gated by `meals.deposit`, the same permission the
 * Deposit module uses, so exactly the roles that can take money IN can pay it
 * OUT. Meal Managers are additionally scoped to the members assigned to them.
 */
class RefundController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $studentId = (string) $request->query('student', '');
        $reason = (string) $request->query('reason', '');
        $from = $request->query('from');
        $to = $request->query('to');

        // A Meal Manager only sees refunds for their assigned members (plus
        // their own member record when they are also a member).
        $scopedIds = $request->user()->scopedStudentIds();

        $refunds = Refund::query()
            ->with(['student:id,name,roll', 'recorder:id,name', 'reverser:id,name'])
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds))
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($reason !== '', fn ($q) => $q->where('reason', $reason))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%' . $search . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('notes', 'like', $term)
                        ->orWhere('payment_method', 'like', $term)
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $term));
                });
            })
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Totals: everything matching the filter, split by live vs reversed.
        $totalsBase = fn () => Refund::query()
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds))
            ->when($studentId !== '', fn ($q) => $q->where('student_id', $studentId))
            ->when($reason !== '', fn ($q) => $q->where('reason', $reason))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $filteredTotal = (float) $totalsBase()->sum('amount');
        $activeTotal = (float) $totalsBase()->whereNull('reversed_at')->sum('amount');
        $reversedTotal = (float) $totalsBase()->whereNotNull('reversed_at')->sum('amount');

        // Withdrawable credit per member, computed with grouped aggregates (no
        // N+1 and no row hydration): lifetime deposits − refunds − meal cost at
        // the current per-meal rate.
        $costPerMeal = (new FinanceCalculator())->perMealRate(now()->format('Y-m'));

        $depositTotals = Deposit::query()
            ->whereNull('reversed_at')
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $refundTotals = Refund::query()
            ->whereNull('reversed_at')
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $mealTotals = MealEntry::query()
            ->select('student_id')
            ->selectRaw('COALESCE(SUM(total_meals), 0) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $members = Student::query()
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('id', $scopedIds))
            ->orderBy('name')
            ->get(['id', 'name', 'roll'])
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'roll' => $s->roll,
                'balance' => round(
                    (float) ($depositTotals[$s->id] ?? 0)
                        - (float) ($refundTotals[$s->id] ?? 0)
                        - ((int) ($mealTotals[$s->id] ?? 0) * $costPerMeal),
                    2
                ),
            ]);

        return Inertia::render('Meals/Refunds/Index', [
            'refunds' => $refunds,
            'members' => $members,
            'reasons' => collect(Refund::REASONS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'costPerMeal' => $costPerMeal,
            'filteredTotal' => $filteredTotal,
            'activeTotal' => $activeTotal,
            'reversedTotal' => $reversedTotal,
            'filters' => [
                'search' => $search,
                'student' => $studentId,
                'reason' => $reason,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function create()
    {
        return redirect()->route('meals.refunds.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', Rule::in(array_keys(Refund::REASONS))],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        // A Meal Manager may only refund a member assigned to them.
        $scopedIds = $request->user()->scopedStudentIds();
        if ($scopedIds !== null && ! in_array((int) $data['student_id'], $scopedIds, true)) {
            return back()->with('error', 'That member is not assigned to you.');
        }

        return DB::transaction(function () use ($data) {
            $student = Student::findOrFail($data['student_id']);

            // Guard: you cannot refund more than the member has in credit. The
            // figure is their lifetime balance (deposits − refunds − meal cost),
            // computed with three aggregates rather than hydrating their rows.
            $costPerMeal = (new FinanceCalculator())->perMealRate(now()->format('Y-m'));

            $deposits = (float) $student->deposits()->whereNull('reversed_at')->sum('amount');
            $refunds = (float) $student->refunds()->whereNull('reversed_at')->sum('amount');
            $meals = (int) $student->entries()->sum('total_meals');
            $available = round($deposits - $refunds - ($meals * $costPerMeal), 2);

            if ($available <= 0) {
                return back()->with('error', "{$student->name} has no withdrawable balance to refund.");
            }

            if ((float) $data['amount'] > $available + 0.001) {
                return back()->with(
                    'error',
                    'The refund exceeds ' . $student->name . '\'s available balance of '
                    . Money::format($available) . '.'
                );
            }

            $reason = $data['reason'] ?? 'withdrawal';

            // The cash-out that takes the money back out of the pool.
            $tx = Transaction::create([
                'user_id' => auth()->id(),
                'student_id' => $student->id,
                'type' => 'out',
                'item' => 'Balance Refund for ' . $student->name,
                'amount' => $data['amount'],
                'category' => 'Member Refund',
                // transactions.payment_method is NOT NULL (default 'Cash'); an
                // explicit null would override the default and fail the insert.
                'payment_method' => ($data['payment_method'] ?? null) ?: 'Cash',
                'by_whom' => $student->name,
                'reason' => $data['notes'] ?? (Refund::REASONS[$reason] ?? null),
                'source' => 'refund',
            ]);

            Refund::create([
                'student_id' => $student->id,
                'amount' => $data['amount'],
                'reason' => $reason,
                'payment_method' => $data['payment_method'] ?? null,
                'recorded_by' => auth()->id(),
                'transaction_id' => $tx->id,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLogger::log('created', "refunded {$student->name}'s balance", $student, [
                'amount' => (float) $data['amount'],
                'reason' => $reason,
                'payment_method' => $data['payment_method'] ?? null,
            ], ['subject_label' => $student->name, 'institution_id' => $student->institution_id]);

            Notifier::refundRecorded($student, (float) $data['amount'], auth()->user());

            return redirect()->route('meals.refunds.index')->with('success', 'Refund recorded.');
        });
    }

    /**
     * Reverse a refund. The row is kept for history but flagged, and a matching
     * cash-in is posted so the ledger and the member's balance both rise back by
     * the refunded amount.
     */
    public function reverse(Request $request, Refund $refund)
    {
        if ($refund->isReversed()) {
            return back()->with('error', 'This refund is already reversed.');
        }

        return DB::transaction(function () use ($request, $refund) {
            $student = $refund->student;

            $reversal = Transaction::create([
                'user_id' => $request->user()->id,
                'student_id' => $student?->id,
                'type' => 'in',
                'item' => 'Refund reversal - ' . ($student?->name ?? 'member'),
                'amount' => $refund->amount,
                'category' => 'Refund Reversal',
                'reason' => 'Reversal of refund #' . $refund->id,
                'source' => 'refund',
            ]);

            $refund->update([
                'reversed_at' => now(),
                'reversed_by' => $request->user()->id,
                'reversal_transaction_id' => $reversal->id,
            ]);

            AuditLogger::log('reversed', 'reversed a refund for ' . ($student?->name ?? 'a member'), $refund, [
                'amount' => (float) $refund->amount,
                'reversal_transaction_id' => $reversal->id,
            ], ['subject_label' => $student?->name, 'institution_id' => $student?->institution_id]);

            return back()->with('success', 'Refund reversed. A matching cash-in was posted.');
        });
    }
}
