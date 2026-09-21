<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Institution Invite Code Manager.
 *
 * WHAT THIS FIXES
 * ---------------
 * The invite code already existed end-to-end in the backend:
 *   - `Institution::generateInviteCode()` creates one on first save,
 *   - `Institution::findByInviteCode()` resolves it at signup,
 *   - `RegisteredUserController::store()` maps a self-signup to the institution
 *     the code points at.
 *
 * The missing piece was STAFF-SIDE: an Institution Admin had no screen to SEE
 * their workspace's code or ROTATE it. Without a visible value they could not
 * hand it to members, so the whole invite-code onboarding path was unreachable.
 *
 * TENANCY: the code belongs to ONE institution, resolved from the ACTIVE tenant
 * (Institution::current()) exactly like the currency/institution settings. An
 * Institution Admin can only ever see - and rotate - their OWN workspace's code.
 * An SSA may manage the code of the workspace they have switched into.
 */
class InviteCodeController extends Controller
{
    /**
     * Show the workspace's invite code together with a ready-to-share signup
     * link (GET /register?code=XXXX).
     */
    public function show(Request $request): Response
    {
        $institution = Institution::current();

        abort_unless($institution !== null, 404, 'No active institution to show an invite code for.');

        // Defensive, explicit tenancy assertion on top of the route permission.
        abort_unless(
            $this->mayManage($request, $institution),
            403,
            'You may only view your own institution\'s invite code.'
        );

        // Backfill a code for legacy rows that predate the invite-code column,
        // so an admin always has something to share.
        if (blank($institution->invite_code)) {
            $institution->regenerateInviteCode();
        }

        return Inertia::render('Settings/InviteCode', [
            'institution' => [
                'id' => $institution->id,
                'name' => $institution->name,
                'slug' => $institution->slug,
            ],
            'inviteCode' => $institution->invite_code,
            // The public signup URL a member can click, pre-filling the code.
            'registerUrl' => route('register', ['code' => $institution->invite_code], false),
            'canManage' => $request->user()->isSuperAdmin()
                || $request->user()->can('institution.manage'),
            // How many members already joined through a code (informational).
            'memberCount' => $institution->students()->count(),
        ]);
    }

    /**
     * Rotate the code, revoking any previously shared signup link.
     */
    public function regenerate(Request $request)
    {
        $institution = Institution::current();

        abort_unless($institution !== null, 404, 'No active institution to rotate a code for.');

        abort_unless(
            $this->mayManage($request, $institution),
            403,
            'You may only rotate your own institution\'s invite code.'
        );

        $old = $institution->invite_code;
        $new = $institution->regenerateInviteCode();

        AuditLogger::log('updated', "rotated the invite code for \"{$institution->name}\"", $institution, [
            'old_code' => $old,
            'new_code' => $new,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with('success', 'Invite code regenerated. The previous code no longer works.');
    }

    /**
     * May this user manage the given institution's invite code?
     *
     * A Software Super Admin has global reach; everyone else must be hard-bound
     * to the institution in question (mirrors User::belongsToInstitution()).
     */
    protected function mayManage(Request $request, Institution $institution): bool
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->institution_id !== null
            && (int) $user->institution_id === (int) $institution->id;
    }
}
