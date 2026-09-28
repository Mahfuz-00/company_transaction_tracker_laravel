<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * A VENDOR INVOICE - what the vendor billed.
 *
 * The final leg of the three-way match. `match_status` / `match_report` record the
 * outcome so the UI can explain, in plain language, exactly which line did not
 * agree and by how much.
 */
class VendorInvoice extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'vendor_id',
        'purchase_order_id',
        'reference',
        'status',
        'invoiced_on',
        'due_on',
        'subtotal',
        'tax',
        'total',
        'lines',
        'match_status',
        'match_report',
        'matched_at',
        'notes',
    ];

    protected $casts = [
        'lines' => 'array',
        'match_report' => 'array',
        'invoiced_on' => 'date',
        'due_on' => 'date',
        'matched_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /** Match outcomes, in increasing order of concern. */
    public const MATCH_STATUSES = ['unmatched', 'matched', 'variance'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function matchTone(): string
    {
        return match ($this->match_status) {
            'matched' => 'emerald',
            'variance' => 'rose',
            default => 'amber',
        };
    }

    public function isMatched(): bool
    {
        return $this->match_status === 'matched';
    }
}
