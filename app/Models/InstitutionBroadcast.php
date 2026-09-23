<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A broadcast an INSTITUTION ADMIN sent to their own workspace.
 *
 * WHY IT IS TENANT-SCOPED
 * -----------------------
 * Unlike `StaffBroadcast` (the SSA's platform-wide notice, which deliberately
 * has no institution column), this model uses `BelongsToInstitution`. That gives
 * two guarantees the feature depends on:
 *
 *   1. Every query is filtered to the active tenant, so an institution's history
 *      is invisible to any other institution.
 *   2. `institution_id` is stamped automatically on create, so a row can never be
 *      written without an owner.
 *
 * The audience deliberately offers NO platform-wide option - "everyone on the
 * platform" is meaningless inside a single institution, and its absence keeps
 * this module from ever reaching across tenants.
 */
class InstitutionBroadcast extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'title',
        'body',
        'audience',
        'severity',
        'recipients',
        'sent_by',
    ];

    protected $casts = [
        'recipients' => 'integer',
    ];

    /**
     * The audiences an institution broadcast can target. Intentionally a subset
     * of the SSA's lists - there is no `all` (whole platform) option here.
     */
    public const AUDIENCES = [
        'institution_admins' => 'Institution Admins only',
        'admins' => 'All staff (admins + managers)',
        'members' => 'All members',
    ];

    /** The admin who sent it (null once that account is deleted). */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** The owning workspace. */
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** Human label for the audience key. */
    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? ucfirst((string) $this->audience);
    }
}
