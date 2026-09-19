<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide broadcast the Software Super Admin sent.
 *
 * Platform-level (NOT institution-scoped), so it does not use the tenant trait.
 */
class StaffBroadcast extends Model
{
    use HasFactory;

    protected $fillable = [
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

    /** The SSA who sent it (null once that account is deleted). */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
