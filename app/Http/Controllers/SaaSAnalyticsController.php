<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: GLOBAL SaaS Business Analytics.
 *
 * This replaces the tenant-scoped analytics view for the SSA. It deliberately
 * reports on the SAAS BUSINESS - subscription revenue, conversion, retention
 * and cross-tenant usage signals - NOT on any single institution's meal counts.
 * A tenant's meal analytics remains available to that tenant's staff inside
 * their own workspace (route `analytics`).
 *
 * TENANCY: every read runs inside `TenantManager::runGlobally()`, the single
 * sanctioned place the per-model tenant scope is lifted, so platform-wide
 * aggregates are correct while isolation holds everywhere else.
 */
class SaaSAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        $months = max(3, min(24, (int) $request->query('months', 12)));

        return Inertia::render('SSA/Analytics', app(TenantManager::class)->runGlobally(function () use ($months) {
            return [
                'kpis' => $this->kpis(),
                'trend' => $this->revenueAndGrowthTrend($months),
                'conversion' => $this->conversion(),
                'planBreakdown' => $this->planBreakdown(),
                'tenantSize' => $this->tenantSizeDistribution(),
                'topRevenue' => $this->topRevenue(),
                'months' => $months,
            ];
        }));
    }

    /* ------------------------------------------------------------------ *
     * Headline KPIs
     * ------------------------------------------------------------------ */

    private function kpis(): array
    {
        $institutions = Institution::query()->get();
        $paid = $institutions->where('subscription_status', 'paid');
        $mrr = (float) $paid->sum('subscription_amount');

        $trial = $institutions->filter(fn ($i) => $i->isOnTrial());
        $expired = $institutions->filter(fn ($i) => $i->trialExpired());

        // Conversion = institutions that ever converted / institutions that ever
        // started on a trial. A clean, honest SaaS conversion ratio.
        $everTrial = $institutions->where('onboarding_mode', 'trial')->count();
        $converted = $institutions->whereNotNull('converted_at')->count()
            + $institutions->where('onboarding_mode', 'subscription')->count();
        $conversionPct = $everTrial > 0
            ? round(min(100, ($institutions->whereNotNull('converted_at')->count() / $everTrial) * 100), 1)
            : ($institutions->where('onboarding_mode', 'subscription')->count() > 0 ? 100.0 : 0.0);

        // Simple churn proxy: paid institutions that are now suspended/cancelled.
        $churned = $institutions->whereIn('subscription_status', ['suspended', 'cancelled'])->count();
        $churnPct = $institutions->count() > 0
            ? round(($churned / $institutions->count()) * 100, 1)
            : 0.0;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'arpa' => $paid->count() > 0 ? round($mrr / $paid->count(), 2) : 0.0,
            'paid_count' => $paid->count(),
            'trial_count' => $trial->count(),
            'expired_count' => $expired->count(),
            'total_institutions' => $institutions->count(),
            'conversion_pct' => $conversionPct,
            'churn_pct' => $churnPct,
            // Revenue at risk: MRR-equivalent of anyone not fully paid.
            'revenue_at_risk' => round((float) $institutions
                ->whereIn('subscription_status', ['overdue', 'pending'])
                ->sum('subscription_amount'), 2),
            'potential_arr' => round((float) $institutions->sum('subscription_amount') * 12, 2),
        ];
    }

    /**
     * A month-by-month series of subscription revenue + customer counts, so the
     * SSA can read the trajectory rather than a single snapshot.
     */
    private function revenueAndGrowthTrend(int $months): array
    {
        $rows = [];
        $cursor = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $month = $cursor->copy()->addMonths($i);
            $end = $month->copy()->endOfMonth();

            // Revenue contribution model: every institution that was already a
            // paid subscriber by this month contributes its MRR. Explainable and
            // honest - it is a run-rate view, not an invoice ledger.
            $revenue = (float) Institution::query()
                ->where('subscription_status', 'paid')
                ->where(function ($q) use ($end) {
                    $q->whereNull('subscription_started_at')
                        ->orWhereDate('subscription_started_at', '<=', $end->toDateString());
                })
                ->sum('subscription_amount');

            $rows[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'revenue' => round($revenue, 2),
                'new_institutions' => Institution::query()
                    ->whereBetween('created_at', [$month->copy()->startOfMonth(), $end])->count(),
                'new_members' => Student::withoutTenantScope()
                    ->whereBetween('created_at', [$month->copy()->startOfMonth(), $end])->count(),
                'new_users' => User::query()
                    ->whereBetween('created_at', [$month->copy()->startOfMonth(), $end])->count(),
            ];
        }

        return $rows;
    }

    /** Trial -> paid funnel, expressed as counts for a funnel chart. */
    private function conversion(): array
    {
        $institutions = Institution::query()->get();

        $started = $institutions->where('onboarding_mode', 'trial')->count()
            + $institutions->where('onboarding_mode', 'subscription')->count();
        $trialStarted = $institutions->where('onboarding_mode', 'trial')->count();
        $converted = $institutions->whereNotNull('converted_at')->count();
        $activePaid = $institutions->where('subscription_status', 'paid')->count();
        $expired = $institutions->filter(fn ($i) => $i->trialExpired())->count();

        return [
            'labels' => ['Registered', 'On trial', 'Converted', 'Active paid'],
            'values' => [$started, $trialStarted, $converted, $activePaid],
            'expired_trials' => $expired,
        ];
    }

    /** Revenue grouped by subscription plan, for a mix breakdown. */
    private function planBreakdown(): array
    {
        return Institution::query()
            ->whereNotNull('subscription_plan')
            ->get()
            ->groupBy(fn (Institution $i) => $i->subscription_plan ?: 'Unspecified')
            ->map(fn ($group, $plan) => [
                'plan' => $plan,
                'count' => $group->count(),
                'mrr' => round((float) $group->sum('subscription_amount'), 2),
            ])
            ->sortByDesc('mrr')
            ->values()
            ->all();
    }

    /** How big tenants are (member counts), bucketed - a product-fit signal. */
    private function tenantSizeDistribution(): array
    {
        $buckets = [
            '1–20' => 0,
            '21–100' => 0,
            '101–500' => 0,
            '500+' => 0,
        ];

        foreach (Institution::query()->withCount('students as members_count')->get() as $i) {
            $n = $i->members_count;
            if ($n <= 20) {
                $buckets['1–20']++;
            } elseif ($n <= 100) {
                $buckets['21–100']++;
            } elseif ($n <= 500) {
                $buckets['101–500']++;
            } else {
                $buckets['500+']++;
            }
        }

        return [
            'labels' => array_keys($buckets),
            'values' => array_values($buckets),
        ];
    }

    /** Top institutions by subscription revenue, with their health. */
    private function topRevenue(): array
    {
        return Institution::query()
            ->withCount('students as members_count')
            ->orderByDesc('subscription_amount')
            ->limit(8)
            ->get()
            ->map(fn (Institution $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'plan' => $i->subscription_plan ?: '—',
                'amount' => (float) $i->subscription_amount,
                'status' => $i->subscriptionLabel(),
                'members' => $i->members_count,
                'arr' => round((float) $i->subscription_amount * 12, 2),
            ])
            ->all();
    }
}
