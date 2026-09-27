<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A LINK between a local user and an external identity (Google / Microsoft).
 *
 * One user may hold several links, so this is a normal one-to-many from User.
 * The unique (provider, provider_user_id) index in the migration is the real
 * guarantee: two local accounts can never claim the same external identity.
 */
class SocialAccount extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'user_id',
        'institution_id',
        'provider',
        'provider_user_id',
        'email',
        'tenant_id',
        'name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_login_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        // Tokens are secrets: encrypted at rest, never serialised to the UI.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** Providers the platform supports, with display metadata. */
    public const PROVIDERS = [
        'google' => [
            'label' => 'Google Workspace',
            'short' => 'Google',
            'icon' => 'google',
            'note' => 'Sign in with your institution Google account.',
        ],
        'microsoft' => [
            'label' => 'Microsoft Entra',
            'short' => 'Microsoft',
            'icon' => 'microsoft',
            'note' => 'Sign in with your institution Microsoft 365 account.',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'short' => 'Facebook',
            'icon' => 'facebook',
            'note' => 'Sign in with your Facebook account.',
        ],
        'x' => [
            'label' => 'X (Twitter)',
            'short' => 'X',
            'icon' => 'x',
            'note' => 'Sign in with your X account.',
        ],
    ];

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? ucfirst($provider);
    }
}
