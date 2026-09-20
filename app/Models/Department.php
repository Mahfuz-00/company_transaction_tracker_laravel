<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function activeStudents()
    {
        return $this->hasMany(Student::class)->where('status', 'active');
    }
}
