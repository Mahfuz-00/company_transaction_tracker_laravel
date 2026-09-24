<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subtitle',
        'slug',
        'invite_code',
        'type',
        'currency_code',
        'currency_settings',
        'theme',
        'logo_path',
        'banner_path',
        'timezone',
        'address',
        'contact_email',
        'contact_phone',
        'terminology',
        'settings',
        'subsidy_mode',
        'subscription_plan',
        'subscription_status',
        'subscription_amount',
        'subscription_started_at',
        'subscription_renews_at',
        'member_limit',
        'health_notes',
        'last_reviewed_at',
        'onboarding_mode',
        'trial_started_at',
        'trial_ends_at',
        'trial_reminder_sent_at',
        'converted_at',
        'is_active',
    ];

    protected $casts = [
        'terminology' => 'array',
        'settings' => 'array',
        'currency_settings' => 'array',
        'theme' => 'array',
        'is_active' => 'boolean',
        'subscription_amount' => 'decimal:2',
        'subscription_started_at' => 'date',
        'subscription_renews_at' => 'date',
        'member_limit' => 'integer',
        'last_reviewed_at' => 'datetime',
        'trial_started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'trial_reminder_sent_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ *
     * Subscription / tenant health (Software Super Admin monitoring)
     * ------------------------------------------------------------------ */

    /** Billing lifecycle states an institution can be in. */
    public const SUBSCRIPTION_STATUSES = [
        'trial' => ['label' => 'Trial', 'tone' => 'sky'],
        'paid' => ['label' => 'Paid', 'tone' => 'emerald'],
        'pending' => ['label' => 'Pending', 'tone' => 'amber'],
        'overdue' => ['label' => 'Overdue', 'tone' => 'rose'],
        'suspended' => ['label' => 'Suspended', 'tone' => 'slate'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'slate'],
    ];

    /** Human label + UI tone for the current subscription status. */
    public function subscriptionLabel(): string
    {
        return self::SUBSCRIPTION_STATUSES[$this->subscription_status]['label']
            ?? ucfirst((string) $this->subscription_status);
    }

    /* ------------------------------------------------------------------ *
     * Trial lifecycle (7-day free trial)
     * ------------------------------------------------------------------ */

    /** How long a default trial runs, in days. */
    public const TRIAL_DAYS = 7;

    /** Is this institution currently inside its free trial window? */
    public function isOnTrial(): bool
    {
        return $this->onboarding_mode === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** Has the trial lapsed (ended, still not converted)? */
    public function trialExpired(): bool
    {
        return $this->onboarding_mode === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast()
            && $this->converted_at === null;
    }

    /** Whole days remaining in the trial (0 when expired, null when not trialing). */
    public function trialDaysLeft(): ?int
    {
        if ($this->onboarding_mode !== 'trial' || $this->trial_ends_at === null) {
            return null;
        }

        // ceil so "3 hours left" still reads as "1 day left" rather than 0.
        return max(0, (int) ceil(now()->diffInMinutes($this->trial_ends_at, false) / 1440));
    }

    /**
     * A trial-specific status used by the Trial Management module, layered on
     * top of the generic subscription status.
     */
    public function trialState(): array
    {
        if ($this->onboarding_mode !== 'trial') {
            return ['key' => 'subscribed', 'label' => 'Subscribed', 'tone' => 'emerald'];
        }

        if ($this->converted_at !== null) {
            return ['key' => 'converted', 'label' => 'Converted', 'tone' => 'emerald'];
        }

        $days = $this->trialDaysLeft();

        if ($days === null) {
            return ['key' => 'trial', 'label' => 'Trial', 'tone' => 'sky'];
        }

        if ($days <= 0) {
            return ['key' => 'expired', 'label' => 'Trial expired', 'tone' => 'rose'];
        }

        if ($days <= 2) {
            return ['key' => 'ending', 'label' => "Ending in {$days}d", 'tone' => 'amber'];
        }

        return ['key' => 'trial', 'label' => "{$days} days left", 'tone' => 'sky'];
    }

    /**
     * Apply a 7-day trial window. Called at onboarding when the SSA chooses the
     * trial option.
     */
    public function startTrial(?int $days = null): void
    {
        $days ??= self::TRIAL_DAYS;

        $this->forceFill([
            'onboarding_mode' => 'trial',
            'subscription_status' => 'trial',
            'trial_started_at' => now(),
            'trial_ends_at' => now()->addDays($days),
            'trial_reminder_sent_at' => null,
            'converted_at' => null,
        ])->save();
    }

    /**
     * Convert this institution to a permanent subscription (leaving the trial).
     * Used by the SSA when a trial converts.
     */
    public function convertToSubscription(array $attributes = []): void
    {
        $this->forceFill(array_merge([
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
            'converted_at' => now(),
            'subscription_started_at' => $this->subscription_started_at ?? now()->toDateString(),
        ], $attributes))->save();
    }

    public function subscriptionTone(): string
    {
        return self::SUBSCRIPTION_STATUSES[$this->subscription_status]['tone'] ?? 'slate';
    }

    /**
     * A single health verdict the registry can render as a traffic light.
     *
     * Combines subscription state, billing date and usage against the plan's
     * member cap so the Software Super Admin sees a problem at a glance rather
     * than having to read three separate columns.
     */
    public function health(): array
    {
        $members = $this->members_count ?? $this->students()->count();
        $renews = $this->subscription_renews_at;
        $overdue = $renews !== null && $renews->isPast()
            && ! in_array($this->subscription_status, ['paid'], true);
        $nearCap = $this->member_limit !== null && $this->member_limit > 0
            && $members >= (int) round($this->member_limit * 0.9);

        if (in_array($this->subscription_status, ['suspended', 'cancelled'], true) || ! $this->is_active) {
            return ['key' => 'critical', 'label' => 'Critical', 'tone' => 'rose'];
        }

        if ($this->subscription_status === 'overdue' || $overdue) {
            return ['key' => 'at_risk', 'label' => 'Overdue', 'tone' => 'amber'];
        }

        if ($nearCap) {
            return ['key' => 'at_risk', 'label' => 'Near capacity', 'tone' => 'amber'];
        }

        if ($this->subscription_status === 'pending') {
            return ['key' => 'attention', 'label' => 'Pending', 'tone' => 'sky'];
        }

        return ['key' => 'healthy', 'label' => 'Healthy', 'tone' => 'emerald'];
    }

    /** Usage of the plan's member cap, 0-100 (null when there is no cap). */
    public function usagePercent(): ?float
    {
        $members = $this->members_count ?? $this->students()->count();

        if ($this->member_limit === null || $this->member_limit <= 0) {
            return null;
        }

        return round(min(100, ($members / $this->member_limit) * 100), 1);
    }

    /**
     * Accent colours a workspace can pick from. Each entry supplies the
     * Tailwind classes and the raw hex the theme layer injects as CSS
     * variables, so every component themes itself without change.
     */
    public const THEMES = [
        'indigo' => ['label' => 'Indigo', 'hex' => '#4f46e5', 'soft' => '#eef2ff'],
        'emerald' => ['label' => 'Emerald', 'hex' => '#059669', 'soft' => '#ecfdf5'],
        'sky' => ['label' => 'Sky', 'hex' => '#0284c7', 'soft' => '#e0f2fe'],
        'violet' => ['label' => 'Violet', 'hex' => '#7c3aed', 'soft' => '#f5f3ff'],
        'rose' => ['label' => 'Rose', 'hex' => '#e11d48', 'soft' => '#fff1f2'],
        'amber' => ['label' => 'Amber', 'hex' => '#d97706', 'soft' => '#fffbeb'],
        'slate' => ['label' => 'Slate', 'hex' => '#334155', 'soft' => '#f1f5f9'],
        'teal' => ['label' => 'Teal', 'hex' => '#0d9488', 'soft' => '#f0fdfa'],
    ];

    /** Default theme when an institution has not chosen one. */
    public const DEFAULT_THEME = [
        'accent' => 'indigo',
        'mode' => 'light',
        'radius' => 'lg',
        'density' => 'comfortable',
    ];

    /**
     * Default global currency settings. Used until the Software Super Admin
     * configures their own, so formatting never renders without a symbol.
     */
    public const DEFAULT_CURRENCY_SETTINGS = [
        'symbol' => '৳',
        'symbol_position' => 'before',
        'decimal_separator' => '.',
        'thousands_separator' => ',',
        'decimal_precision' => 2,
        'numbering_system' => 'short',
        'abbreviations' => true,
        // Magnitude at which a figure switches to its compact scale (K, Mil,
        // ...). Below it the full number is always shown. Default 1000 = only
        // thousands and above abbreviate.
        'abbreviation_threshold' => 1000,
    ];

    /**
     * The institution types an admin can choose, with the terminology each
     * applies. Presets live here (not in the DB) so they stay versioned with
     * the code; the `terminology` column only stores deliberate overrides.
     */
    public const TYPES = [
        'company' => [
            'label' => 'Company / Corporate Office',
            'description' => 'Staff cafeteria or office meal program.',
            'terms' => [
                'member' => 'Employee',
                'members' => 'Employees',
                'participant' => 'Employee',
                'participants' => 'Employees',
                'department' => 'Team',
                'departments' => 'Teams',
                'deposit' => 'Contribution',
                'deposits' => 'Contributions',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Canteen Manager',
                'institution' => 'Company',
            ],
        ],
        'university_dorm' => [
            'label' => 'University Dorm / Hall',
            'description' => 'University residential hall with shared meals.',
            'terms' => [
                'member' => 'Student',
                'members' => 'Students',
                'participant' => 'Student',
                'participants' => 'Students',
                'department' => 'Department',
                'departments' => 'Departments',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Hall Manager',
                'institution' => 'Institution',
            ],
        ],
        'college_dorm' => [
            'label' => 'College Dorm',
            'description' => 'College hostel or residential mess.',
            'terms' => [
                'member' => 'Boarder',
                'members' => 'Boarders',
                'participant' => 'Boarder',
                'participants' => 'Boarders',
                'department' => 'Faculty',
                'departments' => 'Faculties',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Mess Manager',
                'institution' => 'Institution',
            ],
        ],
        'general_mess' => [
            'label' => 'General Mess',
            'description' => 'Shared mess with no academic or corporate structure.',
            'terms' => [
                'member' => 'Member',
                'members' => 'Members',
                'participant' => 'Participant',
                'participants' => 'Participants',
                'department' => 'Group',
                'departments' => 'Groups',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Mess Manager',
                'institution' => 'Institution',
            ],
        ],
    ];

    /**
     * Institutions are addressed by slug in URLs (readable, stable).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve an institution from EITHER a slug or a numeric id.
     *
     * Route-model binding uses the slug above, but older links / callers that
     * pass an id would fail to bind and 404. Accepting both here makes every
     * institution route (switch, toggle, dashboard) robust regardless of which
     * identifier is supplied.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = static::query();

        if (ctype_digit((string) $value)) {
            return $query->whereKey($value)->first();
        }

        return $query->where('slug', $value)->first();
    }

    protected static function booted(): void
    {
        static::saving(function (Institution $institution) {
            if (blank($institution->slug)) {
                $institution->slug = Str::slug($institution->name) ?: 'institution';
            }

            // `timezone` is NOT NULL. An explicit NULL from any caller would
            // override the column default and fail the insert, so heal it here as
            // a last line of defence (see InstitutionProvisioner for the cause).
            if (blank($institution->timezone)) {
                $institution->timezone = config('app.timezone', 'UTC');
            }

            // Every institution gets an invite code on first save, so a public
            // signup can always be mapped to a tenant (never orphaned).
            if (blank($institution->invite_code)) {
                $institution->invite_code = static::generateInviteCode();
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * Invite code (public-signup tenant mapping)
     * ------------------------------------------------------------------ */

    /** A short, human-shareable code that is guaranteed unique. */
    public static function generateInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::query()->where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * Resolve an institution from a user-supplied invite code.
     * Case-insensitive and trimmed, so "abc123xy" works as "ABC123XY".
     */
    public static function findByInviteCode(?string $code): ?static
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return static::query()->where('invite_code', strtoupper($code))->first();
    }

    /** Rotate the invite code, revoking old signup links. */
    public function regenerateInviteCode(): string
    {
        $this->forceFill(['invite_code' => static::generateInviteCode()])->save();

        return $this->invite_code;
    }

    /* ------------------------------------------------------------------ *
     * Terminology
     * ------------------------------------------------------------------ */

    /**
     * The effective term map: type preset, overlaid with saved overrides.
     */
    public function terminologyMap(): array
    {
        $preset = static::TYPES[$this->type]['terms'] ?? static::TYPES['general_mess']['terms'];

        return array_merge($preset, $this->terminology ?? []);
    }

    /**
     * Resolve one term, falling back to the key itself so a missing entry
     * degrades to something readable rather than blank.
     */
    public function term(string $key, ?string $fallback = null): string
    {
        return $this->terminologyMap()[$key] ?? $fallback ?? $key;
    }

    /**
     * Human label for the configured type.
     */
    public function typeLabel(): string
    {
        return static::TYPES[$this->type]['label'] ?? ucfirst($this->type);
    }

    /* ------------------------------------------------------------------ *
     * Access
     * ------------------------------------------------------------------ */

    /**
     * The ACTIVE institution for THIS request, resolved in priority order:
     *
     *   1. The session tenant ("switched view") - set when a Software Super
     *      Admin uses "Access Dashboard" to enter a workspace. This is what makes
     *      tenant switching work per-user WITHOUT mutating a global flag.
     *   2. The signed-in user's own institution - an Institution Admin / Meal
     *      Manager / Member is permanently bound to their workspace.
     *   3. The first active institution (a safe default for a brand-new install).
     *
     * WHY THIS REPLACED THE GLOBAL is_active FLAG:
     * The previous resolver flipped a single global `is_active` column on switch.
     * That is shared across EVERY user and session, so switching deactivated all
     * other institutions and produced 404s / mismatched workspaces. The session
     * scope fixes that: each user gets their own view; the DB row is untouched.
     */
    public static function current(): ?static
    {
        // 1. An explicit, session-scoped tenant (SSA "switched view").
        $tenantId = static::sessionTenantId();
        if ($tenantId) {
            $tenant = static::query()->whereKey($tenantId)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        // 2. The signed-in user's own institution.
        $userInstitutionId = auth()->user()?->institution_id;
        if ($userInstitutionId) {
            $tenant = static::query()->whereKey($userInstitutionId)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        // 3. Fallback: the first active institution (fresh install / guests).
        return static::query()->where('is_active', true)->orderBy('id')->first()
            ?? static::query()->orderBy('id')->first();
    }

    /**
     * The session tenant id, if one is set and we are in a web (session) context.
     * Guarded so the model can still be used from console/queue contexts where no
     * session exists.
     */
    public static function sessionTenantId(): ?int
    {
        try {
            if (! app()->bound('session')) {
                return null;
            }

            $value = app('session')->get('tenant_id');

            return $value ? (int) $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The id of the institution the given user is HARD-BOUND to (their own),
     * ignoring any session switch. Super Admins return null (global reach).
     */
    public static function boundInstitutionId(?User $user = null): ?int
    {
        $user ??= auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return null;
        }

        return $user->institution_id ? (int) $user->institution_id : null;
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function subsidies()
    {
        return $this->hasMany(Subsidy::class);
    }

    /** Member claims / disputes raised within this institution. */
    public function claims()
    {
        return $this->hasMany(Claim::class);
    }

    public function subsidySources()
    {
        return $this->hasMany(SubsidySource::class);
    }

    public function mealRateSetting()
    {
        return $this->hasOne(MealRateSetting::class);
    }

    /** Users whose primary affiliation is this institution. */
    public function users()
    {
        return $this->hasMany(User::class);
    }



    /* ------------------------------------------------------------------ *
     * Theme + branding
     * ------------------------------------------------------------------ */

    /**
     * The effective theme: defaults overlaid with saved choices.
     */
    public function themeSettings(): array
    {
        return array_merge(self::DEFAULT_THEME, $this->theme ?? []);
    }

    /** Resolved accent palette (hex + soft) for the current theme. */
    public function accentPalette(): array
    {
        $accent = $this->themeSettings()['accent'];

        return self::THEMES[$accent] ?? self::THEMES[self::DEFAULT_THEME['accent']];
    }

    /**
     * Public URL for the logo, or null when none has been uploaded.
     * Carries a cache-busting version so a replaced logo appears immediately.
     */
    public function logoUrl(): ?string
    {
        return $this->brandingUrl($this->logo_path);
    }

    /** Public URL for the login banner, with the same cache-busting version. */
    public function bannerUrl(): ?string
    {
        return $this->brandingUrl($this->banner_path);
    }

    /**
     * A public URL for a branding file, versioned by last-modified time so the
     * browser fetches the new image the instant a logo/banner is replaced.
     */
    protected function brandingUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = \Storage::disk('public');
        $url = $disk->url($path);

        try {
            if ($disk->exists($path)) {
                $url .= '?v=' . $disk->lastModified($path);
            }
        } catch (\Throwable $e) {
            // Fall through with the plain URL if the disk is unavailable.
        }

        return $url;
    }

    /**
     * Admins attached to this institution, for the super-admin registry view.
     */
    public function admins()
    {
        return $this->hasMany(User::class)->role(['Institution Admin']);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /* ------------------------------------------------------------------ *
     * Currency
     * ------------------------------------------------------------------ */

    /**
     * The effective global currency settings: saved config merged over the
     * defaults, so a missing key never renders a blank symbol.
     */
    public function currencySettings(): array
    {
        return array_merge(self::DEFAULT_CURRENCY_SETTINGS, $this->currency_settings ?? []);
    }

    /**
     * The vendor row that represents this institution acting as its own
     * primary supplier / hub in the multi-vendor ecosystem.
     */
    public function hubVendor(): ?Vendor
    {
        return $this->vendors()->where('is_institution_hub', true)->first();
    }

    /**
     * Ensure the institution has a hub vendor, creating it on first use.
     */
    public function ensureHubVendor(): Vendor
    {
        return $this->hubVendor() ?? $this->vendors()->create([
            'name' => $this->name . ' (Central)',
            'category' => 'other',
            'is_institution_hub' => true,
            'status' => 'active',
            'notes' => 'Institution acting as the primary vendor / supply hub.',
        ]);
    }
}
