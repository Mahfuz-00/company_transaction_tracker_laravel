<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Member roster API. Meals and balances are month-scoped, matching the web UI.
 */
class MemberApiController extends Controller
{
    public function index(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $finance = new FinanceCalculator();
        $breakdown = $finance->memberBreakdown($month)->keyBy('id');
        $rate = $finance->perMealRate($month);

        $query = Student::query()
            ->with('department:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('roll', 'like', "%{$search}%")))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('name');

        $perPage = min((int) $request->query('per_page', 25), 100);
        $members = $query->paginate($perPage)->withQueryString();

        $members->through(function (Student $member) use ($breakdown) {
            $row = $breakdown->get($member->id);

            return [
                'id' => $member->id,
                'name' => $member->name,
                'roll' => $member->roll,
                'department' => $member->department?->name,
                'status' => $member->status,
                'has_account' => (bool) $member->user_id,
                'month_meals' => (int) ($row['meals'] ?? 0),
                'breakfast' => (int) ($row['breakfast'] ?? 0),
                'lunch' => (int) ($row['lunch'] ?? 0),
                'dinner' => (int) ($row['dinner'] ?? 0),
                'meal_cost' => (float) ($row['meal_cost'] ?? 0),
                'deposited' => (float) ($row['deposited'] ?? 0),
                'subsidy_share' => (float) ($row['subsidy_share'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'is_due' => (bool) ($row['is_due'] ?? false),
            ];
        });

        return response()->json([
            'data' => $members->items(),
            'meta' => [
                'month' => $month,
                'per_meal_rate' => $rate,
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'pagination' => [
                    'current_page' => $members->currentPage(),
                    'last_page' => $members->lastPage(),
                    'per_page' => $members->perPage(),
                    'total' => $members->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Student $member)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();

        $row = $finance->memberBreakdown($month)->firstWhere('id', $member->id);

        $member->load([
            'department:id,name',
            'user:id,name,email',
            'manager:id,name,email',
            'deposits' => fn ($q) => $q->latest()->limit(20),
            'entries' => fn ($q) => $q->orderByDesc('date')->limit(30),
        ]);

        return response()->json([
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'roll' => $member->roll,
                'department' => $member->department?->name,
                'status' => $member->status,
                'managed_by' => $member->manager_label,
                'account' => $member->user ? [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                ] : null,
                'month' => $month,
                'figures' => $row,
                'recent_deposits' => $member->deposits->map(fn ($d) => [
                    'id' => $d->id,
                    'amount' => (float) $d->amount,
                    'kind' => $d->kind,
                    'date' => $d->created_at->toIso8601String(),
                ]),
                'recent_meals' => $member->entries->map(fn ($e) => [
                    'date' => $e->date?->toDateString(),
                    'breakfast' => $e->breakfast,
                    'lunch' => $e->lunch,
                    'dinner' => $e->dinner,
                    'total' => (int) $e->breakfast + (int) $e->lunch + (int) $e->dinner,
                ]),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'roll' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $data['institution_id'] = Institution::current()?->id;

        $member = Student::create($data);

        return response()->json(['data' => ['id' => $member->id, 'name' => $member->name]], 201);
    }

    public function update(Request $request, Student $member)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'roll' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $member->update($data);

        return response()->json(['data' => ['id' => $member->id, 'name' => $member->name]]);
    }

    public function destroy(Student $member)
    {
        if ($member->deposits()->exists() || $member->entries()->exists()) {
            return response()->json([
                'message' => 'This member has meal or deposit history and cannot be deleted.',
            ], 409);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }
}
