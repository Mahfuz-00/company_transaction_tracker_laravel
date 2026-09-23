<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ActivityLogController;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Support\Money;
use App\Support\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Vendor & supplier management, and the spend ledger they carry.
 *
 * WHAT THIS IS
 * ------------
 * A "vendor" is anyone the institution buys from - a grocery shop, a gas
 * supplier, or the institution's own internal hub. Vendors are the counterparty
 * for money-out: meal expenses may point at one, and these screens roll those
 * expenses (and their ledger transactions) up into spend and outstanding
 * figures.
 *
 * MONEY MATH - "OUTSTANDING"
 * -------------------------
 * The figure the list shows is NOT just the sum of purchases:
 *     outstanding = opening_balance + sum(expenses where payment_status = 'unpaid')
 * i.e. whatever the institution already owed the vendor when they were onboarded,
 * PLUS everything since bought on credit that has not been marked paid. Purchases
 * paid at the time are deliberately excluded - only genuinely outstanding money
 * stays on the books.
 *
 * TENANCY
 * -------
 * `Vendor` uses `BelongsToInstitution`, so all reads and writes are confined to
 * the active institution automatically, and `institution_id` on create is pinned
 * to `Institution::current()` server-side.
 *
 * ROUTE-MODEL BINDING
 * -------------------
 * `Vendor::getRouteKeyName()` returns `slug`, so a method signature of
 * `Vendor $vendor` resolves from the URL SLUG (not an id) - and because binding
 * runs through the tenant scope, a slug belonging to another institution 404s.
 */
class VendorController extends Controller
{
    /**
     * Paginated vendor list with search / category / status filters, plus each
     * row's outstanding balance.
     *
     * Performance note: the outstanding totals are gathered in a SECOND aggregate
     * query (see below) instead of calling `$vendor->outstandingBalance()` per
     * row, which would fire one query per vendor - the classic N+1 that makes a
     * 15-row page cost 15 extra round-trips.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $status = (string) $request->query('status', '');

        $institution = Institution::current();
        // Guarantee there is always a hub vendor representing the institution
        // itself, so it appears in the ecosystem from day one.
        if ($institution) {
            $institution->ensureHubVendor();
        }

        $vendors = Vendor::query()
            // Hub first - it is the platform's primary vendor.
            ->orderByDesc('is_institution_hub')
            ->withCount('expenses')
            ->withSum(['transactions as total_purchased' => fn ($q) => $q->where('type', 'out')], 'amount')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('contact_person', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('is_institution_hub')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Outstanding balances need a second aggregate; doing it here keeps the
        // list query count constant instead of one query per row.
        $outstanding = Vendor::query()
            ->withSum(['expenses as unpaid_total' => fn ($q) => $q->where('payment_status', 'unpaid')], 'amount')
            ->pluck('unpaid_total', 'id');

        // Paginator::through() maps each row in place, so the derived attribute
        // attaches to the paginated items WITHOUT discarding the pagination
        // metadata (total, per-page, links) the view needs.
        $vendors->through(function (Vendor $vendor) use ($outstanding) {
            $vendor->setAttribute(
                'outstanding_balance',
                round((float) $vendor->opening_balance + (float) ($outstanding[$vendor->id] ?? 0), 2)
            );

            return $vendor;
        });

        return Inertia::render('Meals/Vendors/Index', [
            'vendors' => $vendors,
            'categories' => Vendor::CATEGORIES,
            'recurrences' => collect(Vendor::RECURRENCES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'filters' => [
                'search' => $search,
                'category' => $category,
                'status' => $status,
            ],
            'totals' => [
                'vendors' => Vendor::count(),
                'active' => Vendor::active()->count(),
                'recurring' => Vendor::recurring()->count(),
                'purchased' => (float) Transaction::query()
                    ->whereNotNull('vendor_id')
                    ->where('type', 'out')
                    ->sum('amount'),
            ],
        ]);
    }

    /**
     * Purchase history for one vendor: every recorded expense tied to it, with
     * lifetime and month-to-date totals. Returned as JSON so the Purchase
     * History tab can load on demand without a full page visit.
     */
    public function history(Vendor $vendor)
    {
        $rows = $vendor->purchaseHistory(200);

        return response()->json([
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'category' => $vendor->category_label,
                'recurrence' => $vendor->recurrence_label,
                'status' => $vendor->status,
                'is_institution_hub' => (bool) $vendor->is_institution_hub,
            ],
            'history' => $rows,
            'totals' => [
                'orders' => count($rows),
                'purchased' => round(array_sum(array_column($rows, 'amount')), 2),
                'outstanding' => $vendor->outstandingBalance(),
            ],
        ]);
    }

    /**
     * Export the vendor ledger (spend per supplier).
     */
    public function export(Request $request)
    {
        $vendors = Vendor::query()
            ->withSum(['transactions as total_purchased' => fn ($q) => $q->where('type', 'out')], 'amount')
            ->orderByDesc('is_institution_hub')
            ->orderBy('name')
            ->get()
            ->map(fn (Vendor $v) => [
                'name' => $v->name,
                'category' => $v->category_label,
                'recurrence' => $v->recurrence_label ?? 'On demand',
                'status' => ucfirst($v->status),
                'contact_person' => $v->contact_person,
                'phone' => $v->phone,
                'total_purchased' => (float) ($v->total_purchased ?? 0),
                'role' => $v->is_institution_hub ? 'Institution hub' : 'Vendor',
            ]);

        $format = $request->query('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'vendors-' . now()->format('Ymd'),
            title: 'Vendor & Supplier Ledger',
            columns: [
                'name' => 'Vendor',
                'role' => 'Role',
                'category' => 'Category',
                'recurrence' => 'Recurrence',
                'status' => 'Status',
                'contact_person' => 'Contact',
                'phone' => 'Phone',
                'total_purchased' => 'Total Purchased',
            ],
            rows: $vendors,
            meta: [
                'Institution' => Institution::current()?->name ?? '-',
                'Vendors' => $vendors->count(),
                'Total purchased' => Money::format((float) $vendors->sum('total_purchased')),
            ],
            formatter: fn ($value, $key) => $key === 'total_purchased' ? Money::format((float) $value) : $value,
        );

        ActivityLogController::recordExport($request, 'Vendor & Supplier Ledger', ['format' => $format]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
    }

    /**
     * Create a vendor.
     *
     * `institution_id` and a normalised `opening_balance` are filled in by
     * `validated()` first, so the mass-assignable payload is complete before
     * `create()` runs.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $vendor = Vendor::create($data);

        return redirect()
            ->route('meals.vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" added.");
    }

    /**
     * Update a vendor.
     *
     * `$vendor` is resolved by ROUTE-MODEL BINDING on the slug, running through
     * the tenant scope - a vendor from another institution can never be reached
     * here. `validated()` receives the model so it knows this is an update and
     * skips re-stamping `institution_id`.
     */
    public function update(Request $request, Vendor $vendor)
    {
        $data = $this->validated($request, $vendor);

        $vendor->update($data);

        return redirect()
            ->route('meals.vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" updated.");
    }

    /**
     * Delete a vendor - unless it has financial history.
     *
     * A vendor referenced by any expense or ledger transaction is a historical
     * record: deleting it would orphan those rows and skew past reports. Rather
     * than cascade a deletion through financial history, the user is told to mark
     * the vendor INACTIVE instead.
     */
    public function destroy(Vendor $vendor)
    {
        // A vendor with purchase history is a financial record; removing it
        // would orphan the expenses that reference it.
        if ($vendor->expenses()->exists() || $vendor->transactions()->exists()) {
            return back()->with(
                'error',
                "Cannot delete \"{$vendor->name}\" - purchase history exists. Mark them inactive instead."
            );
        }

        $name = $vendor->name;
        $vendor->delete();

        return redirect()
            ->route('meals.vendors.index')
            ->with('success', "Vendor \"{$name}\" deleted.");
    }

    /**
     * Shared validation + normalisation for store() and update().
     *
     * Beyond the plain rules, this fixes two "web form" realities a mobile
     * developer would not expect:
     *   1. an optional field left blank arrives as `""`, not null - and an empty
     *      string violates the NOT NULL columns, so blanks are coerced to null
     *      (and `opening_balance`, a numeric column, to 0);
     *   2. `institution_id` is stamped here for a NEW vendor only, so an edit can
     *      never move a supplier into another tenant.
     */
    protected function validated(Request $request, ?Vendor $vendor = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(Vendor::CATEGORIES)],
            'recurrence' => ['nullable', Rule::in(array_keys(Vendor::RECURRENCES))],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'recurring_amount' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // A new vendor always belongs to the current institution.
        if ($vendor === null) {
            $data['institution_id'] = Institution::current()?->id;
        }

        // A blank form field arrives as "" rather than null. The column is NOT
        // NULL with a DB default, but an explicit empty string still fails the
        // constraint - so normalise it to 0 here.
        $data['opening_balance'] = filled($data['opening_balance'] ?? null)
            ? $data['opening_balance']
            : 0;

        // Same reasoning for the nullable string columns: store null, not "".
        foreach (['contact_person', 'phone', 'email', 'address', 'category', 'recurrence', 'lead_time_days', 'recurring_amount', 'notes'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
