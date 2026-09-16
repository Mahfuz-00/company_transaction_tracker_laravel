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

        // An email that already has an account cannot be invited again.
        if (User::where('email', $data['email'])->exists()) {
            return back()->with('error', 'A user with that email already exists.');
        }

        [$invitation, $plainToken] = MemberInvitation::issue([
            'institution_id' => $institution?->id,
            'student_id' => $data['student_id'] ?? null,
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'role' => $data['role'] ?? 'Member',
            'invited_by' => $request->user()->id,
        ]);

        // A signed URL: the token in the path plus a signature that Laravel
        // validates, so a tampered link is rejected before we ever look it up.
        $acceptUrl = \URL::temporarySignedRoute(
            'invitations.accept',
            now()->addDays(MemberInvitation::TTL_DAYS),
            ['invitation' => $invitation->id, 'token' => $plainToken]
        );

        Mail::to($invitation->email)->send(
            new MemberInvitationMail($invitation, $acceptUrl, $institution?->name)
        );

        AuditLogger::log('invited', 'invited '.$invitation->email, $invitation, [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ], ['subject_label' => $invitation->email]);

        return back()->with('success', "Invitation sent to {$invitation->email}.");
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

            // Grant the role the invitation specified (defaults to Member).
            try {
                $user->assignRole($invitation->role);
            } catch (\Throwable $e) {
                $user->assignRole('Member');
            }

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
