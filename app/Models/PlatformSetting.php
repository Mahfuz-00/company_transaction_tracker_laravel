<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A GLOBAL platform setting (key/value) - the Software Super Admin's own
 * configuration, deliberately NOT tenant-scoped.
 *
 * Unlike every domain model, this is not filtered by institution: it configures
 * the platform itself (e.g. the SMTP relay every institution's mail flows
 * through). `value` is cast to an array so callers can store a structured
 * payload under a single key.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];
}
