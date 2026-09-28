<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ClaimController;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CLAIMS API — the member↔manager dispute workflow on mobile.
 *
 * WHY THIS DELEGATES INSTEAD OF REIMPLEMENTING
 * --------------------------------------------
 * Approving a claim MOVES MONEY: it writes ledger transactions and deposit rows
 * inside a database transaction so the pool and the member's balance can never
 * diverge. Duplicating that logic for mobile would create a second, independently
 * drifting implementation of the platform's most delicate write — the classic
 * source of "the app and the website disagree about my balance".
 *
 * So this controller reuses the web controller's own:
 *   - `canReview()`  — the exact authorisation rule, and
 *   - `present()`    — the exact serialisation.
 *
 * The money-moving bodies of approve/reject are identical to the web ones and
 * are kept in ONE place (`ClaimController`), which this class extends so the
 * shared helpers and the shared write path are literally the same code.
 *
 * ACCESS
 *   - index/store  : a member sees and creates only their OWN claims (resolved
 *                    from the token; no member id is accepted).
 *   - review       : staff only (`claims.review`), scoped exactly as the web is —
 *                    an Institution Admin sees the whole institution, a Meal
 *                    Manager sees only their assigned members' claims.
 */
class ClaimApiController extends ClaimController
{
    /** GET /api/claims — the signed-in member's own claims. */
    public function index(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json([
                'data' => [],
                'meta' => ['has_member_record' => false],
            ]);
        }

        $perPage = min((int) $request->query('per_page', 15), 50);

        $claims = Claim::query()
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $claims->through(fn (Claim $c) => $this->present($c));

        return response()->json([
            'data' => $claims->items(),
            'meta' => [
                'has_member_record' => true,
                'kinds' => $this->enumList(Claim::KINDS),
                'subjects' => $this->labelMap(Claim::SUBJECTS),
                'pagination' => [
                    'current_page' => $claims->currentPage(),
                    'last_page' => $claims->lastPage(),
                    'per_page' => $claims->perPage(),
                    'total' => $claims->total(),
                ],
            ],
        ]);
    }

    /** POST /api/claims — raise a claim. */
    public function store(Request $request)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json([
                'message' => 'Your account is not linked to a member record yet. Ask your manager to link it.',
            ], 409);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Claim::KINDS))],
            'subject' => ['nullable', Rule::in(array_keys(Claim::SUBJECTS))],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'claim_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'entry_date' => ['nullable', 'date'],
            'breakfast' => ['nullable', 'integer', 'min:0', 'max:10'],
            'lunch' => ['nullable', 'integer', 'min:0', 'max:10'],
            'dinner' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        // The same guard rails as the web form, so a half-complete claim never
        // reaches a manager. Returned as a normal 422 so the app shows the error
        // inline rather than as a flash banner.
        if ($data['kind'] === 'expense' && blank($data['amount'] ?? null)) {
            return response()->json([
                'message' => 'Please enter the amount you spent.',
                'errors' => ['amount' => ['Please enter the amount you spent.']],
            ], 422);
        }

        if ($data['kind'] === 'dispute' && ($data['subject'] ?? null) === 'meal') {
            $meals = (int) ($data['breakfast'] ?? 0) + (int) ($data['lunch'] ?? 0) + (int) ($data['dinner'] ?? 0);

            if ($meals <= 0) {
                return response()->json([
                    'message' => 'Select at least one missed meal.',
                    'errors' => ['breakfast' => ['Select at least one missed meal.']],
                ], 422);
            }

            if (blank($data['entry_date'] ?? null)) {
                return response()->json([
                    'message' => 'Please provide the date the meal was missed.',
                    'errors' => ['entry_date' => ['Please provide the date the meal was missed.']],
                ], 422);
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

        Notifier::claimSubmitted($claim, $request->user());

        return response()->json([
            'data' => $this->present($claim),
            'message' => 'Claim submitted. Your manager will review it.',
        ], 201);
    }

    /**
     * GET /api/claims/review — the manager queue.
     *
     * Permission-gated by the route (`claims.review`), which a Member does not
     * hold. Scoped by BOTH institution and manager assignment, identically to the
     * web queue: an Institution Admin sees every claim in the institution, a Meal
     * Manager sees only claims from members assigned to them.
     */
    public function review(Request $request)
    {
        $status = (string) $request->query('status', 'pending');
        $kind = (string) $request->query('kind', '');
        $perPage = min((int) $request->query('per_page', 20), 100);

        $scopedIds = $request->user()->scopedStudentIds();

        $base = fn () => Claim::query()
            ->forInstitution(Institution::current()?->id)
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds));

        $claims = $base()
            ->with(['student:id,name,roll', 'reviewer:id,name'])
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($kind !== '', fn ($q) => $q->ofKind($kind))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $claims->through(fn (Claim $c) => $this->present($c, true));

        return response()->json([
            'data' => $claims->items(),
            'meta' => [
                'stats' => [
                    'pending' => $base()->pending()->count(),
                    'approved' => $base()->where('status', 'approved')->count(),
                    'rejected' => $base()->where('status', 'rejected')->count(),
                ],
                'kinds' => $this->enumList(Claim::KINDS),
                // Lets the app say "only your assigned members" for a manager.
                'scoped_to_assigned' => $scopedIds !== null,
                'pagination' => [
                    'current_page' => $claims->currentPage(),
                    'last_page' => $claims->lastPage(),
                    'per_page' => $claims->perPage(),
                    'total' => $claims->total(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/claims/{claim}/approve
     *
     * Runs the SAME money-moving transaction as the web approval — this method
     * exists only to translate the web's redirect response into JSON, so the
     * ledger-writing code lives in exactly one place.
     */
    public function approve(Request $request, Claim $claim)
    {
        if (! $this->canReview($request, $claim)) {
            return response()->json(['message' => 'You cannot review this claim.'], 403);
        }

        if (! $claim->isPending()) {
            return response()->json(['message' => 'This claim has already been reviewed.'], 409);
        }

        // Delegate the actual approval (and its transaction) to the web controller.
        $response = parent::approve($request, $claim);

        // The parent returns a redirect with a flash message; surface that message
        // as JSON. A failure flash means the parent refused the write.
        $flash = session()->get('error') ?? session()->get('success');

        if (session()->has('error')) {
            return response()->json(['message' => $flash], 422);
        }

        return response()->json([
            'data' => $this->present($claim->fresh(), true),
            'message' => $flash ?? 'Claim approved and the member\'s balance updated.',
        ]);
    }

    /** PATCH /api/claims/{claim}/reject — no money moves. */
    public function reject(Request $request, Claim $claim)
    {
        if (! $this->canReview($request, $claim)) {
            return response()->json(['message' => 'You cannot review this claim.'], 403);
        }

        if (! $claim->isPending()) {
            return response()->json(['message' => 'This claim has already been reviewed.'], 409);
        }

        $response = parent::reject($request, $claim);

        $flash = session()->get('error') ?? session()->get('success');

        if (session()->has('error')) {
            return response()->json(['message' => $flash], 422);
        }

        return response()->json([
            'data' => $this->present($claim->fresh(), true),
            'message' => $flash ?? 'Claim rejected.',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** `['dispute' => 'Missing entry / dispute', ...]` from a KINDS map. */
    protected function enumList(array $map): array
    {
        return collect($map)
            ->map(fn ($meta, $key) => ['value' => $key, 'label' => is_array($meta) ? $meta['label'] : $meta])
            ->values()
            ->all();
    }

    /** A flat `{ key: label }` map (SUBJECTS is already in that shape). */
    protected function labelMap(array $map): array
    {
        return collect($map)->map(fn ($label) => is_array($label) ? ($label['label'] ?? '') : $label)->all();
    }
}
