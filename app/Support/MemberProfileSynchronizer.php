<?php

namespace App\Support;

use App\Models\MemberInvitation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a person's NAME consistent across every record that represents them.
 *
 * The platform stores a member twice:
 *   - `users.name`    - the account / login identity.
 *   - `students.name` - the roster (Member) record the admin created.
 *
 * They are created at DIFFERENT times: an admin creates the roster row with a
 * placeholder name, then invites the person, who later sets their REAL name at
 * password setup or from the Profile Manager. Historically only the `users` row
 * was updated, so the roster kept the placeholder - the mismatch this service
 * exists to prevent.
 *
 * Every name-changing path calls `syncName()`, which updates all linked records
 * inside one transaction, so the two can never drift again.
 */
class MemberProfileSynchronizer
{
    /**
     * Persist a new name everywhere it belongs for this user.
     *
     * Updates:
     *   - the user's own `name`,
     *   - the linked member (students) record, when one exists,
     *   - any still-open invitation for this email (so the admin's list and any
     *     future resend show the corrected name, not the placeholder).
     *
     * @return bool True when at least the user row changed.
     */
    public static function syncName(User $user, string $name, bool $save = true): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $name, $save) {
            $user->name = $name;

            if ($save) {
                $user->save();
            }

            // Email can change at the same time (Profile Manager). Keep the
            // roster / invitation rows pointing at the SAME person by mirroring
            // the address once it has been persisted on the user row.
            static::syncEmail($user);

            // The roster (Member) record this login belongs to.
            $student = Student::query()->where('user_id', $user->id)->first();

            // Fall back to matching by email-scoped invitation when the link is
            // not yet written (e.g. mid-accept), so a fresh invite still syncs.
            if (! $student) {
                $student = static::studentForInvitationEmail($user->email, $user->institution_id);
            }

            if ($student) {
                // Guard: never let a name-sync tamper with a member record that
                // belongs to a different institution.
                if ($student->institution_id === null
                    || $user->institution_id === null
                    || (int) $student->institution_id === (int) $user->institution_id) {
                    $student->name = $name;

                    // Heal the ownership link if it is still missing.
                    if (blank($student->user_id)) {
                        $student->user_id = $user->id;
                    }

                    $student->save();
                }
            }

            // Keep any pending invitation's display name in step.
            MemberInvitation::query()
                ->where('email', $user->email)
                ->whereNull('accepted_at')
                ->update(['name' => $name]);

            return true;
        });
    }

    /**
     * Mirror the user's current email onto the linked member/invitation rows.
     *
     * The member's login identity lives on the user row, but the roster and any
     * still-open invitation carry their own copy of the address. When a member
     * edits their email in the Profile Manager the roster copy would otherwise
     * go stale, so we realign it here - scoped to the same institution so we can
     * never touch another tenant's record.
     */
    public static function syncEmail(User $user): void
    {
        if (blank($user->email) || ! $user->exists) {
            return;
        }

        $student = Student::query()->where('user_id', $user->id)->first();

        if ($student) {
            // Same-institution guard, mirroring syncName().
            $sameTenant = $student->institution_id === null
                || $user->institution_id === null
                || (int) $student->institution_id === (int) $user->institution_id;

            if ($sameTenant) {
                // The member's linked login row does not store email on the
                // roster, but a stale invitation queued to the OLD address should
                // not linger. We only realign open invitations here.
                MemberInvitation::query()
                    ->where('student_id', $student->id)
                    ->whereNull('accepted_at')
                    ->update(['email' => $user->email]);
            }
        }
    }

    /**
     * The member record an invitation email points at (used before the
     * user_id link exists on the student row).
     */
    protected static function studentForInvitationEmail(?string $email, ?int $institutionId): ?Student
    {
        if (blank($email)) {
            return null;
        }

        $invitation = MemberInvitation::query()
            ->where('email', $email)
            ->whereNotNull('student_id')
            ->orderByDesc('created_at')
            ->first();

        if (! $invitation?->student_id) {
            return null;
        }

        return Student::query()
            ->whereKey($invitation->student_id)
            ->when($institutionId, fn ($q) => $q->where(function ($sub) use ($institutionId) {
                $sub->where('institution_id', $institutionId)->orWhereNull('institution_id');
            }))
            ->first();
    }
}
