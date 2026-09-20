<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\Institution;
use App\Support\ReportExporter;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: GLOBAL System Audit & Security Log.
 *
 * A centralized, CROSS-TENANT view of everything that happened on the platform,
 * filterable by institution, actor, event, subject module, severity and free
 * text. Built for security compliance: an SSA can trace who did what, where,
 * and when - across every tenant - and export the filtered slice.
 *
 * SEVERITY is derived (no schema change needed) from the event type:
 *   - critical : hard failures / security events (failed email, repeated errors)
 *   - warning  : destructive-but-audited events (deleted, reversed)
 *   - info     : routine writes (created, updated, invited, exported)
 *   - notice   : low-signal (login, logout)
 */
class GlobalAuditController extends Controller
{
    /** Event -> severity mapping, single source of truth for the UI + filters. */
    public const SEVERITIES = [
        'critical' => ['label' => 'Critical', 'tone' => 'rose'],
        'warning' => ['label' => 'Warning', 'tone' => 'amber'],
        'info' => ['label' => 'Info', 'tone' => 'sky'],
        'notice' => ['label' => 'Notice', 'tone' => 'slate'],
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        $search = trim((string) $request->query('search', ''));
        $institutionId = (string) $request->query('institution', '');
        $event = (string) $request->query('event', '');
        $severity = (string) $request->query('severity', '');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('SSA/Audit', app(TenantManager::class)->runGlobally(function () use (
            $search,
            $institutionId,
            $event,
            $severity,
            $from,
            $to
        ) {
            $eventsForSeverity = $this->eventsForSeverity($severity);

            $base = fn () => ActivityLog::withoutTenantScope()
                ->when($institutionId !== '', fn ($q) => $q->where('institution_id', $institutionId))
                ->when($event !== '', fn ($q) => $q->where('event', $event))
                ->when($severity !== '', fn ($q) => $q->whereIn('event', $eventsForSeverity))
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->when($search !== '', function ($q) use ($search) {
                    $term = '%' . $search . '%';
                    $q->where(function ($sub) use ($term) {
                        $sub->where('description', 'like', $term)
                            ->orWhere('subject_label', 'like', $term)
                            ->orWhere('user_name', 'like', $term)
                            ->orWhere('user_email', 'like', $term)
                            ->orWhere('ip_address', 'like', $term);
                    });
                });

            $logs = $base()
                ->with(['institution:id,name', 'user:id,name,email'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(40)
                ->withQueryString()
                ->through(fn (ActivityLog $log) => $this->present($log));

            // Severity + per-institution roll-ups for the summary strip.
            $severityCounts = [
                'critical' => (clone $base())->whereIn('event', $this->eventsForSeverity('critical'))->count(),
                'warning' => (clone $base())->whereIn('event', $this->eventsForSeverity('warning'))->count(),
                'info' => (clone $base())->whereIn('event', $this->eventsForSeverity('info'))->count(),
                'notice' => (clone $base())->whereIn('event', $this->eventsForSeverity('notice'))->count(),
            ];

            // Failed emails are a first-class security signal.
            $failedEmails = EmailLog::withoutTenantScope()
                ->when($institutionId !== '', fn ($q) => $q->where('institution_id', $institutionId))
                ->where('status', 'failed')
                ->count();

            $byInstitution = ActivityLog::withoutTenantScope()
                ->selectRaw('institution_id, COUNT(*) as entries')
                ->whereNotNull('institution_id')
                ->groupBy('institution_id')
                ->orderByDesc('entries')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->institution_id,
                    'name' => Institution::query()->whereKey($row->institution_id)->value('name') ?? 'Unknown',
                    'entries' => (int) $row->entries,
                ]);

            return [
                'logs' => $logs,
                'severityCounts' => $severityCounts,
                'failedEmails' => $failedEmails,
                'byInstitution' => $byInstitution,
                'institutions' => Institution::query()->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($i) => ['value' => (string) $i->id, 'label' => $i->name])
                    ->values(),
                'events' => collect(ActivityLog::EVENTS)
                    ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                    ->values(),
                'severities' => collect(self::SEVERITIES)
                    ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label'], 'tone' => $meta['tone']])
                    ->values(),
                'filters' => [
                    'search' => $search,
                    'institution' => $institutionId,
                    'event' => $event,
                    'severity' => $severity,
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        }));
    }

    /** Which raw events belong to a given severity bucket. */
    protected function eventsForSeverity(string $severity): array
    {
        return match ($severity) {
            'warning' => ['deleted', 'reversed'],
            'info' => ['created', 'updated', 'invited', 'accepted', 'exported', 'announced'],
            'notice' => ['login', 'logout'],
            'critical' => ['failed', 'error', 'security'],
            default => [],
        };
    }

    /** Derive a severity key for one log row. */
    protected function severityOf(ActivityLog $log): string
    {
        return match (true) {
            in_array($log->event, ['deleted', 'reversed'], true) => 'warning',
            in_array($log->event, ['login', 'logout'], true) => 'notice',
            in_array($log->event, ['failed', 'error', 'security'], true) => 'critical',
            default => 'info',
        };
    }

    /** Export the (filtered) global audit slice as Excel or PDF. */
    public function export(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $institutionId = (string) $request->query('institution', '');
        $event = (string) $request->query('event', '');
        $severity = (string) $request->query('severity', '');
        $from = $request->query('from');
        $to = $request->query('to');

        $rows = ActivityLog::withoutTenantScope()
            ->with('institution:id,name')
            ->when($institutionId !== '', fn ($q) => $q->where('institution_id', $institutionId))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($severity !== '', fn ($q) => $q->whereIn('event', $this->eventsForSeverity($severity)))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'when' => $log->created_at?->format('Y-m-d H:i'),
                'severity' => ucfirst($this->severityOf($log)),
                'institution' => $log->institution?->name ?? 'Platform',
                'event' => $log->eventLabel,
                'description' => $log->description,
                'actor' => $log->user_name ?? 'System',
                'actor_email' => $log->user_email,
                'ip' => $log->ip_address,
            ]);

        $format = $request->query('format', 'excel');

        $exporter = new ReportExporter(
            filename: 'global-audit-' . now()->format('Ymd'),
            title: 'Global System Audit & Security Log',
            columns: [
                'when' => 'When',
                'severity' => 'Severity',
                'institution' => 'Institution',
                'event' => 'Event',
                'description' => 'Description',
                'actor' => 'Actor',
                'actor_email' => 'Actor Email',
                'ip' => 'IP Address',
            ],
            rows: $rows,
            meta: [
                'Generated' => now()->format('j M Y, H:i'),
                'Rows' => $rows->count(),
            ],
        );

        ActivityLogController::recordExport($request, 'Global System Audit', ['format' => $format]);

        return $format === 'pdf' ? $exporter->pdf() : $exporter->excel();
    }

    /** Serialise one row for the UI. */
    protected function present(ActivityLog $log): array
    {
        $severity = $this->severityOf($log);

        return [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => $log->eventLabel,
            'severity' => $severity,
            'severity_label' => self::SEVERITIES[$severity]['label'],
            'severity_tone' => self::SEVERITIES[$severity]['tone'],
            'description' => $log->description,
            'subject_label' => $log->subject_label,
            'module' => $log->module,
            'institution' => $log->institution?->name ?? 'Platform',
            'institution_id' => $log->institution_id,
            'actor' => $log->user_name ?? 'System',
            'actor_email' => $log->user_email,
            'ip' => $log->ip_address,
            'created_at' => $log->created_at?->format('j M Y, H:i'),
            'created_human' => $log->created_at?->diffForHumans(),
        ];
    }
}
