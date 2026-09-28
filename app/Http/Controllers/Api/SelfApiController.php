<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * SELF-SERVICE API — the "my account" surface every role uses on mobile.
 *
 * Covers notifications (the bell), the personal theme, and changing your own
 * password. None of it is administrative: every method acts on the CALLER, so
 * there is nothing to authorise beyond being signed in.
 */
class SelfApiController extends Controller
{
    /* ------------------------------------------------------------------ *
     * Notifications
     * ------------------------------------------------------------------ */

    /** GET /api/notifications — the full list, paginated. */
    public function notifications(Request $request)
    {
        $user = $request->user();
        $perPage = min((int) $request->query('per_page', 20), 100);

        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $notifications->through(fn ($n) => $this->presentNotification($n));

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'unread_count' => $user->unreadNotifications()->count(),
                'can_announce' => $user->can('notifications.announce'),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/notifications/latest
     *
     * A small glanceable list for the app's bell / badge, plus the unread count.
     * Deliberately capped at 8 rows.
     */
    public function latestNotifications(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get()
                    ->map(fn ($n) => $this->presentNotification($n))
                    ->all(),
            ],
        ]);
    }

    /** POST /api/notifications/{notification}/read */
    public function markNotificationRead(Request $request, string $notification)
    {
        // whereKey on the USER's own relation: a notification id belonging to
        // someone else simply does not resolve, so this cannot touch another
        // user's data.
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json([
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
            'message' => 'Marked as read.',
        ]);
    }

    /** POST /api/notifications/read-all */
    public function markAllNotificationsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'data' => ['unread_count' => 0],
            'message' => 'All notifications marked as read.',
        ]);
    }

    /** DELETE /api/notifications/{notification} */
    public function deleteNotification(Request $request, string $notification)
    {
        $request->user()->notifications()->whereKey($notification)->delete();

        return response()->json(['message' => 'Notification removed.']);
    }

    /**
     * POST /api/notifications/announce
     *
     * Broadcast to the whole institution. Permission-gated by the route
     * (`notifications.announce`), which only an Institution Admin holds on mobile
     * (the SSA — the other holder on web — is blocked from the API entirely).
     */
    public function announce(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $institution = Institution::current();

        if (! $institution) {
            return response()->json(['message' => 'Select an institution first, then post your announcement.'], 409);
        }

        // An admin may only announce inside their OWN institution.
        if (! $user->belongsToInstitution($institution->id)) {
            return response()->json(['message' => 'You can only post announcements to your own institution.'], 403);
        }

        $count = Notifier::announcement($institution, $data['title'], $data['body'], $user);

        AuditLogger::log('announced', "posted an announcement to {$institution->name}", $institution, [
            'title' => $data['title'],
            'recipients' => $count,
        ], ['subject_label' => $data['title'], 'institution_id' => $institution->id]);

        return response()->json([
            'data' => ['recipients' => $count],
            'message' => "Announcement sent to {$count} user(s).",
        ], 201);
    }

    /* ------------------------------------------------------------------ *
     * Theme (per-user preference)
     * ------------------------------------------------------------------ */

    /** PUT /api/me/theme — set the caller's own theme. */
    public function updateTheme(Request $request)
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:light,dark,system'],
            'accent' => ['nullable', 'string', 'max:30'],
            'density' => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();
        $theme = array_filter($data, fn ($v) => $v !== null);

        // Merged, not replaced: the app may send only the field the user changed.
        $user->forceFill(['theme' => array_merge($user->theme ?? [], $theme)])->save();

        return response()->json([
            'data' => ['theme' => $user->fresh()->theme],
            'message' => 'Theme updated.',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Password
     * ------------------------------------------------------------------ */

    /** PUT /api/auth/password — change your own password. */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Your current password is incorrect.',
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        // The User model guards password writes (the SSA anti-tamper hook); a user
        // changing their OWN password is exactly the case that flag authorises.
        $user->passwordWriteAuthorised = true;
        $user->password = Hash::make($data['password']);
        $user->save();

        AuditLogger::log('updated', 'changed their own password', $user);

        // Every OTHER device is signed out; the calling token survives, so the
        // user is not ejected from the app they just used to change it.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json(['message' => 'Password updated. Other devices were signed out.']);
    }

    /* ------------------------------------------------------------------ */

    /** Normalise a notification row — identical shape to the web bell. */
    protected function presentNotification($notification): array
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
