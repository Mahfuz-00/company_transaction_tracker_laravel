<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Read-only audit trail browser. Visible to Software Super Admins (everything)
 * and Institution Admins (their own institution only).
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        $search = trim((string) $request->query('search', ''));
        $event = (string) $request->query('event', '');
        $module = (string) $request->query('module', '');
        $actor = (string) $request->query('actor', '');

        $logs = ActivityLog::query()
            ->with(['user:id,name,email'])
            // Institution Admins only see their own institution's history.
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('institution_id', $institution?->id))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('description', 'like', $term)
                        ->orWhere('subject_label', 'like', $term)
                        ->orWhere('user_name', 'like', $term)
                        ->orWhere('user_email', 'like', $term);
                });
            })
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($module !== '', fn ($q) => $q->where('subject_type', 'like', '%'.$module.'%'))
            ->when($actor !== '', fn ($q) => $q->where('user_id', $actor))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Settings/ActivityLog', [
            'logs' => $logs,
            'events' => collect(ActivityLog::EVENTS)
                ->map(fn ($meta, $key) => ['value' => $key, 'label' => $meta['label']])
                ->values(),
            'filters' => [
                'search' => $search,
                'event' => $event,
                'module' => $module,
                'actor' => $actor,
            ],
            'isSuperAdmin' => $user->isSuperAdmin(),
        ]);
    }

    /**
     * Record a report/dataset export so the trail shows who pulled what data.
     */
    public static function recordExport(Request $request, string $label, array $meta = []): void
    {
        AuditLogger::log(
            'exported',
            'exported '.$label,
            null,
            $meta,
            ['subject_label' => $label]
        );
    }
}
