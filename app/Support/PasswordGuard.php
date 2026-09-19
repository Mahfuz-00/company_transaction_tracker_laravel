<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Central guard for USER CREDENTIAL changes.
 *
 * WHY THIS EXISTS
 * ---------------
 * A Software Super Admin account was found with an unexpectedly changed
 * password. The investigation showed the cause plainly: the SSA row's
 * `password` column had been written DIRECTLY - by a seed/test helper doing
 * `forceFill(['password' => bcrypt(...)])->save()` - with no authorisation
 * check, no audit entry, and `password_changed_at` left null. Any code path
 * (seeder, migration, an admin's generic user-update form, a tinker snippet)
 * could silently overwrite the most privileged account on the platform.
 *
 * This class is the single, explicit gate for credential writes. Rules:
 *
 *   1. Changing a password ALWAYS stamps `password_changed_at` (so the audit
 *      trail shows when the credential last moved).
 *   2. A SOFTWARE SUPER ADMIN password may only be changed through an
 *      AUTHORISED path - an explicit self-service reset, or an explicit SSA
 *      password-reset action. Generic user updates and seeders are refused.
 *   3. Every accepted change is written to the activity log, naming the actor.
 *
 * Callers that legitimately change credentials (ProfileController, the password
 * reset flow, the invite-accept flow) call changePassword(); everything else
 * simply cannot move an SSA credential.
 */
class PasswordGuard
{
    /**
     * Change a user's password through an AUTHORISED path.
     *
     * @param  User  $user  the account whose password changes
     * @param  string  $plain  the new plaintext password
     * @param  string  $reason  why (recorded in the audit log)
     * @param  bool  $forceSsa  true ONLY for an explicit SSA reset/self-service
     * @return bool true when the password was changed
     */
    public static function changePassword(User $user, string $plain, string $reason = 'password_change', bool $forceSsa = false): bool
    {
        if ($plain === '') {
            return false;
        }

        // RULE 1: an SSA password cannot move unless the caller explicitly opts
        // into an authorised path. This is what stops a seeder, a generic user
        // update, or a stray tinker call from silently resetting the account.
        if ($user->isSuperAdmin() && ! $forceSsa) {
            return false;
        }

        /*
         * RULE 1b: OPERATOR LOCK-DOWN (environment-driven).
         *
         * When PLATFORM_SSA_ALLOW_SELF_SERVICE_PASSWORD=false, the SSA credential
         * may ONLY move through an explicit operator path - the CLI reset
         * (`reason` starts with 'cli_') or a seeder - never through self-service
         * (the Profile Manager) nor an admin form. This is the hard boundary a
         * regulated / third-party-hosted deployment needs.
         */
        if ($user->isSuperAdmin() && ! config('platform.allow_self_service_password', true)) {
            $isOperatorPath = str_starts_with($reason, 'cli_') || $reason === 'seeder';

            if (! $isOperatorPath) {
                return false;
            }
        }

        // Signal to the User model's `saving` safety net that THIS write is
        // authorised, so it passes through (and stamps the change time).
        $previousAuthorised = $user->passwordWriteAuthorised;
        $user->passwordWriteAuthorised = true;

        try {
            $user->forceFill([
                'password' => Hash::make($plain),
                // RULE 2: always record WHEN the credential changed.
                'password_changed_at' => now(),
                // A fresh password is never "temporary" once set through an
                // authorised path.
                'must_change_password' => false,
            ])->save();
        } finally {
            $user->passwordWriteAuthorised = $previousAuthorised;
        }

        // RULE 3: audit every credential change with the actor.
        $actor = Auth::user();

        AuditLogger::log('updated', "changed the password for {$user->email}", $user, [
            'reason' => $reason,
            'by' => $actor?->email ?? 'system',
            'super_admin' => $user->isSuperAdmin(),
        ], [
            'subject_label' => $user->name,
            'institution_id' => $user->institution_id,
        ]);

        return true;
    }

    /**
     * Is this credential change authorised? Used by controllers BEFORE writing,
     * so they can return a clear error rather than silently doing nothing.
     */
    public static function mayChangePassword(User $target, ?User $actor = null): bool
    {
        // OPERATOR LOCK-DOWN: when self-service is disabled, an SSA password may
        // only move through the CLI / seeder - refuse every in-app path here too,
        // so callers return a clear error instead of a silent no-op.
        if ($target->isSuperAdmin() && ! config('platform.allow_self_service_password', true)) {
            return false;
        }

        // A Super Admin may change their OWN password (self-service).
        if ($actor !== null && $actor->id === $target->id) {
            return true;
        }

        // Nobody else may change ANOTHER Super Admin's password through a generic
        // surface. (An explicit SSA reset action passes forceSsa instead.)
        if ($target->isSuperAdmin()) {
            return false;
        }

        return true;
    }
}
