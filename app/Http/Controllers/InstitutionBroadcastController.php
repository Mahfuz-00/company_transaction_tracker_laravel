<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\InstitutionBroadcast;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * INSTITUTION-SCOPED BROADCASTS (Institution Admin).
 *
 * The tenant-level counterpart to the SSA's platform broadcast. It composes a
 * notice, sends it to a selected audience WITHIN ONE institution, and keeps a
 * history of what was sent.
 *
 * ISOLATION - the whole point of this module
 * ------------------------------------------
 * Three independent guarantees stop a workspace broadcast reaching anyone else:
 *
 *   1. The target institution is resolved from the ACTOR (their switched session
 *      tenant, else their own `institution_id`) - NEVER from request input. A
 *      crafted request cannot point the broadcast at another workspace.
 *   2. Recipients come from `Notifier::institutionBroadcast()`, which queries
 *      `where('institution_id', $institution->id)`. The SSA's method
 *      (`Notifier::broadcast()` / `platformAudience()`, which has NO institution
 *      filter) is never called here.
 *   3. The history table is tenant-owned (`InstitutionBroadcast` uses
 *      `BelongsToInstitution`), and the history query additionally pins the
 *      institution explicitly via `withoutTenantScope()->where(...)` so an SSA
 *      viewing in GLOBAL (unscoped) mode still sees only the selected tenant.
 *
 * Access is gated by the existing `notifications.announce` permission, which the
 * Institution Admin holds and the Meal Manager / Member do not.
 */
class InstitutionBroadcastController extends Controller
{
    /** Severity options the composer offers. */
    public const SEVERITIES = ['info', 'success', 'warning', 'critical'];

    /** The broadcast history + composer for the acting institution. */
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()->can('notifications.announce'),
            403,
            'You are not allowed to send broadcasts.'
        );

        $institution = $this->resolveInstitution($request);

        $history = $this->historyQuery($institution)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (InstitutionBroadcast $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'body' => $b->body,
                'audience' => $b->audience,
                'audience_label' => $b->audienceLabel(),
                'severity' => $b->severity,
                'recipients' => $b->recipients,
                'sent_by' => $b->sender?->name,
                'sent_at' => $b->created_at?->format('j M Y, H:i'),
                'sent_human' => $b->created_at?->diffForHumans(),
            ]);

        return Inertia::render('InstitutionAdmin/Broadcasts', [
            'institution' => [
                'id' => $institution->id,
                'name' => $institution->name,
            ],
            'history' => $history,
            'audiences' => collect(InstitutionBroadcast::AUDIENCES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'severities' => [
                ['value' => 'info', 'label' => 'Information'],
                ['value' => 'success', 'label' => 'Update / Good news'],
                ['value' => 'warning', 'label' => 'Maintenance / Warning'],
                ['value' => 'critical', 'label' => 'Urgent / Outage'],
            ],
            'stats' => [
                'total_broadcasts' => $this->historyQuery($institution)->count(),
                'total_recipients' => (int) $this->historyQuery($institution)->sum('recipients'),
                'last_sent' => $this->historyQuery($institution)->latest()->first()?->created_at?->diffForHumans(),
            ],
        ]);
    }

    /** Compose + dispatch a broadcast to the acting institution only. */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('notifications.announce'), 403);

        // Resolved from the actor, before validation, so an unauthorised or
        // institution-less caller never even reaches validation.
        $institution = $this->resolveInstitution($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', Rule::in(array_keys(InstitutionBroadcast::AUDIENCES))],
            'severity' => ['required', Rule::in(self::SEVERITIES)],
        ]);

        $actor = $request->user();

        // A severity prefix so the recipient feels the weight of the message.
        $prefix = match ($data['severity']) {
            'warning' => '⚠ Maintenance: ',
            'critical' => '🚨 Important: ',
            'success' => '✅ Update: ',
            default => '',
        };

        /*
         * STRICTLY SCOPED DISPATCH.
         *
         * The recipients are resolved from THIS institution alone. This is the
         * single line that separates this feature from the SSA's platform-wide
         * broadcast - never call Notifier::broadcast() here.
         */
        $sent = Notifier::institutionBroadcast(
            $data['audience'],
            $institution,
            $prefix . $data['title'],
            $data['body'],
            $actor,
        );

        // The trait also stamps institution_id; set it explicitly so the intent
        // is obvious at the call site.
        $record = InstitutionBroadcast::create([
            'institution_id' => $institution->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'severity' => $data['severity'],
            'recipients' => $sent,
            'sent_by' => $actor->id,
        ]);

        AuditLogger::log(
            'announced',
            "broadcast \"{$record->title}\" to {$data['audience']} within {$institution->name}",
            $institution,
            [
                'title' => $data['title'],
                'audience' => $data['audience'],
                'severity' => $data['severity'],
                'recipients' => $sent,
            ],
        );

        /*
         * Flash EXPLICITLY onto the session rather than relying solely on
         * `back()->with(...)`. The Feature and Dusk assertions read
         * `assertSessionHas('success')`, and an explicit flash guarantees the key
         * is present no matter how the redirect target is resolved from the
         * Referer.
         */
        $request->session()->flash('success', "Broadcast sent to {$sent} recipient(s) in {$institution->name}.");

        return back();
    }

    /**
     * Resolve the institution this request acts inside - ALWAYS from the actor.
     *
     * A switched-in Software Super Admin uses their session tenant; everyone
     * else uses their own bound `institution_id`. Request input is never
     * consulted, so the scope cannot be widened by a crafted payload or query
     * string.
     */
    protected function resolveInstitution(Request $request): Institution
    {
        $user = $request->user();

        $institutionId = Institution::sessionTenantId() ?? $user->institution_id;

        $institution = $institutionId
            ? Institution::query()->find($institutionId)
            : null;

        abort_if($institution === null, 403, 'No institution is active. Switch into a workspace first.');

        // Defence in depth on top of the permission gate: the actor must really
        // belong to (or have switched into) this institution.
        abort_unless($user->belongsToInstitution($institution->id), 403);

        return $institution;
    }

    /**
     * Base query for this institution's history, pinned explicitly.
     *
     * `withoutTenantScope()` removes the automatic scope and we re-add an exact
     * `institution_id` match. That matters for an SSA whose context is GLOBAL:
     * with no tenant, the automatic scope adds no clause, so relying on it alone
     * would list every institution's broadcasts. The explicit pin keeps the
     * read isolated in every context.
     */
    protected function historyQuery(Institution $institution): Builder
    {
        return InstitutionBroadcast::withoutTenantScope()
            ->where('institution_id', $institution->id);
    }
}
