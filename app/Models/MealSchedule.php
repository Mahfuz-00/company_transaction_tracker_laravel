<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MealSchedule extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'student_id',
        'user_id',
        'status',          // 'off' (will not take meals) | 'on' (will take meals)
        'recurrence',      // 'one_time' | 'daily' | 'weekly' | 'custom'
        'starts_on',
        'ends_on',
        'interval_days',
        'weekdays',        // e.g. "1,2,3,4,5"
        'breakfast',
        'lunch',
        'dinner',
        'reason',
        'manager_status',  // 'pending' | 'acknowledged' | 'cancelled'
        'acknowledged_by',
        'acknowledged_at',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'breakfast' => 'boolean',
        'lunch' => 'boolean',
        'dinner' => 'boolean',
        'interval_days' => 'integer',
        'acknowledged_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Check if this schedule covers a specific date and meal type.
     */
    public function covers(Carbon|string $date, string $mealType = 'lunch'): bool
    {
        $d = Carbon::parse($date)->startOfDay();
        $start = $this->starts_on->startOfDay();
        $end = $this->ends_on ? $this->ends_on->startOfDay() : $start;

        if ($d->lt($start) || $d->gt($end)) {
            return false;
        }

        if (! in_array($mealType, ['breakfast', 'lunch', 'dinner'], true)) {
            return false;
        }

        if (! (bool) $this->{$mealType}) {
            return false;
        }

        if ($this->recurrence === 'one_time' || $this->recurrence === 'daily') {
            return true;
        }

        if ($this->recurrence === 'weekly') {
            $allowedDays = array_map('intval', explode(',', (string) $this->weekdays));

            return in_array($d->dayOfWeek, $allowedDays, true);
        }

        if ($this->recurrence === 'custom' && $this->interval_days > 0) {
            $diffDays = $start->diffInDays($d);

            return ($diffDays % $this->interval_days) === 0;
        }

        return true;
    }
}
