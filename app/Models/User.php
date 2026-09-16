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
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

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
        'phone',
        'status',
        'invitation_pending',
        'password',
        'last_login_at',
    ];

    /** Public URL for the profile picture, or null when none is set. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? \Storage::disk('public')->url($this->avatar_path) : null;
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
        'password' => 'hashed',
    ];

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
