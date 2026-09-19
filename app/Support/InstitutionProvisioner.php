<?php

namespace App\Support;

use App\Mail\InstitutionWelcomeMail;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * CENTRAL INSTITUTION PROVISIONER.
 *
 * WHY THIS EXISTS
 * ---------------
 * An institution (a tenant workspace) can be created from more than one place:
 *
 *   - the SSA approves a LANDING ENQUIRY (a 7-day trial or a chosen plan), and
 *   - the SSA creates a workspace directly from the INSTITUTION REGISTRY.
 *
 * Both paths must behave identically: create the institution, provision its
 * first Institution Admin, start the trial / subscription, and email the admin
 * welcome credentials. Previously the email was assembled separately in each
 * controller, and the registry path sent it WITHOUT any temporary password or
 * setup link - locking the new administrator out of the very account they were
 * told to use.
 *
 * This class is now the ONE place that turns "provision a workspace" into
 * reality, so a new provisioning caller (an API, a CLI command, a payment
 * webhook) gets credentials-in-email behaviour for free and cannot drift.
 *
 * WHAT THE ADMIN ALWAYS RECEIVES
 * ------------------------------
 *   - a TEMPORARY PASSWORD (generated here if the SSA did not supply one) so
 *     they can sign in immediately, AND
 *   - a SIGNED, SINGLE-USE setup link so they can set their OWN password
 *     securely. Both are embedded in the welcome email's credentials block.
 */
class InstitutionProvisioner
{
    /**
     * Create an institution together with its first Institution Admin.
     *
     * Runs inside ONE transaction so a failure provisions nothing; the welcome
     * email is dispatched AFTER the commit and is best-effort, so a mail problem
     * never rolls back a successfully created workspace.
     *
     * @param  array{
     *     name:string,
     *     type:string,
     *     subtitle?:?string,
     *     contact_email?:?string,
     *     contact_phone?:?string,
     *     address?:?string,
     *     currency_code?:?string,
     *     timezone?:?string,
     *     onboarding_mode?:string,           // 'trial' | 'subscription'
     *     subscription_plan?:?string,
     *     subscription_amount?:int|float,
     *     trial_days?:?int,
     *     admin_name:string,
     *     admin_email:string,
     *     admin_password?:?string            // when blank, a temporary one is generated
     * }  $data
     * @param  User|null  $actor  the SSA performing the provisioning (for the invite audit trail)
     * @return array{0:Institution,1:User,2:?string} [institution, admin, temporaryPassword]
     */
    public static function provision(array $data, ?User $actor = null): array
    {
        $mode = $data['onboarding_mode'] ?? 'trial';

        // A temporary password the admin changes on first sign-in, unless the
        // caller supplied one. NEVER left null - that was the lockout.
        $plainPassword = filled($data['admin_password'] ?? null)
            ? (string) $data['admin_password']
            : Str::password(12);

        [$institution, $admin] = DB::transaction(function () use ($data, $mode, $plainPassword) {
            $institution = Institution::create([
                'name' => $data['name'],
                'subtitle' => $data['subtitle'] ?? null,
                'type' => $data['type'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'onboarding_mode' => $mode,
                'subscription_plan' => $data['subscription_plan'] ?? null,
                'subscription_amount' => $data['subscription_amount'] ?? 0,
                'is_active' => true,
            ]);

            // Apply the lifecycle for the chosen mode. A trial gets a concrete
            // expiry window; a subscription is immediately live.
            if ($mode === 'trial') {
                $institution->startTrial((int) ($data['trial_days'] ?? Institution::TRIAL_DAYS));
            } else {
                $institution->convertToSubscription([
                    'subscription_status' => 'paid',
                    'subscription_renews_at' => now()->addMonth()->toDateString(),
                ]);
            }

            // The institution acts as its own hub vendor from day one.
            $institution->ensureHubVendor();

            // Provision the first Institution Admin, scoped to the workspace.
            // `must_change_password` is TRUE because they were handed a
            // temporary credential.
            $admin = User::create([
                'institution_id' => $institution->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($plainPassword),
                'status' => 'active',
                'designation' => 'Institution Admin',
                'must_change_password' => true,
                'setup_completed_at' => now(),
            ]);

            // SAFE ROLE ASSIGNMENT: route through the whitelist guard so the new
            // admin can only ever receive an institution-scoped role. It can
            // never become a global Software Super Admin through this path.
            $admin->assignInstitutionRole('Institution Admin');

            return [$institution, $admin];
        });

        // Best-effort: issue the signed setup link and email the credentials.
        self::sendWelcomeEmail($institution, $admin, $mode, $plainPassword, $actor);

        return [$institution, $admin, $plainPassword];
    }

    /**
     * Issue a signed, single-use password-setup link for an admin account.
     *
     * Reuses the MemberInvitation token + expiry machinery so there is ONE
     * invitation table and ONE setup screen; the raw token is never stored, only
     * its SHA-256 hash.
     *
     * @return string|null the signed URL, or null if it could not be built
     */
    public static function issueSetupUrl(Institution $institution, User $admin, ?User $actor = null): ?string
    {
        try {
            [$invitation, $plainToken] = MemberInvitation::issue([
                'institution_id' => $institution->id,
                'student_id' => null,
                'email' => $admin->email,
                'name' => $admin->name,
                'role' => 'Institution Admin',
                'invited_by' => $actor?->id,
            ]);

            return URL::temporarySignedRoute(
                'password.setup',
                now()->addDays(MemberInvitation::TTL_DAYS),
                ['invitation' => $invitation->id, 'token' => $plainToken]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Send the institution welcome email carrying BOTH the temporary password
     * AND the signed setup link. Never throws - a mail failure must not undo a
     * successfully created workspace.
     */
    public static function sendWelcomeEmail(
        Institution $institution,
        User $admin,
        string $mode,
        ?string $temporaryPassword = null,
        ?User $actor = null,
    ): void {
        try {
            $setupUrl = self::issueSetupUrl($institution, $admin, $actor);

            Mail::to($admin->email)->send(
                new InstitutionWelcomeMail(
                    $institution,
                    $admin,
                    $mode === 'plan' ? 'subscription' : $mode,
                    temporaryPassword: $temporaryPassword,
                    setupUrl: $setupUrl,
                )
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
