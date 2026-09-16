<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Institutional subsidies - funds injected by an authority rather than a
 * member. Tracked in their own table so they never blur with deposits.
 */
class SubsidyController extends Controller
{
    public function index(Request $request)
    {
        $institution = Institution::current();
        $source = (string) $request->query('source', '');
        $status = (string) $request->query('status', '');
        // Subsidy tracking is STRICTLY month-scoped, defaulting to now.
        $month = FinanceCalculator::resolveMonth($request->query('month'));

        // Guarantee the institution has its default funding sources to pick from.
        SubsidySource::ensureDefaults($institution?->id);

        $subsidies = Subsidy::query()
            ->with(['department:id,name,slug', 'student:id,name,roll', 'recorder:id,name', 'fundingSource'])
            ->forMonth($month)
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Totals split by apply mode so the "strict balance" funds are visible
        // separately from money that lands straight in the pool.
        $byMode = Subsidy::query()
            ->active()
            ->forMonth($month)
            ->selectRaw('apply_mode, COALESCE(SUM(amount), 0) as total')
            ->groupBy('apply_mode')
            ->pluck('total', 'apply_mode');

        // Per-source totals for the month, with each source's default share.
        $bySource = Subsidy::query()
            ->active()
            ->forMonth($month)
            ->selectRaw('source, COALESCE(SUM(amount), 0) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sources = SubsidySource::where(fn ($q) => $q->whereNull('institution_id')
                ->orWhere('institution_id', $institution?->id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (SubsidySource $s) => [
                'value' => $s->key ?: $s->name,
                'label' => $s->name,
                'name' => $s->name,
                'key' => $s->key,
                'percentage' => (float) ($s->percentage ?? 0),
                'month_total' => (float) ($bySource[$s->key] ?? 0),
            ])
            ->values();

        $monthTotal = (float) $subsidies->sum('amount');

        return Inertia::render('Meals/Subsidies/Index', [
            'subsidies' => $subsidies,
            'sources' => $sources,
            'applyModes' => collect(Subsidy::APPLY_MODES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'slug']),
            'students' => Student::active()->orderBy('name')->get(['id', 'name', 'roll']),
            'month' => $month,
            'months' => $this->monthOptions(),
            'sourceTotals' => $sources->map(fn ($s) => [
                'label' => $s['label'],
                'total' => (float) ($bySource[$s['key']] ?? 0),
                'percentage' => $s['percentage'],
            ])->values(),
            'totals' => [
                'all' => $monthTotal,
                'pool' => (float) ($byMode['pool'] ?? 0),
                'per_member' => (float) ($byMode['per_member'] ?? 0),
                'credit_behind' => (float) ($byMode['credit_behind'] ?? 0),
            ],
            'filters' => [
                'source' => $source,
                'status' => $status,
                'month' => $month,
            ],
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

    public function store(Request $request)
    {
        $data = $request->validate([
            // Source is a free key so admins can add their own funding sources.
            'source' => ['required', 'string', 'max:60'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_mode' => ['required', Rule::in(array_keys(Subsidy::APPLY_MODES))],
            'department_id' => ['nullable', 'exists:departments,id'],
            'student_id' => ['nullable', 'exists:students,id'],
            // Month-scoped, not a free date range.
            'period_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string'],
        ]);

        $institution = Institution::current();
        // Default the period to the current month when none was chosen.
        $data['period_month'] = $data['period_month'] ?? now()->format('Y-m');

        // Resolve a human label: a managed source's name, else the raw key.
        $managed = SubsidySource::where('key', $data['source'])
            ->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution?->id))
            ->first();
        $label = $data['source_label'] ?? $managed?->name ?? ucfirst(str_replace('_', ' ', $data['source']));

        return DB::transaction(function () use ($data, $institution, $label) {
            // Subsidy money enters the ledger as a cash-in so the pool reflects
            // it, but is tagged distinctly from personal deposits.
            $tx = Transaction::create([
                'user_id' => auth()->id(),
                'type' => 'in',
                'item' => 'Institutional Subsidy ('.$label.')',
                'amount' => $data['amount'],
                'category' => 'Subsidy',
                'by_whom' => $label,
                'reason' => $data['notes'] ?? null,
                'source' => 'subsidy',
            ]);

            $subsidy = Subsidy::create($data + [
                'institution_id' => $institution?->id,
                'recorded_by' => auth()->id(),
                'transaction_id' => $tx->id,
                'status' => 'active',
            ]);

            // Per-member subsidies are distributed as tagged deposit rows so
            // each member's statement shows where the money came from.
            if ($data['apply_mode'] === 'per_member') {
                $this->distributePerMember($subsidy);
            }

            return redirect()
                ->route('meals.subsidies.index')
                ->with('success', 'Subsidy recorded.');
        });
    }

    /**
     * Reverse a subsidy (e.g. a grant was overpaid or withdrawn). Keeps the row
     * but flips its status; the ledger gets a matching cash-out.
     */
    public function reverse(Subsidy $subsidy)
    {
        if ($subsidy->status === 'reversed') {
            return back()->with('error', 'This subsidy is already reversed.');
        }

        DB::transaction(function () use ($subsidy) {
            Transaction::create([
                'user_id' => auth()->id(),
                'type' => 'out',
                'item' => 'Subsidy reversal',
                'amount' => $subsidy->amount,
                'category' => 'Subsidy',
                'reason' => 'Reversal of subsidy #'.$subsidy->id,
                'source' => 'subsidy',
            ]);

            $subsidy->update(['status' => 'reversed']);

            // Remove any per-member deposit rows this subsidy created.
            Deposit::where('subsidy_id', $subsidy->id)->delete();
        });

        return back()->with('success', 'Subsidy reversed.');
    }

    /**
     * Split a subsidy amount evenly across the active roster and record a
     * tagged deposit for each member. Remainder cents go to the earliest
     * member so the total always matches exactly.
     */
    protected function distributePerMember(Subsidy $subsidy): void
    {
        $members = Student::active()->orderBy('id')->get(['id']);

        if ($members->isEmpty()) {
            return;
        }

        $total = (int) round((float) $subsidy->amount * 100);
        $count = $members->count();
        $each = intdiv($total, $count);
        $remainder = $total - ($each * $count);

        foreach ($members as $index => $member) {
            $cents = $each + ($index < $remainder ? 1 : 0);

            if ($cents <= 0) {
                continue;
            }

            Deposit::create([
                'student_id' => $member->id,
                'amount' => $cents / 100,
                'kind' => 'subsidy',
                'subsidy_id' => $subsidy->id,
                'payment_method' => 'Subsidy',
                'recorded_by' => auth()->id(),
                'transaction_id' => $subsidy->transaction_id,
                'notes' => 'Institutional subsidy allocation',
            ]);
        }
    }
}
