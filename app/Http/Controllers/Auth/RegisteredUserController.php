<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public guest registration - SECURE, TENANT-MAPPED signup.
 *
 * The previous flow created a bare user with no institution: an ORPHANED account
 * that belonged to no workspace and could see nothing. That is the bug this
 * controller fixes.
 *
 * The rule now: a signup MUST carry a valid INVITE CODE belonging to an
 * institution. The institution's invite code (shared by its admin) both
 *   - proves the person is meant to join that workspace, and
 *   - is the mapping key that stamps `users.institution_id`.
 *
 * Role assignment goes through the SAFE institution-scoped guard, so a public
 * signup can only ever obtain 'Member' or 'Meal Manager' - never an admin, and
 * never the global Software Super Admin. Privileged roles are granted only by an
 * existing admin from inside the workspace (or by an invitation).
 */
class RegisteredUserController extends Controller
{
    /**
     * Roles the PUBLIC signup form may grant. Deliberately excludes
     * 'Institution Admin' and the global role - a self-signup can never escalate.
     */
    protected const SELF_SIGNUP_ROLES = ['Member', 'Meal Manager'];

    /**
     * Display the registration view. The invite code may arrive via the query
     * string (?code=XXXX) so an admin can hand out a prefilled link.
     */
    public function create(Request $request): Response
    {
        $prefilled = trim((string) $request->query('code', ''));
        $institution = Institution::findByInviteCode($prefilled);

        return Inertia::render('Auth/Register', [
            'inviteCode' => $institution ? $institution->invite_code : $prefilled,
            // Only expose the NAME of a matched institution - never its data.
            'institutionName' => $institution?->name,
            'roles' => self::SELF_SIGNUP_ROLES,
        ]);
    }

    /**
     * Handle a registration request, mapping the account to its institution.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // The tenant-mapping key. Required, and must resolve to an institution.
            'invite_code' => ['required', 'string', 'max:24'],
            // Optional role, restricted to the safe allow-list.
            'role' => ['nullable', 'string', 'in:' . implode(',', self::SELF_SIGNUP_ROLES)],
        ]);

        // Resolve the institution from the code BEFORE creating anything, so a
        // bad code fails cleanly rather than producing an orphaned account.
        $institution = Institution::findByInviteCode($data['invite_code']);

        if (! $institution) {
            throw ValidationException::withMessages([
                'invite_code' => 'That invitation code is not valid. Ask your institution admin for the correct code.',
            ]);
        }

        if (! $institution->is_active) {
            throw ValidationException::withMessages([
                'invite_code' => 'That institution is not currently accepting new members.',
            ]);
        }

        $user = DB::transaction(function () use ($data, $institution) {
            $user = User::create([
                // THE mapping: the account is bound to the institution from the
                // very first write, so it can never be orphaned.
                'institution_id' => $institution->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => 'active',
                'password' => Hash::make($data['password']),
                'setup_completed_at' => now(),
            ]);

            // SAFE ROLE: the guard strips any global role and coerces unknown
            // values, defaulting to Member. A self-signup can never be an admin.
            $role = $user->assignInstitutionRole($data['role'] ?? 'Member');

            /*
             * THE LINKING FIX.
             *
             * Mapping `users.institution_id` alone was NOT enough: the member
             * AREA (dashboard, meals, deposits, analytics) resolves the person
             * through `users.studentRecord()` -> `students.user_id`. Without a
             * roster row pointing at this new login, `studentRecord()` returned
             * null and every member screen rendered the "Your account is not
             * linked yet" empty state - even though the account WAS correctly
             * bound to the institution.
             *
             * A member signup therefore also creates the roster (Member) record
             * inside the SAME transaction, so account + roster land together and
             * the dashboard is immediately active. A Meal Manager signup gets no
             * roster row (they are staff, not a participant).
             */
            if ($role === 'Member') {
                Student::create([
                    'institution_id' => $institution->id,
                    // The ownership link the member dashboard reads.
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'status' => 'active',
                    'join_date' => now()->toDateString(),
                ]);
            }

            AuditLogger::log('created', "self-registered via invite code into {$institution->name}", $user, [
                'email' => $user->email,
                'role' => $role,
                'via' => 'public_signup',
                'member_record_created' => $role === 'Member',
            ], ['subject_label' => $user->name, 'institution_id' => $institution->id]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false))
            ->with('success', "Welcome! You've joined {$institution->name}.");
    }
}
