<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSubsidyRequest;
use App\Http\Resources\SubsidyResource;
use App\Models\Institution;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Institutional subsidy API. Subsidies are month-scoped and tracked separately
 * from member deposits.
 *
 * Subsidy money is NEVER mixed into a member's own deposited funds: it is a
 * separate pool that only tops up a shortfall (see Subsidy::creditAvailableFor).
 * Like deposits, each subsidy writes a matching ledger transaction so the pool
 * balance stays correct.
 */
class SubsidyApiController extends Controller
{
    /** Paginated subsidies for a month, with per-source totals. */
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

        // Only ACTIVE subsidies count toward the month total.
        $total = (float) Subsidy::query()->active()->forMonth($month)->sum('amount');

        return response()->json([
            'data' => SubsidyResource::collection($subsidies->items())->resolve(),
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

    /** Record a subsidy (and its matching ledger row). */
    public function store(StoreSubsidyRequest $request)
    {
        // Tenant-scoped department/student references validated up-front.
        $data = $request->validated();

        $institution = Institution::current();
        $data['period_month'] = $data['period_month'] ?? now()->format('Y-m');

        // Resolve a human label: an explicit one wins, else the managed source's
        // name, else a title-cased version of the raw key.
        $managed = SubsidySource::where('key', $data['source'])->first();
        $label = $data['source_label'] ?? $managed?->name
            ?? ucfirst(str_replace('_', ' ', $data['source']));

        $subsidy = DB::transaction(function () use ($data, $institution, $label, $request) {
            $tx = Transaction::create([
                'user_id' => $request->user()->id,
                'type' => 'in',
                'item' => 'Institutional Subsidy (' . $label . ')',
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
            // Institution-specific sources PLUS the shared (null-institution)
            // defaults, so every workspace sees the platform baseline.
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

    /**
     * Per-source breakdown for a month, including each source's REAL share
     * (its total as a percentage of all subsidy money that month).
     */
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
            // Guard against division by zero on a month with no subsidies.
            'actual_percentage' => $total > 0 ? round(($row->total / $total) * 100, 2) : 0.0,
        ])->values()->all();
    }
}
