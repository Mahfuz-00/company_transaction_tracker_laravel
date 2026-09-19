<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\StaffBroadcast;
use App\Support\AuditLogger;
use App\Support\Notifier;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: Platform Announcements & System Broadcasts.
 *
 * The SSA is the only role that talks to the WHOLE platform at once - a
 * maintenance window, a pricing change, a new feature. This module composes a
 * broadcast, resolves its audience (all staff / all members / everyone), writes
 * an in-app notification to each recipient, and keeps a history of what was
 * sent so the platform's communications are auditable.
 */
class PlatformBroadcastController extends Controller
{
    /** The audience options the SSA can target. */
    public const AUDIENCES = [
        'institution_admins' => 'Institution Admins only',
        'admins' => 'All staff (admins + managers)',
        'members' => 'All members',
        'all' => 'Everyone on the platform',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        return Inertia::render('SSA/Broadcasts', app(TenantManager::class)->runGlobally(function () {
            $history = StaffBroadcast::query()
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (StaffBroadcast $b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'body' => $b->body,
                    'audience' => $b->audience,
                    'audience_label' => self::AUDIENCES[$b->audience] ?? ucfirst($b->audience),
                    'severity' => $b->severity,
                    'recipients' => $b->recipients,
                    'sent_by' => $b->sender?->name,
                    'sent_at' => $b->created_at?->format('j M Y, H:i'),
                    'sent_human' => $b->created_at?->diffForHumans(),
                ]);

            return [
                'history' => $history,
                'audiences' => collect(self::AUDIENCES)
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'severities' => [
                    ['value' => 'info', 'label' => 'Information'],
                    ['value' => 'success', 'label' => 'Update / Good news'],
                    ['value' => 'warning', 'label' => 'Maintenance / Warning'],
                    ['value' => 'critical', 'label' => 'Urgent / Outage'],
                ],
                'stats' => [
                    'total_broadcasts' => StaffBroadcast::query()->count(),
                    'total_recipients' => (int) StaffBroadcast::query()->sum('recipients'),
                    'last_sent' => StaffBroadcast::query()->latest()->first()?->created_at?->diffForHumans(),
                ],
            ];
        }));
    }

    /**
     * Compose and dispatch a platform broadcast.
     *
     * The notification is written to every recipient (in-app bell + list) and a
     * history row records exactly who it went to, so the platform's own comms
     * are auditable alongside every other action.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', Rule::in(array_keys(self::AUDIENCES))],
            'severity' => ['required', Rule::in(['info', 'success', 'warning', 'critical'])],
        ]);

        $actor = $request->user();

        // Compose the stored body with a severity prefix so the recipient sees
        // the weight of the message (e.g. "⚠ Maintenance - ...").
        $prefix = match ($data['severity']) {
            'warning' => '⚠ Maintenance: ',
            'critical' => '🚨 Important: ',
            'success' => '✅ Update: ',
            default => '',
        };

        // Resolve + send. runGlobally so the audience query sees EVERY tenant's
        // users, not just the SSA's (empty) institution scope.
        $sent = app(TenantManager::class)->runGlobally(fn () => Notifier::broadcast(
            $data['audience'],
            $prefix.$data['title'],
            $data['body'],
            $actor,
            'broadcast',
        ));

        $record = StaffBroadcast::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'severity' => $data['severity'],
            'recipients' => $sent['sent'],
            'sent_by' => $actor->id,
        ]);

        AuditLogger::log('announced', "broadcast \"{$data['title']}\" to {$data['audience']}", null, [
            'title' => $data['title'],
            'audience' => $data['audience'],
            'severity' => $data['severity'],
            'recipients' => $sent['sent'],
        ], ['subject_label' => $data['title']]);

        return back()->with('success', "Broadcast sent to {$sent['sent']} recipient(s).");
    }
}
