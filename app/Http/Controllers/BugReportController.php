<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * BUG REPORTS.
 *
 * TWO AUDIENCES, ONE MODULE
 * -------------------------
 *   - EVERY tenant role (Member / Meal Manager / Institution Admin) can FILE a
 *     report. The Software Super Admin cannot — they are the recipient, and a
 *     report from the person who owns the backlog would be circular.
 *   - ONLY the Software Super Admin can READ the queue or resolve a report.
 *
 * WHY THE TENANT SIDE NEVER READS THIS TABLE
 * ------------------------------------------
 * A defect in one workspace is not another workspace's business, and the platform
 * owner needs to see ALL of them at once. So the model is deliberately not
 * tenant-scoped (see BugReport's docblock), and access is split by ROUTE:
 * `store` is open to any authenticated non-SSA user, every read is SSA-only.
 *
 * THE REPORT SNAPSHOTS ITS REPORTER
 * ---------------------------------
 * `reporter_name`, `reporter_email` and `reporter_role` are copied onto the row at
 * creation. A report must stay readable months later even if the account was
 * renamed, re-roled or deleted — the whole point is to reproduce a defect that
 * happened to a specific person at a specific moment.
 */
class BugReportController extends Controller
{
    /* ------------------------------------------------------------------ *
     * Tenant side — file a report
     * ------------------------------------------------------------------ */

    /**
     * POST /bug-reports
     *
     * Any authenticated user EXCEPT a Software Super Admin (enforced by the route,
     * and re-asserted here so the rule survives a future route reshuffle).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'The platform owner receives bug reports rather than filing them.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'steps' => ['nullable', 'string', 'max:5000'],
            'severity' => ['nullable', Rule::in(array_keys(BugReport::SEVERITIES))],
            // The screen the user was on. Sent by the client from window.location,
            // so it reflects the ACTUAL page rather than whatever the form thinks.
            'page_url' => ['nullable', 'string', 'max:2048'],
            // A screenshot is the single most useful attachment for a UI defect.
            'screenshot' => ['nullable', 'image', 'max:5120'], // 5 MB
        ]);

        $screenshotPath = null;
        $screenshotName = null;

        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');

            // Stored on the `public` disk under a `bug-reports/` prefix so the
            // uploads are grouped and prunable independently of other media.
            $screenshotPath = $file->store('bug-reports', 'public');
            $screenshotName = $file->getClientOriginalName();
        }

        $report = BugReport::create([
            'user_id' => $user->id,
            'institution_id' => $user->institution_id ?? Institution::current()?->id,
            // Snapshot the reporter, so the row stays readable if the account
            // changes or disappears.
            'reporter_name' => $user->name,
            'reporter_email' => $user->email,
            'reporter_role' => $user->getRoleNames()->first() ?? 'Member',
            'page_url' => $data['page_url'] ?? $request->headers->get('referer') ?? '/',
            'title' => $data['title'],
            'description' => $data['description'],
            'steps' => $data['steps'] ?? null,
            'severity' => $data['severity'] ?? 'normal',
            // The browser string identifies a layout/hydration bug quickly.
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'screenshot_path' => $screenshotPath,
            'screenshot_name' => $screenshotName,
            'status' => 'open',
        ]);

        AuditLogger::log('created', "reported a bug: {$report->title}", $report, [
            'severity' => $report->severity,
            'page_url' => $report->page_url,
            'has_screenshot' => $screenshotPath !== null,
        ], ['subject_label' => $user->name, 'institution_id' => $report->institution_id]);

        // Tell the platform owners. If nobody holds the global role the report is
        // still filed — it simply sits in the inbox unread, which is better than
        // losing the user's input.
        Notifier::bugReported($report);

        return back()->with(
            'success',
            "Thanks - your report was sent to the platform team (ref #{$report->id})."
        );
    }

    /* ------------------------------------------------------------------ *
     * SSA side — triage
     * ------------------------------------------------------------------ */

    /** GET /platform/bug-reports — the triage inbox. */
    public function index(Request $request)
    {
        $status = (string) $request->query('status', 'open');
        $severity = (string) $request->query('severity', '');
        $institutionId = $request->query('institution');

        $reports = BugReport::query()
            ->with(['institution:id,name', 'resolver:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            // URGENT FIRST, then oldest-first within a severity band: the queue is
            // for working through, so the longest-waiting critical report wins.
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (BugReport $r) => $this->present($r));

        $base = fn () => BugReport::query()->when(
            $institutionId,
            fn ($q) => $q->where('institution_id', $institutionId)
        );

        return Inertia::render('SSA/BugReports/Index', [
            'reports' => $reports,
            'stats' => [
                'open' => $base()->where('status', 'open')->count(),
                'acknowledged' => $base()->where('status', 'acknowledged')->count(),
                'resolved' => $base()->where('status', 'resolved')->count(),
                'urgent' => $base()->whereIn('severity', BugReport::URGENT_SEVERITIES)
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->count(),
            ],
            'institutions' => Institution::query()->orderBy('name')->get(['id', 'name']),
            'severities' => collect(BugReport::SEVERITIES)
                ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            'statuses' => collect(BugReport::STATUSES)
                ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            'filters' => [
                'status' => $status,
                'severity' => $severity,
                'institution' => $institutionId,
            ],
        ]);
    }

    /**
     * PATCH /platform/bug-reports/{bugReport}
     *
     * Move a report through its lifecycle. Resolution is a STATUS change, never a
     * delete: the history of what went wrong is itself valuable (a cluster of the
     * same defect in one institution is a signal).
     */
    public function update(Request $request, BugReport $bugReport)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(BugReport::STATUSES))],
            'severity' => ['nullable', Rule::in(array_keys(BugReport::SEVERITIES))],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = ['status' => $data['status']];

        if (! empty($data['severity'])) {
            $attributes['severity'] = $data['severity'];
        }

        // Stamp the resolver only when the report is actually closed out. A move
        // to `acknowledged` is not a resolution and must not claim to be one.
        if (in_array($data['status'], ['resolved', 'dismissed'], true)) {
            $attributes['resolved_by'] = $request->user()->id;
            $attributes['resolved_at'] = now();
            $attributes['resolution_notes'] = $data['resolution_notes'] ?? null;
        } else {
            // Re-opening clears the resolution trail, so a stale note never reads
            // as if it applied to the new state.
            $attributes['resolved_by'] = null;
            $attributes['resolved_at'] = null;
            $attributes['resolution_notes'] = $data['resolution_notes'] ?? null;
        }

        $bugReport->update($attributes);

        AuditLogger::log('updated', "moved bug report #{$bugReport->id} to {$data['status']}", $bugReport, [
            'status' => $data['status'],
            'notes' => $data['resolution_notes'] ?? null,
        ], ['subject_label' => $bugReport->title, 'institution_id' => $bugReport->institution_id]);

        return back()->with('success', "Report #{$bugReport->id} marked as {$data['status']}.");
    }

    /* ------------------------------------------------------------------ */

    /** Serialise a report for the React inbox. */
    protected function present(BugReport $report): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'description' => $report->description,
            'steps' => $report->steps,
            'severity' => $report->severity,
            'severity_label' => $report->severityLabel(),
            'severity_tone' => $report->severityTone(),
            'status' => $report->status,
            'status_label' => $report->statusLabel(),
            'status_tone' => $report->statusTone(),
            'is_urgent' => $report->isUrgent(),
            'page_url' => $report->page_url,
            'user_agent' => $report->user_agent,
            'screenshot_url' => $report->screenshotUrl(),
            'screenshot_name' => $report->screenshot_name,
            'reporter' => [
                'name' => $report->reporter_name,
                'email' => $report->reporter_email,
                'role' => $report->reporter_role,
            ],
            'institution' => $report->institution?->name,
            'institution_id' => $report->institution_id,
            'resolution_notes' => $report->resolution_notes,
            'resolved_by' => $report->resolver?->name,
            'resolved_at' => $report->resolved_at?->format('j M Y, H:i'),
            'created_at' => $report->created_at?->format('j M Y, H:i'),
            'created_human' => $report->created_at?->diffForHumans(),
        ];
    }
}
