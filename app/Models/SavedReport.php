<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved report definition for the dynamic reports builder.
 *
 * `definition` is the query spec (dataset + metric + grouping + filters). It is
 * validated against App\Support\ReportBuilder's whitelist on save AND on run, so a
 * stored definition can never request an arbitrary column.
 */
class SavedReport extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'user_id',
        'name',
        'description',
        'definition',
        'is_shared',
        'is_pinned',
        'last_run_at',
        'run_count',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_shared' => 'boolean',
        'is_pinned' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Visible to the acting user: their own, plus anything shared in the tenant. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_shared', true);
        });
    }
}
