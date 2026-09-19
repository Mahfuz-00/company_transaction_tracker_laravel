<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\SubscriptionPlan;
use App\Support\AuditLogger;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: Pricing & Subscription Plan Manager.
 *
 * Where the SSA defines the SaaS pricing tiers (Free Trial, Standard,
 * Enterprise, ...) and assigns them to institutions. Plans are PLATFORM records;
 * every read/write runs in the global (cross-tenant) context, and the whole
 * controller is SSA-gated both at the route and here.
 */
class SubscriptionPlanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        return Inertia::render('SSA/Plans', app(TenantManager::class)->runGlobally(function () {
            $plans = SubscriptionPlan::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'monthly_price' => (float) $plan->monthly_price,
                    'is_free' => $plan->is_free,
                    'is_trial_default' => $plan->is_trial_default,
                    'member_limit' => $plan->member_limit,
                    'member_limit_label' => $plan->memberLimitLabel(),
                    'manager_limit' => $plan->manager_limit,
                    'manager_limit_label' => $plan->managerLimitLabel(),
                    'features' => $plan->features ?? [],
                    'sort_order' => $plan->sort_order,
                    'is_active' => $plan->is_active,
                    'is_public' => $plan->is_public,
                    'institutions_count' => $plan->institutionCount(),
                    'mrr' => $plan->mrr(),
                ]);

            return [
                'plans' => $plans,
                'institutions' => Institution::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'subscription_plan', 'subscription_status', 'subscription_amount'])
                    ->map(fn (Institution $i) => [
                        'id' => $i->id,
                        'name' => $i->name,
                        'plan' => $i->subscription_plan,
                        'status' => $i->subscriptionLabel(),
                        'amount' => (float) $i->subscription_amount,
                    ]),
                'totals' => [
                    'plans' => $plans->count(),
                    'assigned' => $plans->sum('institutions_count'),
                    'mrr' => round($plans->sum('mrr'), 2),
                ],
            ];
        }));
    }

    /**
     * Create a pricing tier. The `key` is derived from the name when omitted and
     * must be unique - it is the string institutions store as their plan.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $this->validated($request);

        $plan = SubscriptionPlan::create($data);

        AuditLogger::log('created', "created pricing plan \"{$plan->name}\"", null, [
            'key' => $plan->key,
            'monthly_price' => (float) $plan->monthly_price,
        ], ['subject_label' => $plan->name]);

        return back()->with('success', "Pricing plan \"{$plan->name}\" created.");
    }

    public function update(Request $request, SubscriptionPlan $plan)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $this->validated($request, $plan);

        $plan->update($data);

        AuditLogger::log('updated', "updated pricing plan \"{$plan->name}\"", null, [
            'key' => $plan->key,
            'monthly_price' => (float) $plan->monthly_price,
            'is_active' => $plan->is_active,
        ], ['subject_label' => $plan->name]);

        return back()->with('success', "Pricing plan \"{$plan->name}\" updated.");
    }

    public function destroy(Request $request, SubscriptionPlan $plan)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        // Refuse to delete a plan that institutions are actively using.
        if ($plan->institutionCount() > 0) {
            return back()->with('error', "Can't delete \"{$plan->name}\" - {$plan->institutionCount()} institution(s) are on it. Deactivate it instead.");
        }

        $name = $plan->name;
        $plan->delete();

        AuditLogger::log('deleted', "deleted pricing plan \"{$name}\"", null, [], ['subject_label' => $name]);

        return back()->with('success', "Pricing plan \"{$name}\" deleted.");
    }

    /**
     * Assign a plan to one institution. Updates the institution's label + amount
     * so revenue reporting and the institution's own billing copy stay in step.
     */
    public function assign(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'subscription_status' => ['nullable', Rule::in(array_keys(Institution::SUBSCRIPTION_STATUSES))],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        $institution->forceFill([
            'subscription_plan' => $plan->key,
            'subscription_amount' => $plan->monthly_price,
            // Allow an explicit status override; otherwise infer from the plan.
            'subscription_status' => $data['subscription_status']
                ?? ($plan->is_free ? 'trial' : 'paid'),
            'member_limit' => $plan->member_limit === -1 ? null : $plan->member_limit,
        ])->save();

        AuditLogger::log('updated', "assigned plan \"{$plan->name}\" to \"{$institution->name}\"", $institution, [
            'plan' => $plan->key,
            'amount' => (float) $plan->monthly_price,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with('success', "\"{$institution->name}\" is now on the {$plan->name} plan.");
    }

    /* ------------------------------------------------------------------ */

    protected function validated(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('subscription_plans', 'key')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'is_free' => ['boolean'],
            'is_trial_default' => ['boolean'],
            'member_limit' => ['required', 'integer', 'min:-1'],
            'manager_limit' => ['required', 'integer', 'min:-1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
        ]);

        $data['key'] = $data['key'] ?? Str::slug($data['name'], '_');
        $data['features'] = array_values(array_filter($data['features'] ?? [], fn ($f) => filled($f)));
        // A free plan is always priced at zero.
        if (! empty($data['is_free'])) {
            $data['monthly_price'] = 0;
        }

        return $data;
    }
}
