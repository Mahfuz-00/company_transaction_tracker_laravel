<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\InstitutionProvisioner;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: Landing Enquiries / Demo Requests.
 *
 * The public landing page captures demo requests. Previously an enquiry only
 * surfaced as an audit-log line, so the SSA could see it but could not act.
 * This controller turns each enquiry into an actionable record:
 *   - list / filter them,
 *   - APPROVE one -> provision the institution (trial or a chosen plan), create
 *     its first admin, start the trial window and send the welcome email,
 *   - mark contacted / rejected with a note.
 */
class LandingEnquiryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        $status = (string) $request->query('status', '');

        return Inertia::render('SSA/Enquiries', app(TenantManager::class)->runGlobally(function () use ($status) {
            $enquiries = LandingEnquiry::query()
                ->with(['institution:id,name,slug', 'reviewer:id,name'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (LandingEnquiry $e) => $this->present($e));

            return [
                'enquiries' => $enquiries,
                'filter' => $status,
                'stats' => [
                    'new' => LandingEnquiry::query()->where('status', 'new')->count(),
                    'contacted' => LandingEnquiry::query()->where('status', 'contacted')->count(),
                    'approved' => LandingEnquiry::query()->where('status', 'approved')->count(),
                    'rejected' => LandingEnquiry::query()->where('status', 'rejected')->count(),
                    'total' => LandingEnquiry::query()->count(),
                ],
                'statuses' => collect(LandingEnquiry::STATUSES)
                    ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label'], 'tone' => $meta['tone']])
                    ->values(),
                // Plan options the SSA can provision the new institution onto.
                'plans' => SubscriptionPlan::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'key', 'name', 'is_free', 'monthly_price'])
                    ->map(fn (SubscriptionPlan $p) => [
                        'id' => $p->id,
                        'key' => $p->key,
                        'name' => $p->name,
                        'is_free' => $p->is_free,
                        'monthly_price' => (float) $p->monthly_price,
                    ]),
                'trialDays' => Institution::TRIAL_DAYS,
                'institutionTypes' => collect(Institution::TYPES)
                    ->map(fn ($preset, $key) => ['value' => $key, 'label' => $preset['label']])
                    ->values(),
            ];
        }));
    }

    /**
     * APPROVE an enquiry and provision the institution from it.
     *
     * This is the core action: it turns a lead into a live workspace by
     *   - creating the institution (trial by default, or the chosen plan),
     *   - provisioning its first Institution Admin with a temporary password,
     *   - starting the 7-day trial (or marking it paid),
     *   - emailing the welcome / trial email to the applicant,
     *   - marking the enquiry approved and linking it to the new institution.
     *
     * Everything runs in one transaction so a failure provisions nothing.
     * Mail is sent AFTER the commit and is best-effort, so a mail problem never
     * undoes a successfully created workspace.
     */
    public function approve(Request $request, LandingEnquiry $enquiry)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        if ($enquiry->status === 'approved' && $enquiry->institution_id) {
            return back()->with('error', 'This enquiry has already been approved.');
        }

        $data = $request->validate([
            // The institution name defaults to the enquiry's, but the SSA can fix it.
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(array_keys(Institution::TYPES))],
            // 'trial' (default) starts the free window; 'plan' assigns a plan.
            'provision_mode' => ['nullable', Rule::in(['trial', 'plan'])],
            'plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            // The admin account the applicant will sign in with.
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_password' => ['nullable', 'string', 'min:8'],
        ]);

        $mode = $data['provision_mode'] ?? 'trial';
        $plan = ! empty($data['plan_id']) ? SubscriptionPlan::find($data['plan_id']) : null;

        // Guard: a 'plan' provision needs a real plan.
        if ($mode === 'plan' && ! $plan) {
            return back()->withErrors(['plan_id' => 'Choose a plan, or provision on a free trial.']);
        }

        $institutionName = $data['name'] ?? $enquiry->institution_name ?? ($enquiry->name . "'s Institution");
        $adminEmail = $enquiry->email;
        $adminName = $data['admin_name'] ?? $enquiry->name;

        // Refuse if a user with that email already exists (avoid a duplicate login).
        if (User::where('email', $adminEmail)->exists()) {
            return back()->with('error', "A user with {$adminEmail} already exists. Reconcile it before provisioning.");
        }

        // A temporary password the applicant changes on first sign-in, unless the
        // SSA supplied one. The provisioner generates one when it is blank, so the
        // admin is NEVER emailed without credentials.
        [$institution, $admin, $plainPassword] = InstitutionProvisioner::provision([
            'name' => $institutionName,
            'type' => $data['type'] ?? $this->guessType($enquiry->institution_type),
            'contact_email' => $enquiry->email,
            'address' => null,
            'onboarding_mode' => $mode === 'plan' ? 'subscription' : 'trial',
            'subscription_plan' => $plan?->key,
            'subscription_amount' => $plan?->monthly_price ?? 0,
            'trial_days' => $data['trial_days'] ?? null,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $data['admin_password'] ?? null,
        ], $request->user());

        // Close out the enquiry against the freshly provisioned workspace.
        $enquiry->update([
            'status' => 'approved',
            'institution_id' => $institution->id,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        AuditLogger::log('created', "approved landing enquiry and provisioned \"{$institution->name}\"", $institution, [
            'enquiry_id' => $enquiry->id,
            'enquiry_email' => $enquiry->email,
            'mode' => $mode,
            'plan' => $plan?->key,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        $label = $mode === 'plan' && $plan ? "the {$plan->name} plan" : 'a ' . Institution::TRIAL_DAYS . '-day free trial';

        return back()->with(
            'success',
            "Institution \"{$institution->name}\" provisioned on {$label}. Welcome email sent to {$admin->email}"
            // Show the generated password only when the SSA did NOT supply one.
            . (filled($data['admin_password'] ?? null) ? '.' : " with a temporary password: {$plainPassword}")
        );
    }

    /** Mark an enquiry as contacted (a note, no provisioning). */
    public function markContacted(Request $request, LandingEnquiry $enquiry)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:2000']]);

        $enquiry->update([
            'status' => 'contacted',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? $enquiry->review_notes,
        ]);

        return back()->with('success', 'Enquiry marked as contacted.');
    }

    /** Reject an enquiry. */
    public function reject(Request $request, LandingEnquiry $enquiry)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:2000']]);

        $enquiry->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? $enquiry->review_notes,
        ]);

        return back()->with('success', 'Enquiry rejected.');
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** Map the free-form landing "institution type" onto a real type key. */
    protected function guessType(?string $type): string
    {
        return match ($type) {
            'corporate' => 'company',
            'university' => 'university_dorm',
            'college' => 'college_dorm',
            'mess' => 'general_mess',
            default => 'general_mess',
        };
    }

    protected function present(LandingEnquiry $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'email' => $e->email,
            'institution_name' => $e->institution_name,
            'institution_type' => $e->institution_type,
            'message' => $e->message,
            'status' => $e->status,
            'status_label' => $e->statusLabel(),
            'status_tone' => $e->statusTone(),
            'institution' => $e->institution ? [
                'id' => $e->institution->id,
                'name' => $e->institution->name,
                'slug' => $e->institution->slug,
            ] : null,
            'reviewer' => $e->reviewer?->name,
            'reviewed_at' => $e->reviewed_at?->format('j M Y, H:i'),
            'review_notes' => $e->review_notes,
            'created_at' => $e->created_at?->format('j M Y, H:i'),
            'created_human' => $e->created_at?->diffForHumans(),
        ];
    }
}
