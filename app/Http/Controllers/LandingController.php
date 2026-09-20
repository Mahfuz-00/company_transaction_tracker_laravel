<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Notifier;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page + lead capture.
 *
 * Serves the marketing page to guests and exposes the SSA-configured pricing
 * tiers so the pricing section is always in sync with what the platform
 * actually sells. It also accepts a "request a demo / contact" submission and
 * routes it to the platform owner.
 */
class LandingController extends Controller
{
    public function index(): Response
    {
        // Pricing tiers the SSA has marked public + active. Read globally (they
        // are platform records) regardless of any active tenant context.
        $plans = app(TenantManager::class)->runGlobally(fn () => SubscriptionPlan::publicPlans()
            ->map(fn (SubscriptionPlan $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'name' => $p->name,
                'description' => $p->description,
                'monthly_price' => (float) $p->monthly_price,
                'is_free' => $p->is_free,
                'member_limit' => $p->member_limit,
                'member_limit_label' => $p->memberLimitLabel(),
                'features' => $p->features ?? [],
            ])
            ->values()
            ->all());

        return Inertia::render('Welcome', [
            'plans' => $plans,
            // Institutions that have opted into public discovery (name only).
            'institutionCount' => app(TenantManager::class)->runGlobally(
                fn () => Institution::query()->where('is_active', true)->count()
            ),
        ]);
    }

    /**
     * Lead capture from the landing "Request a demo" form.
     *
     * Stores the enquiry and notifies the platform owner (SSA). Deliberately
     * simple: it records the lead in the audit trail and emails the owner, so
     * nothing is lost even before a CRM exists.
     */
    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'institution_name' => ['nullable', 'string', 'max:160'],
            'institution_type' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * Persist the enquiry as a FIRST-CLASS, actionable record.
         *
         * Previously it lived only in the audit log, so the SSA could see it but
         * not act on it. Now it is a LandingEnquiry the SSA can approve - which
         * provisions the institution - from the dashboard.
         */
        $enquiry = LandingEnquiry::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'institution_name' => $data['institution_name'] ?? null,
            'institution_type' => $data['institution_type'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'new',
        ]);

        // Keep the audit trail too, so the event is visible in the global log.
        AuditLogger::log('created', "landing enquiry from {$data['email']}", $enquiry, [
            'name' => $data['name'],
            'email' => $data['email'],
            'institution' => $data['institution_name'] ?? null,
            'type' => $data['institution_type'] ?? null,
            'message' => $data['message'] ?? null,
        ], ['subject_label' => $data['name']]);

        // Notify the platform owner (first active SSA), best-effort.
        try {
            $owner = app(TenantManager::class)->runGlobally(fn () => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Software Super Admin'))
                ->where('status', 'active')
                ->first());

            if ($owner) {
                Notifier::send(
                    collect([$owner]),
                    'lead',
                    'New demo request',
                    "{$data['name']} ({$data['email']})"
                        . ($data['institution_name'] ? " from {$data['institution_name']}" : '')
                        . ($data['message'] ? " — {$data['message']}" : ''),
                    // Deep-link straight into the enquiries queue so the SSA can act.
                    ['url' => route('ssa.enquiries.index', [], false)],
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Thanks! Your request has been received - our team will reach out shortly.');
    }
}
