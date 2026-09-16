<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Vendor;
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

        $vendors = Vendor::query()
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
            'filters' => [
                'search' => $search,
                'category' => $category,
                'status' => $status,
            ],
            'totals' => [
                'vendors' => Vendor::count(),
                'active' => Vendor::active()->count(),
                'purchased' => (float) Transaction::query()
                    ->whereNotNull('vendor_id')
                    ->where('type', 'out')
                    ->sum('amount'),
            ],
        ]);
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
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // A blank form field arrives as "" rather than null. The column is NOT
        // NULL with a DB default, but an explicit empty string still fails the
        // constraint - so normalise it to 0 here.
        $data['opening_balance'] = filled($data['opening_balance'] ?? null)
            ? $data['opening_balance']
            : 0;

        // Same reasoning for the nullable string columns: store null, not "".
        foreach (['contact_person', 'phone', 'email', 'address', 'category', 'notes'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
