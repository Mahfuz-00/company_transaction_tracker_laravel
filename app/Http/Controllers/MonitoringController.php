<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\ReportExporter;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: the platform business-oversight dashboard.
 *
 * This is the SaaS control tower. Because the software is sold to many
 * institutions, the SSA needs to answer three questions at a glance:
 *
 *   1. WHO is on the platform and how healthy are they?  -> Institution health
 *   2. Is the business GROWING and is the price right?    -> Business analytics
 *   3. WHAT happened, across every tenant?                -> Global audit stream
 *
 * TENANCY: every aggregate here is deliberately CROSS-TENANT - that is the SSA's
 * job. All reads run inside `TenantManager::runGlobally()`, the single sanctioned
 * place where the per-model tenant scope is lifted. Nothing else in the app does
 * this, so the isolation guarantee everywhere else is preserved.
 */
class MonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        // Only the global role may see cross-tenant business data.
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        $manager = app(TenantManager::class);

        return Inertia::render('Settings/Monitoring', $manager->runGlobally(function () {
            return [
                'overview' => $this->overview(),
                'institutions' => $this->institutionHealth(),
                'revenueTrend' => $this->revenueTrend(),
                'throughput' => $this->throughput(),
                'growth' => $this->growth(),
                'topInstitutions' => $this->topInstitutions(),
                'recentActivity' => $this->recentActivity(),
                // Actionable landing enquiries (demo requests) for the dashboard widget.
                'enquiries' => $this->pendingEnquiries(),
                'statusOptions' => collect(Institution::SUBSCRIPTION_STATUSES)
                    ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                    ->values(),
            ];
        }));
    }

    /**
     * Recent landing enquiries awaiting action - the SSA can approve one into a
     * trial straight from the dashboard.
     */
    private function pendingEnquiries(): array
    {
        return \App\Models\LandingEnquiry::query()
            ->with('institution:id,name')
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn (\App\Models\LandingEnquiry $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'institution_name' => $e->institution_name,
                'institution_type' => $e->institution_type,
                'message' => $e->message,
                'status' => $e->status,
                'status_label' => $e->statusLabel(),
                'status_tone' => $e->statusTone(),
                'created_human' => $e->created_at?->diffForHumans(),
            ])
            ->all();
    }

 /**
     * Count of enquiries still needing action (new), for the dashboard badge.
     */
    private function pendingEnquiryCount(): int
    {
        return \App\Models\LandingEnquiry::query()->where('status', 'new')->count();
    }

    /* ------------------------------------------------------------------ *
     * Headline platform metrics
     * ------------------------------------------------------------------ */

    private function overview(): array
    {
        $institutions = Institution::query()->get();

        $mrr = (float) $institutions
            ->whereIn('subscription_status', ['paid'])
            ->sum('subscription_amount');

        $activePaid = $institutions->where('subscription_status', 'paid')->count();
        $overdue = $institutions->whereIn('subscription_status', ['overdue'])->count();
        $trialing = $institutions->where('subscription_status', 'trial')->count();

        return [
            'institutions' => $institutions->count(),
            'active_institutions' => $institutions->where('is_active', true)->count(),
            'total_members' => Student::withoutTenantScope()->count(),
            'total_users' => User::query()->count(),
            'mrr' => $mrr,
            // Annualised run-rate from the monthly figure.
            'arr' => round($mrr * 12, 2),
            'avg_revenue_per_institution' => $institutions->count() > 0
                ? round($mrr / max(1, $activePaid), 2)
                : 0.0,
            'paid' => $activePaid,
            'overdue' => $overdue,
            'trialing' => $trialing,
            'pending' => $institutions->where('subscription_status', 'pending')->count(),
            // Trial-specific counts for the dashboard's trial panel.
            'trial_active' => $institutions->filter(fn ($i) => $i->isOnTrial())->count(),
            'trial_expiring_soon' => $institutions->filter(
                fn ($i) => $i->isOnTrial() && ($i->trialDaysLeft() ?? 99) <= 2
            )->count(),
            'trial_expired' => $institutions->filter(fn ($i) => $i->trialExpired())->count(),
            // Landing enquiries awaiting action (drives the dashboard badge).
            'pending_enquiries' => $this->pendingEnquiryCount(),
            // Platform data volume (rough throughput proxy) + server load hint.
            'system' => $this->systemHealth(),
        ];
    }

    /**
     * Coarse, portable "server load" indicators.
     *
     * Deliberately dependency-free (no ext-posix requirement): we report memory
     * in use, the PHP process's peak, and the platform's stored row volume as a
     * data-throughput proxy. These are honest, always-available signals rather
     * than an invented CPU %.
     */
    private function systemHealth(): array
    {
        $memoryUsed = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $limit = $this->memoryLimitBytes();

        $rowVolume = Institution::query()->count()
            + User::query()->count()
            + Student::withoutTenantScope()->count()
            + Transaction::withoutTenantScope()->count()
            + \App\Models\Deposit::withoutTenantScope()->count()
            + \App\Models\MealEntry::withoutTenantScope()->count();

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_used' => $memoryUsed,
            'memory_peak' => $memoryPeak,
            'memory_limit' => $limit,
            // 0-100, how close the request is to the PHP memory ceiling.
            'memory_percent' => $limit > 0 ? round(min(100, ($memoryPeak / $limit) * 100), 1) : null,
            'db_driver' => \Illuminate\Support\Facades\DB::connection()->getDriverName(),
            'row_volume' => $rowVolume,
        ];
    }

    /** Parse the php.ini memory_limit (e.g. "128M") into bytes. */
    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * The institution registry with health indicators, subscription status and
     * usage against each plan's member cap.
     */
    private function institutionHealth(): array
    {
        return Institution::query()
            ->withCount([
                'students as members_count',
                'vendors as vendors_count',
                'subsidies as subsidies_count',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Institution $institution) {
                $health = $institution->health();

                return [
                    'id' => $institution->id,
                    'name' => $institution->name,
                    'slug' => $institution->slug,
                    'type_label' => $institution->typeLabel(),
                    'is_active' => (bool) $institution->is_active,
                    'logo_url' => $institution->logoUrl(),
                    'subscription_plan' => $institution->subscription_plan,
                    'subscription_status' => $institution->subscription_status,
                    'subscription_label' => $institution->subscriptionLabel(),
                    'subscription_tone' => $institution->subscriptionTone(),
                    'subscription_amount' => (float) $institution->subscription_amount,
                    'renews_at' => $institution->subscription_renews_at?->format('j M Y'),
                    'health' => $health,
                    'members_count' => $institution->members_count,
                    'vendors_count' => $institution->vendors_count,
                    'subsidies_count' => $institution->subsidies_count,
                    'member_limit' => $institution->member_limit,
                    'usage_percent' => $institution->usagePercent(),
                    'active_users' => User::query()
                        ->where('institution_id', $institution->id)
                        ->where('status', 'active')
                        ->count(),
                    'last_reviewed_at' => $institution->last_reviewed_at?->format('j M Y'),
                    'health_notes' => $institution->health_notes,
                ];
            })
            ->all();
    }

    /**
     * Subscription revenue per month over the last 6 months, so the SSA can see
     * the money trend rather than a single number.
     */
    private function revenueTrend(): array
    {
        $rows = [];
        $cursor = now()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $month = $cursor->copy()->addMonths($i);

            // Simple model: an institution contributes its MRR for every month
            // from the month it started. This is a plausible, explainable view,
            // not a full billing engine - honest about what it is.
            $revenue = (float) Institution::query()
                ->whereIn('subscription_status', ['paid'])
                ->where(function ($q) use ($month) {
                    $q->whereNull('subscription_started_at')
                        ->orWhereDate('subscription_started_at', '<=', $month->copy()->endOfMonth()->toDateString());
                })
                ->sum('subscription_amount');

            $rows[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'revenue' => round($revenue, 2),
            ];
        }

        return $rows;
    }

    /**
     * Data-throughput / flow volume per month: how much business data the
     * platform is handling (meals, deposits, expenses, new members). Rising
     * throughput with flat revenue is the classic signal that pricing is too low.
     */
    private function throughput(): array
    {
        $rows = [];
        $cursor = now()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $month = $cursor->copy()->addMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $meals = (int) MealEntry::withoutTenantScope()
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->sum(DB::raw('breakfast + lunch + dinner'));

            $deposits = (float) Deposit::withoutTenantScope()
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $expenses = (float) Transaction::withoutTenantScope()
                ->where('type', 'out')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $rows[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'meals' => $meals,
                'deposits' => round($deposits, 2),
                'expenses' => round($expenses, 2),
                'volume' => round($deposits + $expenses, 2),
            ];
        }

        return $rows;
    }

    /**
     * Growth trend: institutions + members added per month, and the month-over-
     * month percentage change, so pricing decisions can be data-driven.
     */
    private function growth(): array
    {
        $rows = [];
        $cursor = now()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $month = $cursor->copy()->addMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $rows[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'new_institutions' => Institution::query()
                    ->whereBetween('created_at', [$start, $end])->count(),
                'new_members' => Student::withoutTenantScope()
                    ->whereBetween('created_at', [$start, $end])->count(),
            ];
        }

        // Month-over-month change on the member count, for the header chip.
        $last = $rows[count($rows) - 1]['new_members'] ?? 0;
        $prev = $rows[count($rows) - 2]['new_members'] ?? 0;
        $mom = $prev > 0 ? round((($last - $prev) / $prev) * 100, 1) : ($last > 0 ? 100.0 : 0.0);

        return [
            'months' => $rows,
            'member_mom_pct' => $mom,
        ];
    }

    /**
     * The busiest institutions by throughput, so the SSA can see which tenants
     * are the heaviest users - and therefore whether they are under-priced.
     */
    private function topInstitutions(): array
    {
        return Institution::query()
            ->withCount(['students as members_count'])
            ->get()
            ->map(function (Institution $institution) {
                $deposits = (float) Deposit::withoutTenantScope()
                    ->where('institution_id', $institution->id)->sum('amount');

                return [
                    'id' => $institution->id,
                    'name' => $institution->name,
                    'members' => $institution->members_count,
                    'deposits' => round($deposits, 2),
                    'subscription_amount' => (float) $institution->subscription_amount,
                ];
            })
            // Rank by throughput, then roster size.
            ->sortByDesc(fn ($row) => $row['deposits'])
            ->take(8)
            ->values()
            ->all();
    }

    /** A small global activity feed for the monitoring panel. */
    private function recentActivity(): array
    {
        return ActivityLog::withoutTenantScope()
            ->with('institution:id,name')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'institution' => $log->institution?->name ?? 'Platform',
                'actor' => $log->user_name,
                'at' => $log->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ *
     * Mutations: subscription management + review
     * ------------------------------------------------------------------ */

    /**
     * Update one institution's subscription + health metadata. SSA only.
     */
    public function updateSubscription(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'subscription_plan' => ['nullable', 'string', 'max:40'],
            'subscription_status' => ['required', 'string', 'in:'.implode(',', array_keys(Institution::SUBSCRIPTION_STATUSES))],
            'subscription_amount' => ['nullable', 'numeric', 'min:0'],
            'subscription_renews_at' => ['nullable', 'date'],
            'member_limit' => ['nullable', 'integer', 'min:0'],
            'health_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $institution->update(array_merge($data, ['last_reviewed_at' => now()]));

        return back()->with('success', "\"{$institution->name}\" subscription updated.");
    }

    /* ------------------------------------------------------------------ *
     * Global audit export
     * ------------------------------------------------------------------ */

    /**
     * Export a cross-institution audit report (Excel or PDF). Every row is
     * stamped with its institution so a platform auditor can trace who did what,
     * where.
     */
    public function exportAudit(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $from = $request->query('from');
        $to = $request->query('to');
        $institutionId = (string) $request->query('institution', '');

        $logs = ActivityLog::withoutTenantScope()
            ->with('institution:id,name')
            ->when($institutionId !== '', fn ($q) => $q->where('institution_id', $institutionId))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'date' => $log->created_at?->format('Y-m-d H:i'),
                'institution' => $log->institution?->name ?? 'Platform',
                'event' => $log->eventLabel,
                'description' => $log->description,
                'actor' => $log->user_name ?? 'System',
                'actor_email' => $log->user_email,
                'subject' => $log->subject_label,
            ]);

        $format = $request->query('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'platform-audit-'.now()->format('Ymd'),
            title: 'Platform-Wide Audit Report',
            columns: [
                'date' => 'When',
                'institution' => 'Institution',
                'event' => 'Event',
                'description' => 'Description',
                'actor' => 'Actor',
                'actor_email' => 'Actor Email',
                'subject' => 'Subject',
            ],
            rows: $logs,
            meta: [
                'Generated' => now()->format('j M Y, H:i'),
                'Range' => ($from ?: 'start').' to '.($to ?: 'now'),
                'Rows' => $logs->count(),
            ],
        );

        ActivityLogController::recordExport($request, 'Platform-Wide Audit Report', ['format' => $format]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
    }
}
