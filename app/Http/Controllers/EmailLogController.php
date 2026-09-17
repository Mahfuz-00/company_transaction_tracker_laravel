<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\Institution;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Email Log / Outbox.
 *
 * Every email the platform dispatches is recorded by the LogSentEmail listener.
 * This controller exposes that log with strict scoping:
 *
 *   - Software Super Admin : the whole platform (all institutions).
 *   - Institution Admin    : their own institution.
 *   - Meal Manager         : their own institution (they run the members who
 *                            generate most invitations), read-only.
 *
 * The full rendered HTML body is available per row, so an admin can open an
 * email exactly as the recipient received it.
 */
class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();

        // Non-SSAs are locked to their own institution, from their record (not a
        // query param), so the scope cannot be widened by the client.
        $lockedInstitutionId = $isSuperAdmin ? null : $user->institution_id;

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $kind = (string) $request->query('kind', '');
        // SSA-only institution filter.
        $institutionFilter = $isSuperAdmin ? (string) $request->query('institution', '') : '';

        $logs = EmailLog::query()
            ->with(['institution:id,name', 'user:id,name,email'])
            ->when($lockedInstitutionId !== null, fn ($q) => $q->where('institution_id', $lockedInstitutionId))
            ->when($institutionFilter !== '', fn ($q) => $q->where('institution_id', $institutionFilter))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('to', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('from', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EmailLog $log) => $this->present($log, includeBody: false));

        return Inertia::render('Settings/EmailLog', [
            'logs' => $logs,
            'stats' => [
                'total' => $this->scoped($user)->count(),
                'sent' => $this->scoped($user)->where('status', 'sent')->count(),
                'failed' => $this->scoped($user)->where('status', 'failed')->count(),
                'pending' => $this->scoped($user)->where('status', 'pending')->count(),
            ],
            'kinds' => $this->scoped($user)
                ->select('kind')->distinct()->pluck('kind')
                ->map(fn ($k) => ['value' => $k, 'label' => ucfirst(str_replace('_', ' ', $k))])
                ->values(),
            'institutions' => $isSuperAdmin
                ? Institution::orderBy('name')->get(['id', 'name'])
                    ->map(fn ($i) => ['value' => (string) $i->id, 'label' => $i->name])
                    ->values()
                : [],
            'isSuperAdmin' => $isSuperAdmin,
            'scopeInstitution' => $lockedInstitutionId
                ? ['id' => $lockedInstitutionId, 'name' => Institution::find($lockedInstitutionId)?->name]
                : null,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'kind' => $kind,
                'institution' => $institutionFilter,
            ],
        ]);
    }

    /**
     * The full rendered email body for one row (loaded on demand by the viewer).
     */
    public function show(Request $request, EmailLog $emailLog)
    {
        if (! $this->canView($request, $emailLog)) {
            abort(403, 'This email belongs to another institution.');
        }

        return response()->json([
            'log' => $this->present($emailLog, includeBody: true),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** Base scoped query for the acting user. */
    protected function scoped($user)
    {
        return EmailLog::query()->when(
            ! $user->isSuperAdmin(),
            fn ($q) => $q->where('institution_id', $user->institution_id)
        );
    }

    /** May this user open the given email? */
    protected function canView(Request $request, EmailLog $log): bool
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $log->institution_id !== null
            && (int) $log->institution_id === (int) $user->institution_id;
    }

    /** Serialise a row for the UI. */
    protected function present(EmailLog $log, bool $includeBody = false): array
    {
        $payload = [
            'id' => $log->id,
            'to' => $log->to,
            'from' => $log->from,
            'cc' => $log->cc,
            'bcc' => $log->bcc,
            'subject' => $log->subject,
            'kind' => $log->kind,
            'kind_label' => ucfirst(str_replace('_', ' ', $log->kind)),
            'mailable' => $log->mailable,
            'status' => $log->status,
            'status_label' => $log->statusLabel(),
            'error' => $log->error,
            'created_at' => $log->created_at?->format('j M Y, H:i'),
            'created_human' => $log->created_at?->diffForHumans(),
            'sent_at' => $log->sent_at?->format('j M Y, H:i'),
            'institution' => $log->institution?->name,
            'triggered_by' => $log->user?->name,
        ];

        if ($includeBody) {
            $payload['body'] = $log->body;
            $payload['text_body'] = $log->text_body;
        }

        return $payload;
    }
}
