<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * Set true by PasswordGuard (and the auth controllers) to signal that a
     * password write has been AUTHORISED. The `saving` hook below refuses to
     * let a Super Admin password move without it.
     *
     * This is the model-level safety net for the credential-integrity bug: even
     * if a seeder, a migration, or a stray script reaches for the `password`
     * column directly, the write is rejected unless it came through the guard.
     */
    public bool $passwordWriteAuthorised = false;

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $passwordDirty = $user->isDirty('password');

            // Let an authorised write through, and stamp the change time.
            if ($user->passwordWriteAuthorised) {
                if ($passwordDirty && $user->password_changed_at === null) {
                    $user->password_changed_at = now();
                }

                return;
            }

            /*
             * An UNAUTHORISED password write to an EXISTING Super Admin is
             * blocked outright: we restore the original hash so the row is
             * untouched. This is deliberately scoped to rows that ALREADY have
             * a password (a real credential that could be lost) - creating a
             * brand-new account or a test fixture is never what the guard is
             * protecting against.
             */
            $hadPassword = filled($user->getOriginal('password'));
            $wasSuperAdmin = $user->exists
                && $user->roles()->where('name', 'Software Super Admin')->exists();

            if ($passwordDirty && $hadPassword && ($wasSuperAdmin || $user->isSuperAdmin())) {
                $user->password = $user->getOriginal('password');

                report(new \RuntimeException(
                    'Blocked an unauthorised password write to the Software Super Admin account '
                    . $user->email . '. Use App\\Support\\PasswordGuard::changePassword() instead.'
                ));
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'name',
        'email',
        'avatar_path',
        'designation',
        'theme',
        'phone',
        'status',
        'invitation_pending',
        'must_change_password',
        'password_changed_at',
        'password',
        'last_login_at',
    ];

    /**
     * Public URL for the profile picture, or null when none is set.
     *
     * A cache-busting `?v=` query is appended, keyed off the file's last-modified
     * time. Without it, replacing an avatar writes a NEW file but a browser that
     * cached the old URL at the same path could keep showing the stale image -
     * the "broken/old picture after edit" bug. Changing the query forces a fresh
     * fetch the moment the file changes.
     */
    public function avatarUrl(): ?string
    {
        return $this->publicFileUrl($this->avatar_path);
    }

    /**
     * Build a public URL for a stored path, with a cache-busting version query.
     * Shared by avatar (User) and logo/banner (Institution) rendering so both
     * refresh immediately after an upload.
     */
    public function publicFileUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = \Storage::disk('public');
        $url = $disk->url($path);

        // Version by mtime when the file exists, so a replacement busts the cache.
        try {
            if ($disk->exists($path)) {
                $url .= '?v=' . $disk->lastModified($path);
            }
        } catch (\Throwable $e) {
            // If the disk is unavailable, still return the plain URL.
        }

        return $url;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'invitation_pending' => 'boolean',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'datetime',
        'password' => 'hashed',
        'theme' => 'array',
    ];

    /* ------------------------------------------------------------------ *
     * Personal theme
     * ------------------------------------------------------------------ */

    /**
     * The user's personal theme, merged over the platform defaults.
     *
     * A member may personalise light/dark, accent, font and density; their
     * choice is stored here (database half) and mirrored into the browser's
     * localStorage (PC half). Merging over the defaults guarantees every token
     * is present even for an account that has never opened the customiser.
     */
    public function themeSettings(): array
    {
        return array_merge(static::DEFAULT_THEME, $this->theme ?? []);
    }

    /**
     * The appearance tokens a user can customise, with defaults and the allowed
     * values. Shared with the ThemeCustomizer UI so both sides never drift.
     */
    public const DEFAULT_THEME = [
        'mode' => 'light',
        'accent' => 'indigo',
        'radius' => 'lg',
        'density' => 'comfortable',
        'font' => 'inter',
    ];

    /** Font families the user can choose, mapped to a stack + Google family. */
    public const THEME_FONTS = [
        'inter' => ['label' => 'Inter', 'stack' => "'Inter', ui-sans-serif, system-ui, sans-serif", 'google' => 'Inter'],
        'roboto' => ['label' => 'Roboto', 'stack' => "'Roboto', ui-sans-serif, system-ui, sans-serif", 'google' => 'Roboto'],
        'poppins' => ['label' => 'Poppins', 'stack' => "'Poppins', ui-sans-serif, system-ui, sans-serif", 'google' => 'Poppins'],
        'nunito' => ['label' => 'Nunito', 'stack' => "'Nunito', ui-sans-serif, system-ui, sans-serif", 'google' => 'Nunito'],
        'system' => ['label' => 'System default', 'stack' => 'ui-sans-serif, system-ui, sans-serif', 'google' => null],
    ];

    /** Accent tokens (reused from the institution palette). */
    public static function themeAccents(): array
    {
        return Institution::THEMES;
    }

    /**
     * Is the account currently active?
     */
    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /** The institution this user belongs to (null for Software Super Admins). */
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The student / member record behind this login, if any.
     *
     * A member is a User whose `students.user_id` points at them. Used by the
     * member dashboard to scope every figure to the signed-in person.
     */
    public function studentRecord()
    {
        return Student::query()
            ->with('department:id,name')
            ->where('user_id', $this->id)
            ->first();
    }

    /** Does this account represent a member (has a linked student record)? */
    public function isMember(): bool
    {
        return $this->hasRole('Member') || $this->studentRecord() !== null;
    }

    /**
     * Does this user hold ANY staff (non-member) role?
     *
     * DUAL-ROLE AWARENESS: a staff account can ALSO be a meal member (an admin
     * who lives in the dorm they manage). This answers "do they have a
     * management-facing role" so routing can decide between the staff dashboard
     * and the personal member dashboard without role collisions.
     */
    public function hasAnyStaffRole(): bool
    {
        return $this->isSuperAdmin()
            || $this->isInstitutionAdmin()
            || $this->hasRole('Meal Manager');
    }

    /* ------------------------------------------------------------------ *
     * Role assignment safeguards
     * ------------------------------------------------------------------ */

    /**
     * Roles that may NEVER be granted by an institution-scoped flow.
     *
     * "Software Super Admin" is a GLOBAL platform role. If it ever leaks into
     * member/user creation (a stray picker value, a crafted request, a bad
     * default) the account gains unrestricted, cross-institution power - the
     * exact bug this guard exists to prevent.
     */
    public const GLOBAL_ROLES = ['Software Super Admin'];

    /**
     * Roles an institution-scoped creator is allowed to hand out. Anything else
     * (including the global role) is silently coerced to Member.
     */
    public const INSTITUTION_ROLES = ['Institution Admin', 'Meal Manager', 'Member'];

    /**
     * Coerce a requested role to a safe, institution-scoped role.
     *
     * Returns null when the requested name is empty/unknown/global, so the
     * caller can fall back to the correct default ('Member').
     */
    public static function safeInstitutionRole(?string $role): ?string
    {
        if (blank($role)) {
            return null;
        }

        // Reject the global role outright (case-insensitive).
        if (in_array(strtolower($role), array_map('strtolower', self::GLOBAL_ROLES), true)) {
            return null;
        }

        // Only a known, institution-scoped role is acceptable.
        $match = collect(self::INSTITUTION_ROLES)
            ->first(fn ($r) => strcasecmp($r, $role) === 0);

        return $match; // null when not in the allow-list
    }

    /**
     * Assign an institution-scoped role safely, defaulting to Member.
     *
     * This is THE guard every invite / user-creation path should call instead of
     * `assignRole()` with a raw string. It can never grant a global role.
     */
    public function assignInstitutionRole(?string $requested): string
    {
        $role = self::safeInstitutionRole($requested) ?: 'Member';

        // Ensure the role row exists before assigning, so a fresh install never
        // throws - and still lands on the safe default.
        try {
            $this->assignRole($role);
        } catch (\Throwable $e) {
            $this->assignRole('Member');
            $role = 'Member';
        }

        return $role;
    }

    /**
     * Synchronise roles safely: strip any global role and keep only allowed,
     * institution-scoped ones (defaulting to Member when the result is empty).
     *
     * @param  array<int, string>  $requested
     */
    public function syncInstitutionRoles(array $requested): void
    {
        $safe = collect($requested)
            ->map(fn ($r) => self::safeInstitutionRole($r))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->syncRoles($safe !== [] ? $safe : ['Member']);
    }

    /**
     * Change this user's password through the AUTHORISED guard.
     *
     * The only sanctioned way to move a credential: it stamps the change time,
     * writes the audit entry, and (for a Super Admin) requires an explicit
     * opt-in path. Direct `$user->password = ...` writes on an SSA are refused
     * by the model hook above.
     */
    public function setPassword(string $plain, string $reason = 'password_change', bool $authorised = false): bool
    {
        // The guard owns the write-authorisation flag and the audit entry, so we
        // simply delegate. A Super Admin write additionally requires $authorised.
        $authorised = $authorised || ! $this->isSuperAdmin();

        return \App\Support\PasswordGuard::changePassword($this, $plain, $reason, $authorised);
    }

    /**
     * Is this user forced to change a temporary (demo) password before using
     * the app? Set when an admin creates an account without SMTP.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * Members assigned directly under this user as their meal manager.
     * A Meal Manager only ever sees and manages these - never the whole roster.
     */
    public function assignedMembers()
    {
        return Student::query()->where('manager_id', $this->id);
    }

    /**
     * The set of student IDs this user may act on.
     *
     *  - Software Super Admin / Institution Admin : every student in the
     *    active institution.
     *  - Meal Manager                             : only members they manage.
     *  - Member                                   : only themselves.
     *
     * Returns null to mean "unrestricted within the institution" (handled by the
     * caller's institution scope), so callers can distinguish "all" from "none".
     *
     * @return array<int>|null
     */
    public function scopedStudentIds(): ?array
    {
        if ($this->isSuperAdmin() || $this->isInstitutionAdmin()) {
            return null; // all within the institution
        }

        if ($this->hasRole('Meal Manager')) {
            $ids = Student::query()
                ->where('manager_id', $this->id)
                ->pluck('id')
                ->all();

            // DUAL-ROLE: a Meal Manager who is ALSO a member must be able to see
            // and act on their OWN member record, which the manager_id filter
            // above excludes (a member is never their own manager).
            $own = $this->studentRecord();

            if ($own && ! in_array($own->id, $ids, true)) {
                $ids[] = $own->id;
            }

            return $ids;
        }

        // A member sees only their own record.
        $own = $this->studentRecord();

        return $own ? [$own->id] : [];
    }

    /**
     * An Institution Admin may only act within their own institution; a
     * Software Super Admin has global reach.
     */
    public function belongsToInstitution(?int $institutionId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $institutionId !== null && (int) $this->institution_id === (int) $institutionId;
    }

    /**
     * Does this user hold the global Super Admin role? There is exactly one
     * such role in the streamlined model; the match is case-insensitive so a
     * manually-cased row still resolves.
     */
    public function isSuperAdmin(): bool
    {
        return $this->roles->contains(
            fn ($role) => strtolower($role->name) === 'software super admin'
        );
    }

    /**
     * Is this user an Institution Admin (scoped admin, not global)?
     */
    public function isInstitutionAdmin(): bool
    {
        return $this->hasRole('Institution Admin');
    }

    /**
     * Get the transactions for the user.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
