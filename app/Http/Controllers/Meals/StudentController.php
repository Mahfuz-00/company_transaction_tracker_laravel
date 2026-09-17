<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Support\FinanceCalculator;
use App\Support\Money;
use App\Support\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $department = (string) $request->query('department', '');
        $status = (string) $request->query('status', '');
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $institution = Institution::current();

        // Manager scoping: a Meal Manager only sees the members assigned directly
        // under them; admins see the whole institution. null = unrestricted.
        $scopedIds = $request->user()->scopedStudentIds();

        $students = Student::query()
            // Scope to the active institution in a multi-tenant deployment.
            ->when($institution, fn ($q) => $q->where(function ($sub) use ($institution) {
                $sub->where('institution_id', $institution->id)
                    ->orWhereNull('institution_id');
            }))
            // Only the members this manager is responsible for.
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('id', $scopedIds))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('roll', 'like', $term);
                });
            })
            ->when($department !== '', fn ($q) => $q->where('department_id', $department))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        /* -------------------------------------------------------------- *
         * Month-scoped figures.
         *
         * Meals and balances are for the SELECTED MONTH, not lifetime - the
         * roster shows the current period by default.
         * -------------------------------------------------------------- */
        $finance = new FinanceCalculator();
        $breakdown = $finance->memberBreakdown($month)->keyBy('id');
        $costPerMeal = $finance->perMealRate($month);

        $students->through(function (Student $student) use ($breakdown, $costPerMeal) {
            $row = $breakdown->get($student->id);

            $student->setAttribute('month_meals', (int) ($row['meals'] ?? 0));
            $student->setAttribute('breakfast', (int) ($row['breakfast'] ?? 0));
            $student->setAttribute('lunch', (int) ($row['lunch'] ?? 0));
            $student->setAttribute('dinner', (int) ($row['dinner'] ?? 0));
            $student->setAttribute('total_meals', (int) ($row['meals'] ?? 0));
            $student->setAttribute('total_deposits', (float) ($row['deposited'] ?? 0));
            $student->setAttribute('meal_cost', (float) ($row['meal_cost'] ?? 0));
            $student->setAttribute('subsidy_share', (float) ($row['subsidy_share'] ?? 0));
            $student->setAttribute('balance', (float) ($row['balance'] ?? 0));
            $student->setAttribute('cost_per_meal', $costPerMeal);
            $student->setAttribute('manager_name', $student->manager_label);
            $student->setAttribute('is_invited', (bool) $student->user_id);

            return $student;
        });

        return Inertia::render('Meals/Students/Index', [
            'students' => $students,
            'departments' => Department::orderBy('name')->get(['id', 'name', 'slug']),
            'users' => $this->linkableUsers(),
            'costPerMeal' => $costPerMeal,
            'month' => $month,
            'months' => $this->monthOptions(),
            'rateBreakdown' => $this->rateBreakdown($finance, $month),
            'managers' => $this->managerOptions(),
            'filters' => [
                'search' => $search,
                'department' => $department,
                'status' => $status,
                'month' => $month,
            ],
        ]);
    }

    /**
     * The per-meal rate calculation, exposed in pieces so the UI can explain
     * exactly how the number was reached:
     *
     *     rate = total expense / total meals
     */
    protected function rateBreakdown(FinanceCalculator $finance, string $month): array
    {
        $snapshot = $finance->monthSnapshot($month);

        return [
            'total_expense' => $snapshot['expenses'],
            'total_meals' => $snapshot['meals'],
            'per_meal_rate' => $snapshot['per_meal_rate'],
            'daily_meals' => $snapshot['daily_meals'],
            'daily_cost' => $snapshot['daily_cost'],
            'meals_per_member' => $snapshot['meals_per_member'],
            'cost_per_member' => $snapshot['cost_per_member'],
            'subsidy_coverage_pct' => $snapshot['subsidy_coverage_pct'],
            'member_funded_pct' => $snapshot['member_funded_pct'],
            'month_label' => $snapshot['label'],
        ];
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

    /**
     * Creation happens in a modal on the index screen.
     */
    public function create()
    {
        return redirect()->route('meals.students.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        // Stamp the active institution so the record is scoped correctly.
        $data['institution_id'] = Institution::current()?->id;

        /*
         * MANAGED-BY vs. USER-ACCOUNT separation.
         *
         * `user_id`  = the member's OWN login account (set later via Invite).
         * `manager_id` = the Meal Manager / Admin who OVERSEES this member.
         *
         * A member must never be left "managed by themselves": if the form
         * somehow sends manager_id equal to the member's own user_id, we drop it
         * so the roster cannot show the member as their own manager.
         */
        if (! empty($data['manager_id']) && ! empty($data['user_id'])
            && (int) $data['manager_id'] === (int) $data['user_id']) {
            $data['manager_id'] = null;
        }

        $student = Student::create($data);

        // Redirect back to the INDEX (not create), so the roster table - the
        // list view - is what the user sees after adding a member. This is the
        // fix for "adding members only shows the form, not the list".
        return redirect()
            ->route('meals.students.index')
            ->with('success', "{$student->name} added to the roster.");
    }

    public function show(Request $request, Student $student)
    {
        // A Meal Manager may only open a member assigned to them.
        if (! $this->canAccessStudent($request, $student)) {
            return redirect()
                ->route('meals.students.index')
                ->with('error', 'That member is not assigned to you.');
        }

        $student->load([
            'department:id,name,slug',
            'user:id,name,email',
            'manager:id,name,email',
        ]);

        // Everything on the detail page is month-scoped, matching the roster.
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();
        $costPerMeal = $finance->perMealRate($month);

        $row = $finance->memberBreakdown($month)->firstWhere('id', $student->id);

        // Recent activity for context, independent of the month figures.
        $student->load([
            'deposits' => fn ($q) => $q->latest()->limit(20),
            'entries' => fn ($q) => $q->orderByDesc('date')->limit(30),
        ]);

        return Inertia::render('Meals/Students/Show', [
            'student' => $student,
            'managerName' => $student->manager_label,
            'month' => $month,
            'months' => $this->monthOptions(),
            'costPerMeal' => $costPerMeal,
            'balance' => (float) ($row['balance'] ?? 0),
            'breakdown' => $row ?? [
                'meals' => 0, 'meal_cost' => 0, 'deposited' => 0,
                'subsidy_share' => 0, 'balance' => 0, 'breakfast' => 0,
                'lunch' => 0, 'dinner' => 0,
            ],
            'totalMeals' => (int) ($row['meals'] ?? 0),
            'totalDeposits' => (float) ($row['deposited'] ?? 0),
        ]);
    }

    /**
     * Excel / PDF export of the member roster for a month.
     */
    public function export(Request $request)
    {
        $month = FinanceCalculator::resolveMonth($request->query('month'));
        $finance = new FinanceCalculator();
        $costPerMeal = $finance->perMealRate($month);

        $rows = $finance->memberBreakdown($month)->map(fn ($row) => [
            'name' => $row['name'],
            'roll' => $row['roll'],
            'department' => $row['department'],
            'status' => ucfirst((string) $row['status']),
            'meals' => $row['meals'],
            'meal_cost' => $row['meal_cost'],
            'deposited' => $row['deposited'],
            'balance' => $row['balance'],
        ]);

        $format = $request->query('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'members-'.$month.'-'.now()->format('Ymd'),
            title: 'Member Roster',
            columns: [
                'name' => 'Name',
                'roll' => 'Roll ID',
                'department' => 'Group',
                'status' => 'Status',
                'meals' => 'Meals',
                'meal_cost' => 'Meal Cost',
                'deposited' => 'Deposited',
                'balance' => 'Balance',
            ],
            rows: $rows,
            meta: [
                'Institution' => Institution::current()?->name ?? '-',
                'Period' => \Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y'),
                'Members' => $rows->count(),
                'Per-meal rate' => Money::format($costPerMeal, null, false),
            ],
            formatter: fn ($value, $key) => in_array($key, ['meal_cost', 'deposited', 'balance'], true)
                ? Money::format((float) $value)
                : $value,
        );

        \App\Http\Controllers\ActivityLogController::recordExport($request, 'Member Roster', ['format' => $format]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
    }

    /**
     * Invite a member to create their own login. Creates no password - it sends
     * a signed email link so the member sets their own.
     */
    public function invite(Request $request, Student $student)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($student->user_id) {
            return back()->with('error', 'This member already has a linked account.');
        }

        if (User::where('email', $data['email'])->exists()) {
            return back()->with('error', 'A user with that email already exists.');
        }

        // Reuse the shared invitation flow, scoped to this member record.
        $invitationController = app(\App\Http\Controllers\MemberInvitationController::class);

        return $invitationController->store($request->merge([
            'email' => $data['email'],
            'name' => $student->name,
            'student_id' => $student->id,
            // Members get the view-only role by default.
            'role' => 'Member',
        ]));
    }

    public function edit(Student $student)
    {
        return redirect()->route('meals.students.index');
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate($this->rules($student));

        // Backfill institution scope for records created before multi-tenancy.
        if (blank($student->institution_id)) {
            $data['institution_id'] = Institution::current()?->id;
        }

        // Same guard as store(): a member can never be their own manager. Compare
        // against the incoming user_id (or the existing one when not resubmitted).
        $effectiveUserId = $data['user_id'] ?? $student->user_id;
        if (! empty($data['manager_id']) && ! empty($effectiveUserId)
            && (int) $data['manager_id'] === (int) $effectiveUserId) {
            $data['manager_id'] = null;
        }

        $student->update($data);

        return redirect()
            ->route('meals.students.index')
            ->with('success', "{$student->name} updated.");
    }

    public function destroy(Student $student)
    {
        // A student with recorded money or meals is financial history.
        if ($student->deposits()->exists() || $student->entries()->exists()) {
            return back()->with(
                'error',
                "Cannot delete \"{$student->name}\" - meal or deposit history exists. Mark them inactive instead."
            );
        }

        $name = $student->name;
        $student->delete();

        return redirect()
            ->route('meals.students.index')
            ->with('success', "Student \"{$name}\" removed.");
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * May the acting user open this member record?
     *
     * Admins: any member in their institution. Meal Manager: only a member
     * assigned to them (students.manager_id). Members: only themselves.
     */
    protected function canAccessStudent(Request $request, Student $student): bool
    {
        $user = $request->user();

        if ($user->isSuperAdmin() || $user->isInstitutionAdmin()) {
            return $student->institution_id === null
                || $user->belongsToInstitution($student->institution_id);
        }

        if ($user->hasRole('Meal Manager')) {
            return (int) $student->manager_id === (int) $user->id;
        }

        // A member may only view their own record.
        return (int) $student->user_id === (int) $user->id;
    }

    protected function rules(?Student $student = null): array
    {
        return [
            'user_id' => [
                'nullable',
                'exists:users,id',
                // One login should not back two student records.
                Rule::unique('students', 'user_id')->ignore($student?->id),
            ],
            // Who owns/manages this member record (manager or member account).
            'manager_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'roll' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'join_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * Users eligible to be linked: those not already tied to another student,
     * plus the one currently linked to this student.
     */
    protected function linkableUsers()
    {
        $takenIds = Student::query()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereNotIn('id', $takenIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Users who can be named as a member's manager: anyone trusted with the
     * roster (Institution Admin, Meal Manager), so a member record points at a
     * real responsible party.
     */
    protected function managerOptions()
    {
        return User::query()
            ->where(function ($q) {
                $q->whereHas('roles', function ($r) {
                    $r->whereIn('name', [
                        'Software Super Admin',
                        'Institution Admin',
                        'Meal Manager',
                    ]);
                })
                // Include already-assigned managers even if their role changed.
                ->orWhereIn('id', Student::query()->whereNotNull('manager_id')->pluck('manager_id'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
