<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An emailed invitation that lets a member set their own password and finish
 * creating their account. The plaintext token is emailed once; only its hash
 * is stored.
 */
class MemberInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'student_id',
        'email',
        'name',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    /** How long an invitation stays valid. */
    public const TTL_DAYS = 7;

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Issue a fresh invitation row for an email, invalidating any prior pending
     * invite for the same address.
     */
    public static function issue(array $attributes): array
    {
        // Supersede earlier pending invites for the same email so only the
        // newest link works.
        static::query()
            ->where('email', $attributes['email'])
            ->whereNull('accepted_at')
            ->delete();

        $plainToken = Str::random(64);

        $invitation = static::create($attributes + [
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$invitation, $plainToken];
    }

    /** Resolve a plaintext token back to its invitation, if usable. */
    public static function findByToken(string $plainToken): ?static
    {
        return static::query()
            ->where('token', hash('sha256', $plainToken))
            ->first();
    }
}
