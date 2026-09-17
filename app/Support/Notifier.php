<?php

namespace App\Support;

use App\Models\Claim;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Collection;

/**
 * Central dispatcher for in-app notifications.
 *
 * One place decides WHO gets told about WHAT, so the routing rules live in the
 * codebase (not scattered across controllers) and stay consistent:
 *
 *   - claim submitted      -> the member's ASSIGNED manager (+ institution admins)
 *   - claim approved/rejected -> the member who raised it (+ assigned manager)
 *   - deposit recorded     -> the member it credits (+ assigned manager)
 *   - expense claim approved  -> the member reimbursed (+ admins)
 *   - announcement         -> everyone in an institution
 *
 * All writes are best-effort: a notification failure must never break the
 * business action that triggered it.
 */
class Notifier
{
    /** Notify an arbitrary set of users, de-duplicated, except the actor. */
    public static function send(Collection|array $users, string $kind, string $title, string $body, array $meta = [], ?int $exceptUserId = null): void
    {
        $recipients = collect($users)
            ->filter()
            ->unique('id')
            ->reject(fn (User $u) => $exceptUserId !== null && $u->id === $exceptUserId);

        foreach ($recipients as $user) {
            try {
                $user->notify(new AppNotification($kind, $title, $body, $meta));
            } catch (\Throwable $e) {
                // Never let a notification take down the request.
                report($e);
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Audience resolvers
     * ------------------------------------------------------------------ */

    /** The manager assigned to a member (student.manager_id). */
    public static function assignedManager(?Student $student): ?User
    {
        return $student?->manager;
    }

    /**
     * The people who should hear about a member's activity: their assigned
     * manager, plus the institution admins as a safety net (so nothing is missed
     * if a member has no manager yet).
     */
    public static function memberOversight(?Student $student): Collection
    {
        $manager = static::assignedManager($student);

        $admins = User::query()
            ->when($student?->institution_id, fn ($q) => $q->where('institution_id', $student->institution_id))
            ->whereHas('roles', fn ($q) => $q->where('name', 'Institution Admin'))
            ->get();

        return collect([$manager])->merge($admins)->filter()->unique('id')->values();
    }

    /** Every user in an institution (for announcements). */
    public static function institutionUsers(?int $institutionId): Collection
    {
        return User::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->get();
    }

    /* ------------------------------------------------------------------ *
     * Event helpers - one call per event, used by the controllers
     * ------------------------------------------------------------------ */

    /** A member raised a claim: tell their manager + admins. */
    public static function claimSubmitted(Claim $claim, ?User $actor = null): void
    {
        $student = $claim->student;

        static::send(
            static::memberOversight($student),
            'claim_submitted',
            'New claim from '.($student?->name ?? 'a member'),
            $claim->kindLabel().' · '.$claim->title,
            [
                'url' => route('claims.review', [], false),
                'claim_id' => $claim->id,
                'amount' => $claim->amount !== null ? (float) $claim->amount : null,
            ],
            $actor?->id,
        );
    }

    /** A claim was decided: tell the member who raised it. */
    public static function claimReviewed(Claim $claim, bool $approved, ?User $reviewer = null): void
    {
        $student = $claim->student;
        $member = $student?->user;

        $title = $approved ? 'Your claim was approved' : 'Your claim was rejected';
        $body = $claim->kindLabel().' · '.$claim->title
            .($claim->review_notes ? ' — '.$claim->review_notes : '');

        // The member (if they have a login) plus their assigned manager for visibility.
        static::send(
            collect([$member, static::assignedManager($student)]),
            $approved ? 'claim_approved' : 'claim_rejected',
            $title,
            $body,
            [
                'url' => route('member.dashboard', [], false),
                'claim_id' => $claim->id,
                'amount' => $claim->amount !== null ? (float) $claim->amount : null,
            ],
            $reviewer?->id,
        );
    }

    /** A member's own expense purchase was approved and reimbursed. */
    public static function memberExpenseApproved(Claim $claim, float $amount, ?User $actor = null): void
    {
        $student = $claim->student;
        $member = $student?->user;

        static::send(
            collect([$member])->merge(static::memberOversight($student)),
            'expense_approved',
            'Purchase reimbursed',
            $claim->title.' — '.number_format($amount, 2).' credited to your balance.',
            [
                'url' => route('member.dashboard', [], false),
                'claim_id' => $claim->id,
                'amount' => $amount,
            ],
            $actor?->id,
        );
    }

    /** A deposit touched a member's account: tell the member. */
    public static function depositRecorded(?Student $student, float $amount, string $context, ?User $actor = null): void
    {
        $member = $student?->user;

        static::send(
            collect([$member]),
            'deposit_updated',
            'Deposit recorded',
            number_format($amount, 2).' was added to your account'.($context ? " ({$context})" : '').'.',
            [
                'url' => route('member.dashboard', [], false),
                'amount' => $amount,
            ],
            $actor?->id,
        );
    }

    /** Broadcast an announcement to an institution. */
    public static function announcement(?Institution $institution, string $title, string $body, ?User $actor = null): int
    {
        $recipients = static::institutionUsers($institution?->id)
            ->reject(fn (User $u) => $actor !== null && $u->id === $actor->id);

        $count = 0;
        foreach ($recipients as $user) {
            try {
                $user->notify(new AppNotification('announcement', $title, $body, [
                    'url' => route('notifications.index', [], false),
                ]));
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }
}
