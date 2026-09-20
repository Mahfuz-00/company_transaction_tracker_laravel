<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vendor extends Model
{
    use BelongsToInstitution;
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
        'is_institution_hub',
        'recurrence',
        'lead_time_days',
        'recurring_amount',
        'opening_balance',
        'status',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'recurring_amount' => 'decimal:2',
        'is_institution_hub' => 'boolean',
    ];

    /**
     * How often this supplier is typically bought from. Drives the recurring
     * purchase view and expected spend forecasts.
     */
    public const RECURRENCES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'fortnightly' => 'Every two weeks',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'on_demand' => 'On demand',
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

    /** Vendors with a recurring purchase cadence (excludes on-demand). */
    public function scopeRecurring($query)
    {
        return $query->whereNotNull('recurrence')
            ->whereNotIn('recurrence', ['on_demand']);
    }

    /** The institution's own primary vendor / hub row. */
    public function scopeHubs($query)
    {
        return $query->where('is_institution_hub', true);
    }

    /**
     * Expected spend per cycle for a recurring vendor, from the configured
     * recurring amount (falling back to the historical average of expenses).
     */
    public function expectedRecurringSpend(): float
    {
        if ($this->recurring_amount !== null) {
            return (float) $this->recurring_amount;
        }

        return round((float) $this->expenses()->avg('amount'), 2);
    }

    public function getRecurrenceLabelAttribute(): ?string
    {
        return $this->recurrence ? (self::RECURRENCES[$this->recurrence] ?? ucfirst($this->recurrence)) : null;
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

    /**
     * The vendor's purchase history: every expense recorded against them, newest
     * first, joined with the ledger transaction that carries the authoritative
     * amount. Powers the "Purchase History" tab on the vendor profile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function purchaseHistory(int $limit = 100): array
    {
        return $this->expenses()
            ->with(['transaction:id,amount,item,category,payee,payment_method', 'recorder:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MealExpense $expense) => [
                'id' => $expense->id,
                'date' => $expense->created_at?->toDateString(),
                'description' => $expense->description ?: ($expense->transaction?->item ?? 'Expense'),
                'category' => $expense->category,
                'amount' => (float) ($expense->transaction?->amount ?? $expense->amount ?? 0),
                'payment_status' => $expense->payment_status,
                'payment_method' => $expense->transaction?->payment_method,
                'recorded_by' => $expense->recorder?->name,
            ])
            ->all();
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
