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
        'is_active',
    ];

    protected $casts = [
        'terminology' => 'array',
        'settings' => 'array',
        'currency_settings' => 'array',
        'theme' => 'array',
        'is_active' => 'boolean',
    ];

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

       public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::saving(function (Institution $institution) {
            if (blank($institution->slug)) {
                $institution->slug = Str::slug($institution->name) ?: 'institution';
            }
        });
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
     * The active institution. Single-tenant today; the query is centralised so
     * a request-scoped resolver can replace it later without touching callers.
     */
    public static function current(): ?static
    {
        return static::query()->where('is_active', true)->orderBy('id')->first()
            ?? static::query()->orderBy('id')->first();
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
                $url .= '?v='.$disk->lastModified($path);
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
            'name' => $this->name.' (Central)',
            'category' => 'other',
            'is_institution_hub' => true,
            'status' => 'active',
            'notes' => 'Institution acting as the primary vendor / supply hub.',
        ]);
    }
}
