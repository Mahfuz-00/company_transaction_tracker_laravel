<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Institution;
use App\Models\MealExpense;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Vendor;
use App\Support\AuditLogger;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * REFERENCE & CONFIGURATION API — the remaining staff-side parity surface.
 *
 * Covers the modules a manager needs on mobile but that are not money-moving
 * ledgers: vendors, departments, expenses, refunds and the workspace's own
 * settings (identity, invite code, currency, subsidy sources).
 *
 * ACCESS is enforced at the ROUTE level, matching the web exactly:
 *   - vendors / departments / expenses / refunds : `vendors.view`,
 *     `departments.view`, `meals.expense`, `meals.deposit`
 *   - institution settings                       : `institution.view` / `.manage`
 *   - currency / subsidy sources                 : `currency.*` / `subsidies.manage`
 *
 * Every write stamps `institution_id` from the SERVER's resolved tenant, never
 * from the request body — the tenant-isolation rule applied to writes.
 */
class ReferenceApiController extends Controller
{
    /* ------------------------------------------------------------------ *
     * Vendors
     * ------------------------------------------------------------------ */

    /** GET /api/vendors?search=&category=&status=&per_page= */
    public function vendors(Request $request)
    {
        $institution = Institution::current();

        // Guarantee the institution's own hub vendor exists (mirrors the web list).
        $institution?->ensureHubVendor();

        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $status = (string) $request->query('status', '');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $vendors = Vendor::query()
            ->withCount('expenses')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('contact_person', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('is_institution_hub')
            ->orderBy('name')
            ->paginate($perPage);

        // A SECOND aggregate instead of one query per row — the same N+1 fix the
        // web index uses.
        $outstanding = Vendor::query()
            ->withSum(['expenses as unpaid_total' => fn ($q) => $q->where('payment_status', 'unpaid')], 'amount')
            ->pluck('unpaid_total', 'id');

        $vendors->through(fn (Vendor $vendor) => [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'category' => $vendor->category,
            'category_label' => Vendor::CATEGORIES[$vendor->category] ?? $vendor->category,
            'contact_person' => $vendor->contact_person,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'recurrence' => $vendor->recurrence,
            'status' => $vendor->status,
            'is_hub' => (bool) $vendor->is_institution_hub,
            'opening_balance' => (float) $vendor->opening_balance,
            'outstanding_balance' => round(
                (float) $vendor->opening_balance + (float) ($outstanding[$vendor->id] ?? 0),
                2,
            ),
            'expenses_count' => (int) $vendor->expenses_count,
        ]);

        return response()->json([
            'data' => $vendors->items(),
            'meta' => [
                'categories' => collect(Vendor::CATEGORIES)
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'recurrences' => collect(Vendor::RECURRENCES)
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'pagination' => [
                    'current_page' => $vendors->currentPage(),
                    'last_page' => $vendors->lastPage(),
                    'per_page' => $vendors->perPage(),
                    'total' => $vendors->total(),
                ],
            ],
        ]);
    }

    /** POST /api/vendors */
    public function storeVendor(Request $request)
    {
        $institution = Institution::current();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_keys(Vendor::CATEGORIES))],
            'recurrence' => ['nullable', Rule::in(array_keys(Vendor::RECURRENCES))],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $vendor = Vendor::create($data + [
            'institution_id' => $institution?->id,
            'slug' => Str::slug($data['name']).'-'.Str::random(5),
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'data' => ['id' => $vendor->id, 'name' => $vendor->name],
            'message' => 'Vendor created.',
        ], 201);
    }

    /* ------------------------------------------------------------------ *
     * Departments
     * ------------------------------------------------------------------ */

    /** GET /api/departments?search=&per_page= */
    public function departments(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min((int) $request->query('per_page', 25), 100);

        $departments = Department::query()
            ->withCount([
                'students',
                'students as active_students_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($perPage);

        $departments->through(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'description' => $d->description,
            'students_count' => (int) $d->students_count,
            'active_students_count' => (int) $d->active_students_count,
        ]);

        return response()->json([
            'data' => $departments->items(),
            'meta' => [
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                ],
            ],
        ]);
    }

    /** POST /api/departments */
    public function storeDepartment(Request $request)
    {
        $institution = Institution::current();

        // Name/slug uniqueness scoped to THIS institution — a plain Rule::unique
        // checks every row in the table (it bypasses the model's tenant scope), so
        // two institutions could not both have a "Kitchen".
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where('institution_id', $institution?->id)],
            'slug' => ['nullable', 'string', 'max:255',
                Rule::unique('departments', 'slug')->where('institution_id', $institution?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department = Department::create($data + [
            'institution_id' => $institution?->id,
            'slug' => $data['slug'] ?? Str::slug($data['name']),
        ]);

        return response()->json([
            'data' => ['id' => $department->id, 'name' => $department->name],
            'message' => 'Department created.',
        ], 201);
    }

    /* ------------------------------------------------------------------ *
     * Expenses (Cash Out)
     * ------------------------------------------------------------------ */

    /** GET /api/expenses?month=&vendor=&per_page= */
    public function expenses(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $perPage = min((int) $request->query('per_page', 25), 100);

        // `created_at` is the only date an expense carries (there is no
        // `expense_date` column); the month filter therefore applies to it.
        $expenses = MealExpense::query()
            ->with(['vendor:id,name', 'recorder:id,name'])
            ->whereYear('created_at', substr($month, 0, 4))
            ->whereMonth('created_at', substr($month, 5, 2))
            ->when($request->filled('vendor'), fn ($q) => $q->where('vendor_id', $request->query('vendor')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $expenses->through(fn (MealExpense $e) => [
            'id' => $e->id,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'category' => $e->category,
            'vendor' => $e->vendor?->name,
            'vendor_id' => $e->vendor_id,
            'payment_status' => $e->payment_status,
            'recorded_by' => $e->recorder?->name,
            'is_reversed' => $e->isReversed(),
            'date' => $e->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'month' => $month,
                // Reversed expenses must not inflate the month total.
                'total' => (float) MealExpense::query()
                    ->active()
                    ->whereYear('created_at', substr($month, 0, 4))
                    ->whereMonth('created_at', substr($month, 5, 2))
                    ->sum('amount'),
                'pagination' => [
                    'current_page' => $expenses->currentPage(),
                    'last_page' => $expenses->lastPage(),
                    'per_page' => $expenses->perPage(),
                    'total' => $expenses->total(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/expenses — record a kitchen expense.
     *
     * Mirrors MealExpenseController::store exactly: a ledger cash-out is written
     * FIRST (every pool/expense total in the platform reads from transactions),
     * then the module row links to it. Both happen in one transaction so the two
     * can never disagree.
     */
    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            // Scoped to the caller's institution: a foreign vendor id is a 422.
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('institution_id', Institution::current()?->id)],
            'payment_status' => ['nullable', Rule::in(['paid', 'unpaid', 'partial'])],
        ]);

        $vendor = ! empty($data['vendor_id']) ? Vendor::find($data['vendor_id']) : null;

        $expense = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $vendor, $request) {
            $tx = \App\Models\Transaction::create([
                'user_id' => $request->user()->id,
                'type' => 'out',
                'item' => $data['description'] ?? 'Meal Expense',
                'amount' => $data['amount'],
                'category' => $data['category'] ?? 'Meal Expense',
                'payee' => $vendor?->name,
                'vendor_id' => $vendor?->id,
                'source' => 'meal_expense',
            ]);

            return MealExpense::create([
                'transaction_id' => $tx->id,
                'vendor_id' => $vendor?->id,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'amount' => $data['amount'],
                'payment_status' => $data['payment_status'] ?? 'paid',
                'recorded_by' => $request->user()->id,
            ]);
        });

        AuditLogger::log('created', 'recorded an expense', $expense, [
            'description' => $data['description'] ?? null,
            'amount' => (float) $data['amount'],
            'vendor' => $vendor?->name,
        ], ['subject_label' => $data['description'] ?? 'Expense',
            'institution_id' => $expense->institution_id]);

        return response()->json([
            'data' => ['id' => $expense->id, 'amount' => (float) $expense->amount],
            'message' => 'Expense recorded as a cash-out transaction.',
        ], 201);
    }

    /* ------------------------------------------------------------------ *
     * Workspace settings
     * ------------------------------------------------------------------ */

    /** GET /api/settings/institution — identity, invite code, currency. */
    public function institutionSettings(Request $request)
    {
        $institution = Institution::current();

        if (! $institution) {
            return response()->json(['message' => 'No institution is selected for this session.'], 409);
        }

        return response()->json([
            'data' => [
                'id' => $institution->id,
                'name' => $institution->name,
                'subtitle' => $institution->subtitle,
                'type' => $institution->type,
                'type_label' => $institution->typeLabel(),
                'logo_url' => $institution->logoUrl(),
                'contact_email' => $institution->contact_email,
                'contact_phone' => $institution->contact_phone,
                // The tenant-mapping key a member types on the public signup form.
                'invite_code' => $institution->invite_code,
                'currency' => $institution->currencySettings(),
                'terms' => $institution->terminologyMap(),
                'theme' => $institution->themeSettings(),
                'subsidy_mode' => $institution->subsidy_mode,
                'member_limit' => $institution->member_limit,
                'subscription' => [
                    'plan' => $institution->subscription_plan,
                    'status' => $institution->subscription_status,
                    'amount' => $institution->subscription_amount !== null
                        ? (float) $institution->subscription_amount
                        : null,
                ],
            ],
            'meta' => [
                // What the caller may change, so the app can hide what it cannot.
                'can_manage' => $request->user()->can('institution.manage'),
            ],
        ]);
    }

    /** PUT /api/settings/institution */
    public function updateInstitution(Request $request)
    {
        $institution = Institution::current();

        if (! $institution) {
            return response()->json(['message' => 'No institution is selected for this session.'], 409);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'subsidy_mode' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        // `logo` is the uploaded file (handled above), not a column.
        unset($data['logo']);
        $institution->update($data);

        return response()->json([
            'data' => [
                'id' => $institution->id,
                'name' => $institution->fresh()->name,
                'logo_url' => $institution->fresh()->logoUrl(),
            ],
            'message' => 'Institution settings updated.',
        ]);
    }

    /** GET /api/settings/subsidy-sources */
    public function subsidySources(Request $request)
    {
        // SubsidySource carries the BelongsToInstitution scope, so this is
        // automatically confined to the caller's workspace.
        $sources = SubsidySource::query()->orderBy('name')->get();

        return response()->json([
            'data' => $sources->map(fn (SubsidySource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'key' => $s->key,
                'percentage' => $s->percentage !== null ? (float) $s->percentage : null,
                'description' => $s->description,
                'is_active' => (bool) $s->is_active,
            ])->values(),
            'meta' => [
                'can_manage' => $request->user()->can('subsidies.manage'),
                'platform_sources' => Subsidy::SOURCES,
            ],
        ]);
    }
}
