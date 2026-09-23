<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One member's meal counts for a single day — one row per member per date.
 *
 * Each of breakfast / lunch / dinner stores how many meals of that type were
 * eaten (usually 0 or 1, but fractional/higher counts are allowed), and the
 * `total_meals` accessor sums them. A meal CHARGE is derived by multiplying
 * these counts by the prevailing MealRate, so this model deliberately stores
 * COUNTS, never money.
 *
 * MULTI-TENANCY
 * -------------
 * `use BelongsToInstitution` applies the automatic tenant scope and the
 * create-time `institution_id` stamp from that trait, so entries are confined
 * to the active institution.
 */
class MealEntry extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = ['institution_id', 'student_id', 'date', 'breakfast', 'lunch', 'dinner', 'recorded_by', 'notes'];

    /**
     * Casts turn raw column values into richer PHP types on read: `date` becomes
     * a Carbon instance (so `$entry->date->format(...)` works) and the three
     * count columns become integers.
     */
    protected $casts = [
        'date' => 'date',
        'breakfast' => 'integer',
        'lunch' => 'integer',
        'dinner' => 'integer',
    ];

    /** The member these meal counts belong to. */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** The user who recorded the entry. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Accessor: the `getXxxAttribute` naming convention exposes this as
     * `$entry->total_meals` (the stored column is `total_meals` with an
     * underscore, the method name is camel-cased). Eloquent calls it lazily on
     * access; the `?? 0` guards a null count column so the sum is never
     * "null + n".
     */
    public function getTotalMealsAttribute()
    {
        return ($this->breakfast ?? 0) + ($this->lunch ?? 0) + ($this->dinner ?? 0);
    }
}
