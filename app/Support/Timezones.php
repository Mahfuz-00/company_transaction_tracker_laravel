<?php

namespace App\Support;

use DateTimeZone;

/**
 * The canonical list of VALID global timezones.
 *
 * A free-text timezone field let operators type anything ("Dhaka", "GMT+6",
 * "asia/dhaka"), which saves cleanly but silently breaks any date formatting
 * that expects a real IANA identifier. This helper is the single source of the
 * options offered by the UI dropdowns AND the validation rule that rejects
 * anything not on the list, so the two can never drift apart.
 *
 * The list is the platform's common zones first (fast to pick), then every
 * remaining IANA identifier alphabetically.
 */
class Timezones
{
    /** Frequently used zones, surfaced at the top of the dropdown. */
    public const COMMON = [
        'UTC',
        'Asia/Dhaka',
        'Asia/Kolkata',
        'Asia/Karachi',
        'Asia/Dubai',
        'Asia/Kathmandu',
        'Asia/Singapore',
        'Asia/Tokyo',
        'Europe/London',
        'Europe/Paris',
        'Europe/Berlin',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'Australia/Sydney',
        'Pacific/Auckland',
        'Africa/Cairo',
        'Africa/Lagos',
    ];

    /** All valid timezone identifiers, common ones first. */
    public static function all(): array
    {
        $all = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        $common = array_values(array_intersect(self::COMMON, $all));
        $rest = array_values(array_diff($all, $common));

        return array_merge($common, $rest);
    }

    /** `{ value, label }` options for a <select>, common zones first. */
    public static function options(): array
    {
        return array_map(fn (string $tz) => ['value' => $tz, 'label' => $tz], self::all());
    }

    /** Is this a real IANA timezone identifier? */
    public static function isValid(?string $timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true);
    }
}
