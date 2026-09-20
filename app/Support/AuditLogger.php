<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Central writer for the audit trail.
 *
 * Every tracked model routes through here via RecordActivity observer, and
 * non-model events (sign-in, invitations, exports) call it explicitly. Keeping
 * one entry point means the log format never drifts between modules.
 */
class AuditLogger
{
    /**
     * Fields we never persist into a diff - they are noise or a security risk.
     */
    protected const REDACTED = [
        'password',
        'remember_token',
        'updated_at',
        'created_at',
    ];

    /**
     * Record an event against a subject model (or free-form).
     */
    public static function log(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        array $context = []
    ): ?ActivityLog {
        try {
            $user = Auth::user();

            return ActivityLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'institution_id' => $context['institution_id']
                    ?? static::resolveInstitutionId($subject),
                'event' => $event,
                'description' => $description,
                'subject_type' => $subject ? $subject::class : ($context['subject_type'] ?? null),
                'subject_id' => $subject?->getKey() ?? ($context['subject_id'] ?? null),
                'subject_label' => $context['subject_label']
                    ?? static::labelFor($subject),
                'properties' => $properties ?: null,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break a business action. Log and move on.
            report($e);

            return null;
        }
    }

    /**
     * Convenience wrappers so callers read naturally.
     */
    public static function created(Model $subject): ?ActivityLog
    {
        return static::log(
            'created',
            sprintf('created %s "%s"', class_basename($subject), static::labelFor($subject)),
            $subject,
            ['attributes' => static::clean($subject->getAttributes())]
        );
    }

    public static function updated(Model $subject, array $changes): ?ActivityLog
    {
        return static::log(
            'updated',
            sprintf('updated %s "%s"', class_basename($subject), static::labelFor($subject)),
            $subject,
            ['changes' => static::clean($changes)]
        );
    }

    public static function deleted(Model $subject): ?ActivityLog
    {
        return static::log(
            'deleted',
            sprintf('deleted %s "%s"', class_basename($subject), static::labelFor($subject)),
            $subject,
            ['attributes' => static::clean($subject->getAttributes())]
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** Strip redacted fields from a raw attribute/change array. */
    public static function clean(array $attributes): array
    {
        return collect($attributes)
            ->except(static::REDACTED)
            ->all();
    }

    /**
     * A short human label for a model - prefers name/title, falls back to key.
     */
    public static function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        foreach (['name', 'item', 'code', 'email', 'title'] as $field) {
            if (! empty($subject->{$field})) {
                return (string) $subject->{$field};
            }
        }

        return class_basename($subject) . ' #' . $subject->getKey();
    }

    /**
     * Figure out which institution a subject belongs to, so the log can be
     * scoped. Models carrying institution_id answer directly; the institution
     * itself is its own scope.
     */
    protected static function resolveInstitutionId(?Model $subject): ?int
    {
        if ($subject) {
            if ($subject instanceof Institution) {
                return $subject->getKey();
            }

            if (! empty($subject->institution_id)) {
                return (int) $subject->institution_id;
            }
        }

        // Fall back to the signed-in user's institution.
        $user = Auth::user();

        return $user?->institution_id ? (int) $user->institution_id : null;
    }
}
