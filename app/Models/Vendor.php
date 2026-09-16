<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'slug',
        'contact_person',
        'phone',
        'email',
        'address',
        'category',
        'opening_balance',
        'status',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
    ];

    public const CATEGORIES = [
        'groceries',
        'vegetables',
        'meat_fish',
        'grains',
        'cooking_gas',
        'kitchen_supplies',
        'utilities',
        'other',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::saving(function (Vendor $vendor) {
            if (blank($vendor->slug) || $vendor->isDirty('name')) {
                $vendor->slug = static::uniqueSlug(
                    $vendor->slug ?: $vendor->name,
                    $vendor->id
                );
            }
        });
    }

    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'vendor';
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

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function expenses()
    {
        return $this->hasMany(MealExpense::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInCategory($query, $category)
    {
        return $category ? $query->where('category', $category) : $query;
    }

    /* ------------------------------------------------------------------ *
     * Derived figures
     * ------------------------------------------------------------------ */

    /**
     * Total purchased from this vendor, from the ledger (authoritative amount).
     * Uses an aggregate when the relation isn't loaded, so listing vendors
     * doesn't fire a query per row.
     */
    public function getTotalPurchasedAttribute(): float
    {
        if ($this->relationLoaded('transactions')) {
            return (float) $this->transactions
                ->where('type', 'out')
                ->sum('amount');
        }

        return (float) $this->transactions()->where('type', 'out')->sum('amount');
    }

    /**
     * Outstanding balance: the opening balance plus anything bought on credit
     * that hasn't been marked paid yet.
     */
    public function outstandingBalance(): float
    {
        $unpaid = (float) $this->expenses()
            ->where('payment_status', 'unpaid')
            ->sum('amount');

        return round((float) $this->opening_balance + $unpaid, 2);
    }

    /** "Meat & Fish" from "meat_fish". */
    public function getCategoryLabelAttribute(): ?string
    {
        if (blank($this->category)) {
            return null;
        }

        return collect(explode('_', $this->category))
            ->map(fn ($part) => ucfirst($part))
            ->implode(' ');
    }
}
