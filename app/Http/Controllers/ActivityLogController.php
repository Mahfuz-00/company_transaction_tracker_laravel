<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Read-only audit trail browser, strictly institution-based.
 *
 *  - Institution Admins see ONLY their own institution's activity. The scope is
 *    forced from their user record, not from the request, so it cannot be
 *    bypassed with a query parameter.
 *  - Software Super Admins get a GLOBAL view with an institution filter, so they
 *    can inspect one workspace or compare across the platform.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();

        // The institution a non-SSA is hard-locked to. For an SSA the scope is
        // whatever they pick via the institution filter (null = all).
        $lockedInstitutionId = $isSuperAdmin ? null : $user->institution_id;

        $search = trim((string) $request->query('search', ''));
        $event = (string) $request->query('event', '');
        $module = (string) $request->query('module', '');
        $actor = (string) $request->query('actor', '');
        // SSA-only filter; ignored for everyone else because scope is locked.
        $institutionFilter = $isSuperAdmin ? (string) $request->query('institution', '') : '';

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'institution:id,name'])
            // Institution Admins: hard-scoped to their own institution.
            ->when($lockedInstitutionId !== null, fn ($q) => $q->where('institution_id', $lockedInstitutionId))
            // SSA: optional single-institution filter.
            ->when($institutionFilter !== '', fn ($q) => $q->where('institution_id', $institutionFilter))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%' . $search . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('description', 'like', $term)
                        ->orWhere('subject_label', 'like', $term)
                        ->orWhere('user_name', 'like', $term)
                        ->orWhere('user_email', 'like', $term);
                });
            })
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($module !== '', fn ($q) => $q->where('subject_type', 'like', '%' . $module . '%'))
            ->when($actor !== '', fn ($q) => $q->where('user_id', $actor))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        /*
         * Per-institution roll-up for the SSA dashboard: how much activity each
         * workspace generated in the current listing, so a group-by-institution
         * summary can be rendered alongside the flat feed.
         */
        $byInstitution = $isSuperAdmin
            ? ActivityLog::query()
                ->selectRaw('institution_id, COUNT(*) as entries')
                ->whereNotNull('institution_id')
                ->when($institutionFilter !== '', fn ($q) => $q->where('institution_id', $institutionFilter))
                ->groupBy('institution_id')
                ->orderByDesc('entries')
                ->with('institution:id,name')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->institution_id,
                    'name' => $row->institution?->name ?? 'Unknown institution',
                    'entries' => (int) $row->entries,
                ])
            : collect();

        return Inertia::render('Settings/ActivityLog', [
            'logs' => $logs,
            'events' => collect(ActivityLog::EVENTS)
                ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            // SSA-only: the list of institutions for the filter dropdown, plus
            // the per-institution roll-up.
            'institutions' => $isSuperAdmin
                ? Institution::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($i) => ['value' => (string) $i->id, 'label' => $i->name])
                    ->values()
                : [],
            'byInstitution' => $byInstitution,
            // The workspace a non-SSA is locked to, so the UI can say so.
            'scopeInstitution' => $lockedInstitutionId ? [
                'id' => $lockedInstitutionId,
                'name' => Institution::find($lockedInstitutionId)?->name,
            ] : null,
            'filters' => [
                'search' => $search,
                'event' => $event,
                'module' => $module,
                'actor' => $actor,
                'institution' => $institutionFilter,
            ],
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    /**
     * Record a report/dataset export so the trail shows who pulled what data.
     */
    public static function recordExport(Request $request, string $label, array $meta = []): void
    {
        AuditLogger::log(
            'exported',
            'exported ' . $label,
            null,
            $meta,
            ['subject_label' => $label]
        );
    }
}
