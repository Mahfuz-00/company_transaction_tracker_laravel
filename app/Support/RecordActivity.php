<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Attaches to any model and writes an audit entry on create / update / delete.
 *
 * Wired up in AppServiceProvider via Model::observe() so individual controllers
 * stay clean and can never forget to log a change.
 */
class RecordActivity
{
    public function created(Model $model): void
    {
        AuditLogger::created($model);
    }

    public function updated(Model $model): void
    {
        // Build a { field: { old, new } diff from the model's dirty state.
        $changes = [];

        foreach ($model->getChanges() as $field => $newValue) {
            $changes[$field] = [
                'old' => $model->getOriginal($field),
                'new' => $newValue,
            ];
        }

        // Nothing meaningful changed (e.g. only a timestamp touch).
        $changes = AuditLogger::clean($changes);

        if (empty($changes)) {
            return;
        }

        AuditLogger::updated($model, $changes);
    }

    public function deleted(Model $model): void
    {
        AuditLogger::deleted($model);
    }
}
