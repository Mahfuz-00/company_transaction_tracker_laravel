<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single line on a purchase order.
 *
 * `line_total` is stored (not computed on read) so a historical PO keeps the price
 * it was agreed at, even if a later edit changes the unit price default.
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Keep line_total in step whenever quantity or price is set. */
    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderItem $item) {
            if ($item->isDirty(['quantity', 'unit_price']) || blank($item->line_total)) {
                $item->line_total = round((float) $item->quantity * (float) $item->unit_price, 2);
            }
        });

        // Any change to a line must roll up into the parent PO's totals.
        static::saved(fn (PurchaseOrderItem $item) => $item->purchaseOrder?->recalculateTotalsAndSave());
        static::deleted(fn (PurchaseOrderItem $item) => $item->purchaseOrder?->recalculateTotalsAndSave());
    }
}
