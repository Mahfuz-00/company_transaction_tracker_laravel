<?php

namespace App\Http\Controllers;

use App\Mail\MemberInvitationMail;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Member invitation workflow.
 *
 * An admin supplies a member's email; we email a signed link. The member sets
 * their own password, which creates (or attaches) their user account and links
 * it to the member record. No default password is ever generated.
 */
class MemberInvitationController extends Controller
{
    /**
     * Issue an invitation for a member record (or a bare email).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'student_id' => ['nullable', 'exists:students,id'],
            'role' => ['nullable', 'string', 'max:60'],
        ]);

        $institution = Institution::current();

        // An invitation is meaningless without a workspace, so refuse early
        // rather than creating an ungrouped, orphaned row.
        if (! $institution) {
            return back()->with('error', 'Select an institution first, then invite its members.');
        }

        // TWO-STEP STATE: an email may already belong to a user (complete) or be
        // brand new (incomplete). Both are valid invitation targets - an existing
        // user simply gets a password-RESET link instead of a first-time setup.
        $existingUser = User::where('email', $data['email'])->first();

        // Tenancy guard: an existing user from another institution cannot be
        // re-invited into this one.
        if ($existingUser && $existingUser->institution_id
            && (int) $existingUser->institution_id !== (int) $institution->id) {
            return back()->with('error', 'That email already belongs to a user in another institution.');
        }

        /*
         * Member-record guard. When an invite targets a member record:
         *  - the record must belong to THIS institution (no cross-tenant attach),
         *  - and if it is already linked to a login, inviting the same email is a
         *    no-op reset rather than a second account. We check the email matches
         *    so an invite can never silently re-point an existing login.
         */
        if (! empty($data['student_id'])) {
            $student = Student::query()
                ->whereKey($data['student_id'])
                ->when($institution, fn ($q) => $q->where(function ($sub) use ($institution) {
                    $sub->where('institution_id', $institution->id)->orWhereNull('institution_id');
                }))
                ->first();

            if (! $student) {
                return back()->with('error', 'That member record does not belong to this institution.');
            }

            // Already linked to a different login? Refuse rather than create a
            // duplicate/overlapping account.
            if ($student->user_id) {
                $linked = User::find($student->user_id);
                if ($linked && strcasecmp($linked->email, $data['email']) !== 0) {
                    return back()->with('error', "That member is already linked to {$linked->email}.");
                }
            }

            // Prefer the member's real name for the invite when none was typed,
            // so the invitation never carries an empty/placeholder name.
            if (blank($data['name'] ?? null) && filled($student->name)) {
                $data['name'] = $student->name;
            }
        }

        /*
         * SAFE ROLE at the source. The invitation's role is what the account is
         * created with, so it must be an institution-scoped role. A request that
         * asks for 'Software Super Admin' (or any unknown value) is coerced to
         * Member here - before it is ever persisted - so the misassignment can
         * never reach the assignment step at accept-time.
         */
        $safeRole = User::safeInstitutionRole($data['role'] ?? null) ?: 'Member';

        [$invitation, $plainToken] = MemberInvitation::issue([
            'institution_id' => $institution->id,
            'student_id' => $data['student_id'] ?? null,
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'role' => $safeRole,
            'invited_by' => $request->user()->id,
        ]);

        // A signed URL: the token in the path plus a signature that Laravel
        // validates, so a tampered link is rejected before we ever look it up.
        // Points at the dedicated password-setup screen (handles both the
        // first-time and reset cases).
        $acceptUrl = \URL::temporarySignedRoute(
            'password.setup',
            now()->addDays(MemberInvitation::TTL_DAYS),
            ['invitation' => $invitation->id, 'token' => $plainToken]
        );

        // Send through the branded HTML template. isReset flips the copy when the
        // account already exists. Every send is captured by the outbox listener.
        Mail::to($invitation->email)->send(
            new MemberInvitationMail(
                $invitation,
                $acceptUrl,
                $institution?->name,
                isReset: $existingUser !== null,
            )
        );

        AuditLogger::log('invited', 'invited '.$invitation->email, $invitation, [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'state' => $existingUser ? 'complete' : 'incomplete',
        ], ['subject_label' => $invitation->email, 'institution_id' => $invitation->institution_id]);

        $label = $existingUser ? 'Password reset link' : 'Invitation';

        return back()->with('success', "{$label} sent to {$invitation->email}.");
    }

    /**
     * The member lands here from the email. Validates the signature + token,
     * then shows the set-password form.
     */
    public function accept(Request $request, MemberInvitation $invitation)
    {
        $token = (string) $request->query('token', '');

        $failure = $this->validateInvitation($invitation, $token);

        if ($failure) {
            return redirect()->route('login')->with('error', $failure);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Complete signup: create the user, set the password, link the member
     * record, and mark the invitation consumed.
     */
    public function complete(Request $request, MemberInvitation $invitation)
    {
        $token = (string) $request->input('token', '');

        $failure = $this->validateInvitation($invitation, $token);

        if ($failure) {
            return redirect()->route('login')->with('error', $failure);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data, $invitation) {
            $user = User::create([
                'institution_id' => $invitation->institution_id,
                'name' => $data['name'],
                'email' => $invitation->email,
                'status' => 'active',
                'invitation_pending' => false,
                'password' => Hash::make($data['password']),
            ]);

            // SAFE ROLE: the invitation can only confer an institution-scoped
            // role; a global role is impossible here.
            $user->assignInstitutionRole($invitation->role);

            // Link the member record to this new login.
            if ($invitation->student_id) {
                Student::whereKey($invitation->student_id)
                    ->update(['user_id' => $user->id]);
            }

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        AuditLogger::log('accepted', 'accepted invite for '.$invitation->email, $invitation, [
            'user_id' => $user->id,
        ], ['subject_label' => $invitation->email]);

        // Send them straight to sign in with their new credentials.
        return redirect()
            ->route('login')
            ->with('success', 'Your account is ready. Please sign in.');
    }

    /**
     * Reusable validation of an invitation + token. Returns an error string on
     * failure, or null when the invitation may proceed.
     */
    protected function validateInvitation(MemberInvitation $invitation, string $token): ?string
    {
        if ($token === '' || ! hash_equals($invitation->token, hash('sha256', $token))) {
            return 'This invitation link is not valid.';
        }

        if ($invitation->isAccepted()) {
            return 'This invitation has already been used.';
        }

        if ($invitation->isExpired()) {
            return 'This invitation has expired. Please ask an admin to resend it.';
        }

        return null;
    }
}
