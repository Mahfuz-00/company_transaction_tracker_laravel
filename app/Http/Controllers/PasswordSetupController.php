<?php

namespace App\Http\Controllers;

use App\Mail\MemberWelcomeMail;
use App\Models\MemberInvitation;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MemberProfileSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Password setup from a signed link.
 *
 * One controller handles both states described in the spec:
 *
 *   INCOMPLETE - no user row exists yet (a first-time invitation). Clicking the
 *                link creates the account and sets the password.
 *   COMPLETE   - a user row already exists (re-invited, or an admin asked to
 *                reset). Clicking the link updates the existing password instead
 *                of creating a duplicate.
 *
 * The decision is made at accept-time by looking up the invitation's email:
 *   - user exists  -> password RESET for that user (and link the member record).
 *   - user absent  -> CREATE the user, then set the password.
 *
 * The GET is protected by Laravel's `signed` middleware (tamper-proof URL); the
 * POST re-validates the opaque token (hash_equals) as defence in depth.
 */
class PasswordSetupController extends Controller
{
    /**
     * Show the password setup screen for a signed invitation link.
     */
    public function show(Request $request, MemberInvitation $invitation)
    {
        $token = (string) $request->query('token', '');
        $failure = $this->validateInvitation($invitation, $token);

        if ($failure) {
            return redirect()->route('login')->with('error', $failure);
        }

        // TWO-STEP STATE: does the basic account already exist?
        $existing = User::where('email', $invitation->email)->first();

        // The name we show in the field, in priority order:
        //   1. the real user's name (most accurate - a previously-saved edit),
        //   2. the name the admin typed on the invitation,
        //   3. nothing (a fresh invitee types it).
        $displayName = $existing?->name ?: $invitation->name;

        return Inertia::render('Auth/PasswordSetup', [
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $displayName,
                'role' => $invitation->role,
            ],
            'token' => $token,
            'institutionName' => $invitation->institution?->name,
            // Drives the copy: "Set up your account" vs "Set a new password".
            'mode' => $existing ? 'reset' : 'create',
            // The name field is ALWAYS shown and editable, so a placeholder
            // ("md. samiul islam") can be corrected to the real name during setup.
            'prefillName' => filled($displayName),
        ]);
    }

    /**
     * Complete setup: create or update the user, set the password, retire the
     * invitation.
     */
    public function store(Request $request, MemberInvitation $invitation)
    {
        $token = (string) $request->input('token', '');
        $failure = $this->validateInvitation($invitation, $token);

        if ($failure) {
            return redirect()->route('login')->with('error', $failure);
        }

        $existing = User::where('email', $invitation->email)->first();

        /*
         * The name is validated in BOTH states, not just for a new invitee.
         * This is the fix for the reported bug: an invited user editing the
         * prefilled placeholder name ("md. samiul islam" -> "md. Abdullah al
         * mahfuz") had their input silently discarded because the field was
         * neither validated nor written on the existing-user path.
         *
         *  - a brand-new invitee MUST supply a name,
         *  - an existing user MAY change theirs, and it is persisted if sent.
         */
        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
        ];

        $data = $request->validate($rules);

        $user = DB::transaction(function () use ($data, $invitation, $existing) {
            // Resolve the name to persist: the submitted value when given,
            // otherwise fall back to the existing name / the invited name.
            $name = filled($data['name'] ?? null)
                ? trim($data['name'])
                : ($existing?->name ?: $invitation->name);

            if ($existing) {
                // Defensive: if this account somehow already holds the global role
                // from a prior bug, strip it. An invited member must never keep
                // super-admin privileges.
                if ($existing->isSuperAdmin()) {
                    $existing->removeRole('Software Super Admin');
                }

                // This is an AUTHORISED credential write: the invitee followed a
                // signed link and set their own password. Marking the write
                // authorised lets it through the model's SSA safety net and keeps
                // `password_changed_at` in step.
                $existing->passwordWriteAuthorised = true;

                // ---- COMPLETE STATE: update password AND name together. ----
                $existing->forceFill([
                    'name' => $name,
                    'password' => Hash::make($data['password']),
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                    'setup_completed_at' => $existing->setup_completed_at ?? now(),
                    'status' => 'active',
                    // Finalise tenancy: backfill the institution if the invited
                    // user somehow had none (prevents orphaned, ungrouped rows).
                    'institution_id' => $existing->institution_id ?: $invitation->institution_id,
                ])->save();

                $user = $existing;
            } else {
                // ---- INCOMPLETE STATE: create the record now. ----
                $user = User::create([
                    // Scope is taken from the invitation, so the created account
                    // always belongs to the inviting institution.
                    'institution_id' => $invitation->institution_id,
                    'name' => $name,
                    'email' => $invitation->email,
                    'status' => 'active',
                    'invitation_pending' => false,
                    'must_change_password' => false,
                    'password' => Hash::make($data['password']),
                    'password_changed_at' => now(),
                    'setup_completed_at' => now(),
                ]);

                // Grant the invited role through the SAFE guard: an invitation can
                // only ever confer an institution-scoped role, never a global one.
                // A stray/crafted "Software Super Admin" collapses to Member.
                $user->assignInstitutionRole($invitation->role);
            }

            // Link the member record to this login, if the invite targeted one.
            // Only a same-institution member record may be attached (no
            // cross-tenant linking).
            if ($invitation->student_id) {
                Student::query()
                    ->whereKey($invitation->student_id)
                    ->when(
                        $invitation->institution_id,
                        fn ($q) => $q->where(function ($sub) use ($invitation) {
                            $sub->where('institution_id', $invitation->institution_id)
                                ->orWhereNull('institution_id');
                        })
                    )
                    ->update(['user_id' => $user->id]);
            }

            /*
             * NAME SYNC (single source of truth).
             *
             * The member set their REAL name here; it must land on BOTH the user
             * account AND the linked Member roster record (and the invitation), so
             * the roster never keeps the admin's placeholder. The synchroniser
             * does exactly that, atomically, and also heals the user_id link.
             */
            MemberProfileSynchronizer::syncName($user, $name);

            // Retire the invitation now that setup is complete.
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        AuditLogger::log('accepted', 'completed password setup for '.$invitation->email, $invitation, [
            'user_id' => $user->id,
            'state' => $existing ? 'complete' : 'incomplete',
            'name' => $user->name,
        ], ['subject_label' => $invitation->email, 'institution_id' => $invitation->institution_id]);

        /*
         * AUTOMATED MEMBER WELCOME EMAIL.
         *
         * Sent only on a FIRST-TIME setup ($existing === null) - a genuine new
         * account activation. We deliberately do NOT send it when an existing
         * user merely reset their password, since that is not a "welcome aboard"
         * moment and would be noise.
         *
         * Failures are swallowed: a mail problem must never undo a completed
         * account setup.
         */
        if (! $existing) {
            try {
                Mail::to($user->email)->send(
                    new MemberWelcomeMail($user, $invitation->institution)
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        /*
         * SECURE SESSION HANDLING.
         *
         * The person completing setup may be signed in already (e.g. an admin
         * resetting a password from within the app, or a stale session for the
         * invited account). A password change must invalidate that session so the
         * user is forced to authenticate fresh with the new credentials - this
         * prevents a hijacked/stale cookie from keeping access after a reset.
         *
         * We: log out of the guard, invalidate the session (ID + data),
         * regenerate the CSRF token, and forget the remember-me cookie.
         */
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Drop any "remember me" cookie so the old token cannot silently restore
        // the session we just cleared.
        Cookie::queue(Cookie::forget(Auth::guard('web')->getRecallerName()));
        Cookie::queue(Cookie::forget(config('session.cookie')));

        return redirect()
            ->route('login')
            ->with('success', 'Your password is set. Please sign in with your new credentials.');
    }

    /**
     * The same validation the older invitation controller used: opaque token
     * match + not already used + not expired.
     */
    protected function validateInvitation(MemberInvitation $invitation, string $token): ?string
    {
        if ($token === '' || ! hash_equals($invitation->token, hash('sha256', $token))) {
            return 'This setup link is not valid.';
        }

        if ($invitation->isAccepted()) {
            return 'This link has already been used.';
        }

        if ($invitation->isExpired()) {
            return 'This link has expired. Please ask an admin to send a new one.';
        }

        return null;
    }
}
