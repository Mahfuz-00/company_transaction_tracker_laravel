<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Institutional subsidy API. Subsidies are month-scoped and tracked separately
 * from member deposits.
 */
class SubsidyApiController extends Controller
{
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $institution = Institution::current();

        $subsidies = Subsidy::query()
            ->with(['department:id,name', 'student:id,name', 'recorder:id,name', 'fundingSource'])
            ->forMonth($month)
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->query('source')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $subsidies->through(fn (Subsidy $s) => [
            'id' => $s->id,
            'source' => $s->source,
            'source_name' => $s->source_name,
            'amount' => (float) $s->amount,
            'percentage' => $s->percentage !== null ? (float) $s->percentage : null,
            'apply_mode' => $s->apply_mode,
            'period_month' => $s->period_month,
            'status' => $s->status,
            'scope' => $s->department?->name ?? $s->student?->name ?? 'Whole institution',
            'recorded_by' => $s->recorder?->name,
            'notes' => $s->notes,
            'date' => $s->created_at->toIso8601String(),
        ]);

        $total = (float) Subsidy::query()->active()->forMonth($month)->sum('amount');

        return response()->json([
            'data' => $subsidies->items(),
            'meta' => [
                'month' => $month,
                'total' => $total,
                'source_totals' => $this->sourceTotals($month),
                'apply_modes' => Subsidy::APPLY_MODES,
                'pagination' => [
                    'current_page' => $subsidies->currentPage(),
                    'last_page' => $subsidies->lastPage(),
                    'total' => $subsidies->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:60'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_mode' => ['required', Rule::in(array_keys(Subsidy::APPLY_MODES))],
            'department_id' => ['nullable', 'exists:departments,id'],
            'student_id' => ['nullable', 'exists:students,id'],
            'period_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $institution = Institution::current();
        $data['period_month'] = $data['period_month'] ?? now()->format('Y-m');

        $managed = SubsidySource::where('key', $data['source'])->first();
        $label = $data['source_label'] ?? $managed?->name
            ?? ucfirst(str_replace('_', ' ', $data['source']));

        $subsidy = DB::transaction(function () use ($data, $institution, $label, $request) {
            $tx = Transaction::create([
                'user_id' => $request->user()->id,
                'type' => 'in',
                'item' => 'Institutional Subsidy ('.$label.')',
                'amount' => $data['amount'],
                'category' => 'Subsidy',
                'by_whom' => $label,
                'reason' => $data['notes'] ?? null,
                'source' => 'subsidy',
            ]);

            return Subsidy::create($data + [
                'institution_id' => $institution?->id,
                'recorded_by' => $request->user()->id,
                'transaction_id' => $tx->id,
                'status' => 'active',
            ]);
        });

        return response()->json([
            'data' => ['id' => $subsidy->id, 'amount' => (float) $subsidy->amount],
            'message' => 'Subsidy recorded.',
        ], 201);
    }

    /** The funding sources an admin has configured for this institution. */
    public function sources()
    {
        $institution = Institution::current();
        SubsidySource::ensureDefaults($institution?->id);

        $sources = SubsidySource::query()
            ->where(fn ($q) => $q->whereNull('institution_id')
                ->orWhere('institution_id', $institution?->id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'key', 'percentage', 'description'])
            ->map(fn (SubsidySource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'key' => $s->key,
                'percentage' => $s->percentage !== null ? (float) $s->percentage : null,
                'description' => $s->description,
            ]);

        return response()->json(['data' => $sources]);
    }

    /** Per-source breakdown for a month, including each source's real share. */
    protected function sourceTotals(string $month): array
    {
        $bySource = Subsidy::query()
            ->active()
            ->forMonth($month)
            ->selectRaw('source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $total = (float) $bySource->sum('total');

        return $bySource->map(fn ($row, $key) => [
            'key' => $key,
            'total' => (float) $row->total,
            'entries' => (int) $row->entries,
            'actual_percentage' => $total > 0 ? round(($row->total / $total) * 100, 2) : 0.0,
        ])->values()->all();
    }
}
