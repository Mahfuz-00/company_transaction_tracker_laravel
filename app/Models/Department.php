<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A grouping of members (for example "Computer Science"), used for reporting
 * and for bulk operations such as rate assignment.
 *
 * MULTI-TENANCY
 * -------------
 * `use BelongsToInstitution` applies the automatic tenant scope and create-time
 * `institution_id` stamp from that trait, so departments are always confined to
 * the active institution.
 *
 * SLUG ROUTING
 * ------------
 * Eloquent's default route binding looks a model up by primary key. Overriding
 * `getRouteKeyName()` to return `slug` makes route parameters resolve by slug
 * instead, so URLs read /meals/departments/computer-science rather than an id.
 * Because two institutions could pick the same department name, the slug is
 * de-duplicated on save (see booted()/uniqueSlug()).
 */
class Department extends Model
{
    use BelongsToInstitution;
    use HasFactory;

    protected $fillable = ['institution_id', 'name', 'slug', 'description'];

    /**
     * Use the slug in URLs so routes read /meals/departments/computer-science.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Auto-derive a slug from the name when one is not supplied.
     * Runs on create and on name changes, and de-duplicates collisions
     * (computer-science, computer-science-2, ...) so the unique index holds.
     */
    protected static function booted(): void
    {
        static::saving(function (Department $department) {
            if (blank($department->slug) || $department->isDirty('name')) {
                $department->slug = static::uniqueSlug(
                    $department->slug ?: $department->name,
                    $department->id
                );
            }
        });
    }

    /**
     * Build a URL-safe slug that no other department already owns.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'department';
        $slug = $base;
        $suffix = 1;

        // Loop until the slug is free. `when()` adds the "ignore my own row"
        // clause only during an update, so renaming a department does not fight
        // its own existing slug.
        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /** The institution this department belongs to. */
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /** Every member assigned to this department (a one-to-many relationship). */
    public function students()
    {
        return $this->hasMany(Student::class);
    }

    /** Same relation as students(), but pre-filtered to active members. */
    public function activeStudents()
    {
        return $this->hasMany(Student::class)->where('status', 'active');
    }
}
