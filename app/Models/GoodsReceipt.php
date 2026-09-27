<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A GOODS RECEIPT - what actually arrived against a PO.
 *
 * The middle leg of the three-way match: PO (ordered) <-> receipt (received) <->
 * invoice (billed). `lines` is a JSON array of { item_id, quantity }.
 */
class GoodsReceipt extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'purchase_order_id',
        'institution_id',
        'reference',
        'received_on',
        'lines',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'lines' => 'array',
        'received_on' => 'date',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Total received per PO item, summed across every receipt for that PO.
     *
     * @return array<int, float> keyed by purchase_order_item id
     */
    public static function receivedQuantities(PurchaseOrder $order): array
    {
        $totals = [];

        foreach ($order->receipts as $receipt) {
            foreach ((array) $receipt->lines as $line) {
                $itemId = $line['item_id'] ?? null;

                if ($itemId === null) {
                    continue;
                }

                $totals[(int) $itemId] = ($totals[(int) $itemId] ?? 0.0) + (float) ($line['quantity'] ?? 0);
            }
        }

        return $totals;
    }
}
