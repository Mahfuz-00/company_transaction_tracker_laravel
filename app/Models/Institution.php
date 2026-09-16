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
        'slug',
        'type',
        'currency_code',
        'timezone',
        'address',
        'contact_email',
        'contact_phone',
        'terminology',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'terminology' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
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
                'department' => 'Department',
                'departments' => 'Departments',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Hall Manager',
                'institution' => 'Hall',
            ],
        ],
        'college_dorm' => [
            'label' => 'College Dorm',
            'description' => 'College hostel or residential mess.',
            'terms' => [
                'member' => 'Boarder',
                'members' => 'Boarders',
                'department' => 'Faculty',
                'departments' => 'Faculties',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Mess Manager',
                'institution' => 'Hostel',
            ],
        ],
        'general_mess' => [
            'label' => 'General Mess',
            'description' => 'Shared mess with no academic or corporate structure.',
            'terms' => [
                'member' => 'Member',
                'members' => 'Members',
                'department' => 'Group',
                'departments' => 'Groups',
                'deposit' => 'Deposit',
                'deposits' => 'Deposits',
                'meal' => 'Meal',
                'meals' => 'Meals',
                'meal_manager' => 'Mess Manager',
                'institution' => 'Mess',
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
}
