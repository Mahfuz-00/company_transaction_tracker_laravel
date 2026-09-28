<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A PURCHASE ORDER (PO) - what we committed to buy from a vendor.
 *
 * Lifecycle: draft -> submitted -> approved -> ordered -> received -> closed
 * (or cancelled). `approved_by` / `approved_at` record the sign-off, which is the
 * control an institution actually needs over spending.
 */
class PurchaseOrder extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'vendor_id',
        'reference',
        'status',
        'ordered_on',
        'expected_on',
        'subtotal',
        'tax',
        'total',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'ordered_on' => 'date',
        'expected_on' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public const STATUSES = [
        'draft', 'submitted', 'approved', 'ordered', 'received', 'closed', 'cancelled',
    ];

    /** Statuses that permit an approval action. */
    public const APPROVABLE = ['submitted'];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function invoices()
    {
        return $this->hasMany(VendorInvoice::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'approved', 'received', 'closed' => 'emerald',
            'submitted', 'ordered' => 'sky',
            'cancelled' => 'rose',
            default => 'slate',
        };
    }

    public function isApprovable(): bool
    {
        return in_array($this->status, self::APPROVABLE, true);
    }

    /** Recompute the money totals from the line items. */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items->sum(fn (PurchaseOrderItem $item) => (float) $item->line_total);

        $this->subtotal = round($subtotal, 2);
        $this->total = round($subtotal + (float) $this->tax, 2);
    }

    /**
     * Recompute AND persist in one step.
     *
     * Called by PurchaseOrderItem whenever a line is added, changed or removed, so
     * the parent PO's totals can never drift from its own line items.
     */
    public function recalculateTotalsAndSave(): void
    {
        // Refresh so the sum reflects the just-persisted line, not a stale cache.
        $this->unsetRelation('items');
        $this->load('items');

        $this->recalculateTotals();
        $this->save();
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'PO-'.now()->format('ym').'-'.strtoupper(Str::random(5));
        } while (static::withoutTenantScope()->where('reference', $reference)->exists());

        return $reference;
    }
}
