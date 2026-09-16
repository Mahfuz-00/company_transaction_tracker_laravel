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

class VendorController extends Controller
{
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
                $term = '%'.$search.'%';
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
            filename: 'vendors-'.now()->format('Ymd'),
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

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $vendor = Vendor::create($data);

        return redirect()
            ->route('meals.vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" added.");
    }

    public function update(Request $request, Vendor $vendor)
    {
        $data = $this->validated($request, $vendor);

        $vendor->update($data);

        return redirect()
            ->route('meals.vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" updated.");
    }

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
