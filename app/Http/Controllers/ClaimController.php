<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\Transaction;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Claim & dispute workflow.
 *
 * Members raise claims ("a deposit is missing", "I bought supplies"); managers
 * review them. Approval is the ONLY point at which money moves, and it happens
 * transactionally so the ledger and the member's balance can never diverge.
 *
 * Two kinds:
 *   - dispute : approving creates the missing deposit / meal entry (or a credit
 *               adjustment), which raises the member's balance.
 *   - expense : approving records a reimbursable cash-out and credits the
 *               member's balance with the amount they spent.
 */
class ClaimController extends Controller
{
    /* ------------------------------------------------------------------ *
     * Member side
     * ------------------------------------------------------------------ */

    /** The signed-in member's own claims. */
    public function index(Request $request)
    {
        $student = $request->user()->studentRecord();

        $claims = $student
            ? Claim::query()
                ->where('student_id', $student->id)
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (Claim $c) => $this->present($c))
            : null;

        return Inertia::render('Claims/Index', [
            'hasMemberRecord' => (bool) $student,
            'claims' => $claims,
            'kinds' => collect(Claim::KINDS)->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label']])->values(),
            'subjects' => collect(Claim::SUBJECTS)->map(fn ($label, $k) => ['value' => $k, 'label' => $label])->values(),
        ]);
    }

    /** Submit a new claim (member-raised). */
    public function store(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return back()->with('error', 'Your account is not linked to a member record yet. Ask your manager to link it.');
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Claim::KINDS))],
            // A dispute must say what it is about; an expense does not.
            'subject' => ['nullable', Rule::in(array_keys(Claim::SUBJECTS))],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'claim_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            // Meal-dispute specifics.
            'entry_date' => ['nullable', 'date'],
            'breakfast' => ['nullable', 'integer', 'min:0', 'max:10'],
            'lunch' => ['nullable', 'integer', 'min:0', 'max:10'],
            'dinner' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        // Guard rails per kind so a half-complete claim never reaches a manager.
        if ($data['kind'] === 'expense' && blank($data['amount'] ?? null)) {
            return back()->with('error', 'Please enter the amount you spent.');
        }

        if ($data['kind'] === 'dispute' && ($data['subject'] ?? null) === 'meal') {
            $meals = (int) ($data['breakfast'] ?? 0) + (int) ($data['lunch'] ?? 0) + (int) ($data['dinner'] ?? 0);
            if ($meals <= 0) {
                return back()->with('error', 'Select at least one missed meal.');
            }
            if (blank($data['entry_date'] ?? null)) {
                return back()->with('error', 'Please provide the date the meal was missed.');
            }
        }

        $claim = Claim::create([
            'institution_id' => $student->institution_id ?? Institution::current()?->id,
            'student_id' => $student->id,
            'kind' => $data['kind'],
            'subject' => $data['subject'] ?? null,
            'amount' => $data['amount'] ?? null,
            'entry_date' => $data['entry_date'] ?? null,
            'breakfast' => $data['breakfast'] ?? null,
            'lunch' => $data['lunch'] ?? null,
            'dinner' => $data['dinner'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'claim_date' => $data['claim_date'] ?? now()->toDateString(),
            'payment_method' => $data['payment_method'] ?? null,
            'status' => 'pending',
        ]);

        AuditLogger::log('created', "raised a {$claim->kindLabel()} claim", $claim, [
            'kind' => $claim->kind,
            'amount' => $claim->amount !== null ? (float) $claim->amount : null,
        ], ['subject_label' => $student->name, 'institution_id' => $claim->institution_id]);

        // Tell the member's assigned manager (and admins as a safety net).
        Notifier::claimSubmitted($claim, $request->user());

        return back()->with('success', 'Claim submitted. Your manager will review it.');
    }

    /* ------------------------------------------------------------------ *
     * Manager side
     * ------------------------------------------------------------------ */

    /**
     * The review queue.
     *
     * Scoped by BOTH institution and manager assignment:
     *   - Institution Admins / SSAs see every claim in the institution.
     *   - A Meal Manager sees ONLY claims raised by the members assigned to them
     *     (students.manager_id = their id) - never the whole institution's.
     */
    public function review(Request $request)
    {
        $institution = Institution::current();
        $status = (string) $request->query('status', 'pending');
        $kind = (string) $request->query('kind', '');

        // null = unrestricted within the institution; array = only these students.
        $scopedIds = $request->user()->scopedStudentIds();

        $base = fn () => Claim::query()
            ->forInstitution($institution?->id)
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds));

        $claims = $base()
            ->with(['student:id,name,roll', 'reviewer:id,name'])
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($kind !== '', fn ($q) => $q->ofKind($kind))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Claim $c) => $this->present($c, true));

        return Inertia::render('Claims/Review', [
            'claims' => $claims,
            'stats' => [
                'pending' => $base()->pending()->count(),
                'approved' => $base()->where('status', 'approved')->count(),
                'rejected' => $base()->where('status', 'rejected')->count(),
            ],
            'kinds' => collect(Claim::KINDS)->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label']])->values(),
            'filters' => ['status' => $status, 'kind' => $kind],
            // So the UI can say "only your assigned members" for a manager.
            'scopedToAssigned' => $scopedIds !== null,
        ]);
    }

    /** Approve a claim, moving money and producing the ledger records. */
    public function approve(Request $request, Claim $claim)
    {
        if (! $this->canReview($request, $claim)) {
            return back()->with('error', 'You cannot review a claim from another institution.');
        }

        if (! $claim->isPending()) {
            return back()->with('error', 'This claim has already been reviewed.');
        }

        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:1000'],
            // A manager may approve a different amount than claimed (e.g. partial).
            'approved_amount' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
        ]);

        DB::transaction(function () use ($request, $claim, $data) {
            $student = $claim->student;
            $amount = (float) ($data['approved_amount'] ?? $claim->amount ?? 0);
            $depositId = null;
            $transactionId = null;

            if ($claim->kind === 'expense') {
                // The member bought something: record a cash-out (the institution
                // now owes them) and credit their balance with a deposit row so
                // the money is settled immediately.
                $tx = Transaction::create([
                    'user_id' => $request->user()->id,
                    'student_id' => $student->id,
                    'type' => 'out',
                    'item' => 'Member purchase: ' . $claim->title,
                    'amount' => $amount,
                    'category' => $claim->subject ? ucfirst($claim->subject) : 'Member Purchase',
                    'payee' => $student->name,
                    'reason' => 'Approved member expense claim #' . $claim->id,
                    'source' => 'claim',
                ]);
                $transactionId = $tx->id;

                // Credit the member: the institution reimburses what they spent.
                $deposit = Deposit::create([
                    'student_id' => $student->id,
                    'amount' => $amount,
                    'kind' => 'credit',
                    'payment_method' => 'Reimbursement',
                    'recorded_by' => $request->user()->id,
                    'transaction_id' => $tx->id,
                    'notes' => 'Reimbursement for approved claim #' . $claim->id . ' - ' . $claim->title,
                ]);
                $depositId = $deposit->id;
            } elseif ($claim->subject === 'meal') {
                // Missing meal entry: add the missed meals to that day's record.
                // Looks up by DATE (the column stores a datetime), matching the
                // pattern used by MealEntryController to avoid a unique clash.
                $entry = MealEntry::query()
                    ->where('student_id', $student->id)
                    ->whereDate('date', $claim->entry_date)
                    ->first();

                $attributes = [
                    'breakfast' => (int) ($entry?->breakfast ?? 0) + (int) ($claim->breakfast ?? 0),
                    'lunch' => (int) ($entry?->lunch ?? 0) + (int) ($claim->lunch ?? 0),
                    'dinner' => (int) ($entry?->dinner ?? 0) + (int) ($claim->dinner ?? 0),
                    'recorded_by' => $request->user()->id,
                ];

                if ($entry) {
                    $entry->update($attributes);
                } else {
                    MealEntry::create($attributes + [
                        'student_id' => $student->id,
                        'date' => $claim->entry_date,
                    ]);
                }
            } else {
                // Missing deposit (or other correction): record the deposit and
                // its matching cash-in, so the member's balance rises.
                $tx = Transaction::create([
                    'user_id' => $request->user()->id,
                    'student_id' => $student->id,
                    'type' => 'in',
                    'item' => 'Claim adjustment for ' . $student->name,
                    'amount' => $amount,
                    'category' => 'Claim Adjustment',
                    'by_whom' => $student->name,
                    'reason' => 'Approved claim #' . $claim->id . ' - ' . $claim->title,
                    'source' => 'claim',
                ]);
                $transactionId = $tx->id;

                $deposit = Deposit::create([
                    'student_id' => $student->id,
                    'amount' => $amount,
                    'kind' => 'credit',
                    'payment_method' => $claim->payment_method ?: 'Claim adjustment',
                    'recorded_by' => $request->user()->id,
                    'transaction_id' => $tx->id,
                    'notes' => 'Approved claim #' . $claim->id . ' - ' . $claim->title,
                ]);
                $depositId = $deposit->id;
            }

            $claim->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
                'result_deposit_id' => $depositId,
                'result_transaction_id' => $transactionId,
            ]);

            AuditLogger::log('approved', "approved a {$claim->kindLabel()} claim for {$student->name}", $claim, [
                'amount' => $amount,
                'deposit_id' => $depositId,
                'transaction_id' => $transactionId,
            ], ['subject_label' => $student->name, 'institution_id' => $claim->institution_id]);

            // Notify the member their claim was approved.
            Notifier::claimReviewed($claim, true, $request->user());

            // A member-funded expense approval is a distinct, money-moving event.
            if ($claim->kind === 'expense') {
                Notifier::memberExpenseApproved($claim, $amount, $request->user());
            }
        });

        return back()->with('success', 'Claim approved and the member\'s balance updated.');
    }

    /** Reject a claim. No money moves. */
    public function reject(Request $request, Claim $claim)
    {
        if (! $this->canReview($request, $claim)) {
            return back()->with('error', 'You cannot review a claim from another institution.');
        }

        if (! $claim->isPending()) {
            return back()->with('error', 'This claim has already been reviewed.');
        }

        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $claim->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? null,
        ]);

        AuditLogger::log('rejected', "rejected a {$claim->kindLabel()} claim", $claim, [
            'reason' => $data['review_notes'] ?? null,
        ], ['subject_label' => $claim->student?->name, 'institution_id' => $claim->institution_id]);

        Notifier::claimReviewed($claim, false, $request->user());

        return back()->with('success', 'Claim rejected.');
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * May this user review the given claim?
     *
     *  - SSA / Institution Admin : any claim in their institution.
     *  - Meal Manager            : ONLY a claim raised by a member assigned to
     *    them. A manager cannot touch another manager's member, even in the same
     *    institution.
     */
    protected function canReview(Request $request, Claim $claim): bool
    {
        $user = $request->user();

        if ($user->isSuperAdmin() || $user->isInstitutionAdmin()) {
            // Still bound to the institution that owns the claim.
            return $claim->institution_id !== null
                && $user->belongsToInstitution($claim->institution_id);
        }

        if ($user->hasRole('Meal Manager')) {
            return $claim->student_id !== null
                && $user->assignedMembers()->whereKey($claim->student_id)->exists();
        }

        return false;
    }

    /**
     * Serialise a claim for the UI. `$review` adds reviewer detail for the
     * manager queue.
     */
    protected function present(Claim $claim, bool $review = false): array
    {
        $payload = [
            'id' => $claim->id,
            'kind' => $claim->kind,
            'kind_label' => $claim->kindLabel(),
            'subject' => $claim->subject,
            'subject_label' => $claim->subject ? (Claim::SUBJECTS[$claim->subject] ?? ucfirst($claim->subject)) : null,
            'title' => $claim->title,
            'summary' => $claim->summary,
            'description' => $claim->description,
            'amount' => $claim->amount !== null ? (float) $claim->amount : null,
            'status' => $claim->status,
            'status_label' => $claim->statusLabel(),
            'entry_date' => $claim->entry_date?->format('j M Y'),
            'breakfast' => $claim->breakfast,
            'lunch' => $claim->lunch,
            'dinner' => $claim->dinner,
            'claim_date' => $claim->claim_date?->format('j M Y'),
            'payment_method' => $claim->payment_method,
            'review_notes' => $claim->review_notes,
            'created_at' => $claim->created_at?->format('j M Y, H:i'),
            'student' => $claim->student ? [
                'id' => $claim->student->id,
                'name' => $claim->student->name,
                'roll' => $claim->student->roll,
            ] : null,
        ];

        if ($review) {
            $payload['reviewer'] = $claim->reviewer?->name;
            $payload['reviewed_at'] = $claim->reviewed_at?->format('j M Y, H:i');
        }

        return $payload;
    }
}
