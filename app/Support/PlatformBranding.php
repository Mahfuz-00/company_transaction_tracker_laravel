<?php

namespace App\Support;

/**
 * Platform branding - the master software name and chrome labels.
 *
 * THE single source of truth for the PLATFORM's identity (as opposed to a
 * tenant institution's). Every place that renders global chrome - the landing
 * header, the login top bar, the SSA sidebar - reads from here, so changing
 * `config/platform.php` updates all of them at once.
 *
 * It is shared to the frontend as the `platform` Inertia prop (see
 * HandleInertiaRequests), where the matching usePlatformBranding() React hook
 * consumes it.
 */
class PlatformBranding
{
    /**
     * The full branding payload shared with the frontend.
     *
     * @return array{name:string, tagline:string, admin_subtitle:string, control_center:string, logo_url:?string}
     */
    public static function toArray(): array
    {
        return [
            'name' => static::name(),
            'tagline' => static::tagline(),
            'admin_subtitle' => static::adminSubtitle(),
            'control_center' => static::controlCenter(),
            'logo_url' => static::logoUrl(),
        ];
    }

    public static function name(): string
    {
        return (string) config('platform.name', config('app.name', 'Meal Tracking Platform'));
    }

    public static function tagline(): string
    {
        return (string) config('platform.tagline', 'Multi-institution meal & expense platform');
    }

    public static function adminSubtitle(): string
    {
        return (string) config('platform.admin_subtitle', 'Super Admin Portal');
    }

    public static function controlCenter(): string
    {
        return (string) config('platform.control_center', static::name());
    }

    /** Public URL for the platform logo, or null when none is configured. */
    public static function logoUrl(): ?string
    {
        $path = config('platform.logo_path');

        if (blank($path)) {
            return null;
        }

        try {
            $disk = \Storage::disk('public');
            $url = $disk->url($path);

            if ($disk->exists($path)) {
                $url .= '?v='.$disk->lastModified($path);
            }

            return $url;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
