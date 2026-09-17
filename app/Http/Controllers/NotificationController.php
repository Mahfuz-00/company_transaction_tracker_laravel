<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * In-app notifications for every role.
 *
 * A user only ever sees their OWN notifications (the notifiable morph scopes it).
 * Institution Admins additionally get a composer to broadcast an announcement to
 * their institution.
 */
class NotificationController extends Controller
{
    /** The full notifications page. */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($n) => $this->present($n));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'canAnnounce' => $user->can('notifications.announce'),
        ]);
    }

    /**
     * The latest notifications for the header bell (polled / revalidated by the
     * frontend). Kept small - a glanceable list, not the full page.
     */
    public function latest(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unreadCount' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn ($n) => $this->present($n))
                ->all(),
        ]);
    }

    /** Mark a single notification as read. */
    public function markRead(Request $request, string $notification)
    {
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return back();
    }

    /** Mark every notification as read. */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }

    /** Delete a single notification. */
    public function destroy(Request $request, string $notification)
    {
        $request->user()->notifications()->whereKey($notification)->delete();

        return back();
    }

    /**
     * Broadcast an announcement to the whole institution. Institution Admins
     * (scoped) and Software Super Admins (active workspace) may do this.
     */
    public function announce(Request $request)
    {
        $user = $request->user();

        if (! $user->can('notifications.announce')) {
            return back()->with('error', 'You are not allowed to post announcements.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $institution = Institution::current();

        if (! $institution) {
            return back()->with('error', 'Select an institution first (use Access Dashboard), then post your announcement.');
        }

        // An Institution Admin may only announce within their own institution.
        if (! $user->belongsToInstitution($institution->id)) {
            return back()->with('error', 'You can only post announcements to your own institution.');
        }

        $count = Notifier::announcement($institution, $data['title'], $data['body'], $user);

        AuditLogger::log('announced', "posted an announcement to {$institution->name}", $institution, [
            'title' => $data['title'],
            'recipients' => $count,
        ], ['subject_label' => $data['title'], 'institution_id' => $institution->id]);

        return back()->with('success', "Announcement sent to {$count} user(s).");
    }

    /** Normalise a notification row for the UI. */
    protected function present($notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'kind' => $data['kind'] ?? 'notice',
            'title' => $data['title'] ?? 'Notification',
            'body' => $data['body'] ?? '',
            'url' => $data['meta']['url'] ?? null,
            'amount' => $data['meta']['amount'] ?? null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'created_human' => $notification->created_at?->diffForHumans(),
        ];
    }
}
