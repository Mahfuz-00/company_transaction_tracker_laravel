<?php

namespace App\Support;

use App\Models\GoodsReceipt;
use App\Models\VendorInvoice;

/**
 * THREE-WAY MATCH (PO <-> GOODS RECEIPT <-> VENDOR INVOICE).
 *
 * The control that stops a mess paying for things it did not order or did not
 * receive. For every line we compare three independent numbers:
 *
 *   ORDERED   - what the PO says we committed to buy
 *   RECEIVED  - what the goods receipt says actually arrived
 *   BILLED    - what the vendor's invoice says we owe
 *
 * A line is CLEARED only when all three agree within tolerance. Any disagreement
 * is reported as a variance with the specific reason ("5 received but 10 billed",
 * "nothing received against this line") so a human can act on it rather than
 * staring at a generic "mismatch".
 *
 * TOLERANCE is relative (a percentage) plus an absolute floor, because a 2%
 * difference on a 1-unit line is noise while 2% on 10,000 kg is not.
 */
class ThreeWayMatcher
{
    /** Relative tolerance between compared quantities. */
    public const QUANTITY_TOLERANCE_PCT = 2.0;

    /** Absolute floor below which a difference is always ignored. */
    public const QUANTITY_ABSOLUTE_FLOOR = 0.01;

    /** Price tolerance, as a percentage of the ordered unit price. */
    public const PRICE_TOLERANCE_PCT = 1.0;

    /**
     * Run the match for an invoice against its PO.
     *
     * @return array{
     *   status:string, lines:array, summary:array, matched_at:string
     * }
     */
    public function match(VendorInvoice $invoice): array
    {
        $order = $invoice->purchaseOrder;

        // An invoice with no PO cannot be matched at all - it needs a human to
        // link it (or to raise a retrospective PO).
        if (! $order) {
            return [
                'status' => 'unmatched',
                'lines' => [],
                'summary' => [
                    'message' => 'This invoice is not linked to a purchase order, so no comparison could be made.',
                    'ordered_total' => 0.0,
                    'received_total' => 0.0,
                    'billed_total' => (float) $invoice->total,
                    'variance_total' => (float) $invoice->total,
                ],
                'matched_at' => now()->toDateTimeString(),
            ];
        }

        // Index the three sources by PO line id.
        $ordered = $order->items->keyBy('id');
        $received = GoodsReceipt::receivedQuantities($order);

        $billed = [];
        foreach ((array) $invoice->lines as $line) {
            $itemId = $line['item_id'] ?? null;

            if ($itemId === null) {
                continue;
            }

            $itemId = (int) $itemId;

            $billed[$itemId] = [
                'quantity' => ($billed[$itemId]['quantity'] ?? 0.0) + (float) ($line['quantity'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
            ];
        }

        $lines = [];
        $hasVariance = false;
        $orderedTotal = 0.0;
        $receivedTotal = 0.0;
        $billedTotal = 0.0;

        // Every ORDERED line must be accounted for.
        foreach ($ordered as $itemId => $item) {
            $qtyOrdered = (float) $item->quantity;
            $qtyReceived = (float) ($received[$itemId] ?? 0.0);
            $qtyBilled = (float) ($billed[$itemId]['quantity'] ?? 0.0);
            $priceOrdered = (float) $item->unit_price;
            $priceBilled = (float) ($billed[$itemId]['unit_price'] ?? $priceOrdered);

            $issues = [];

            // 1. Received vs ordered.
            if (! $this->withinTolerance($qtyOrdered, $qtyReceived, self::QUANTITY_TOLERANCE_PCT, self::QUANTITY_ABSOLUTE_FLOOR)) {
                if ($qtyReceived <= 0.0) {
                    $issues[] = 'Nothing was received against this line.';
                } elseif ($qtyReceived < $qtyOrdered) {
                    $issues[] = sprintf('Short delivery: %s ordered, only %s received.', $this->qty($qtyOrdered), $this->qty($qtyReceived));
                } else {
                    $issues[] = sprintf('Over-delivery: %s ordered, %s received.', $this->qty($qtyOrdered), $this->qty($qtyReceived));
                }
            }

            // 2. Billed vs received - the one that costs real money.
            if (! $this->withinTolerance($qtyReceived, $qtyBilled, self::QUANTITY_TOLERANCE_PCT, self::QUANTITY_ABSOLUTE_FLOOR)) {
                if ($qtyBilled > $qtyReceived) {
                    $issues[] = sprintf('Billed for more than was received: %s received but %s billed.', $this->qty($qtyReceived), $this->qty($qtyBilled));
                } else {
                    $issues[] = sprintf('Billed for less than was received: %s received but only %s billed.', $this->qty($qtyReceived), $this->qty($qtyBilled));
                }
            }

            // 3. Price vs the agreed PO price.
            if ($priceOrdered > 0 && ! $this->withinTolerance($priceOrdered, $priceBilled, self::PRICE_TOLERANCE_PCT, 0.0)) {
                $issues[] = sprintf(
                    'Price differs from the order: agreed %s but billed %s per %s.',
                    $this->money($priceOrdered),
                    $this->money($priceBilled),
                    $item->unit,
                );
            }

            $lineTotal = round($qtyBilled * $priceBilled, 2);
            $orderedTotal += round($qtyOrdered * $priceOrdered, 2);
            $receivedTotal += round($qtyReceived * $priceOrdered, 2);
            $billedTotal += $lineTotal;

            if ($issues !== []) {
                $hasVariance = true;
            }

            $lines[] = [
                'item_id' => $itemId,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity_ordered' => $qtyOrdered,
                'quantity_received' => $qtyReceived,
                'quantity_billed' => $qtyBilled,
                'unit_price_ordered' => $priceOrdered,
                'unit_price_billed' => $priceBilled,
                'line_total' => $lineTotal,
                'issues' => $issues,
                'clear' => $issues === [],
            ];
        }

        // A line the vendor billed that was never ordered is its own finding.
        foreach ($billed as $itemId => $data) {
            if ($ordered->has($itemId)) {
                continue;
            }

            $hasVariance = true;

            $lines[] = [
                'item_id' => $itemId,
                'description' => 'Not on the purchase order',
                'unit' => '',
                'quantity_ordered' => 0.0,
                'quantity_received' => 0.0,
                'quantity_billed' => (float) $data['quantity'],
                'unit_price_ordered' => 0.0,
                'unit_price_billed' => (float) $data['unit_price'],
                'line_total' => round((float) $data['quantity'] * (float) $data['unit_price'], 2),
                'issues' => ['This line was billed but does not appear on the purchase order.'],
                'clear' => false,
            ];

            $billedTotal += round((float) $data['quantity'] * (float) $data['unit_price'], 2);
        }

        $status = $hasVariance ? 'variance' : 'matched';

        return [
            'status' => $status,
            'lines' => $lines,
            'summary' => [
                'message' => $status === 'matched'
                    ? 'All three documents agree. The invoice is clear to pay.'
                    : 'One or more lines disagree. Review the flagged lines before paying.',
                'ordered_total' => round($orderedTotal, 2),
                'received_total' => round($receivedTotal, 2),
                'billed_total' => round($billedTotal, 2),
                'variance_total' => round($billedTotal - $receivedTotal, 2),
                'lines_flagged' => count(array_filter($lines, fn (array $line) => ! $line['clear'])),
            ],
            'matched_at' => now()->toDateTimeString(),
        ];
    }

    /** Run the match and persist the outcome on the invoice. */
    public function matchAndSave(VendorInvoice $invoice): array
    {
        $report = $this->match($invoice);

        $invoice->forceFill([
            'match_status' => $report['status'],
            'match_report' => $report,
            'matched_at' => now(),
            // A matched invoice becomes 'matched'; a variance stays 'pending' for
            // a human decision rather than silently progressing.
            'status' => $report['status'] === 'matched' ? 'matched' : 'variance',
        ])->save();

        return $report;
    }

    /**
     * Are two quantities/prices within tolerance?
     *
     * Tolerance is max(relative % of the reference, absolute floor) so a tiny line
     * is not flagged for a rounding-level difference while a large one still is.
     */
    protected function withinTolerance(float $reference, float $actual, float $pct, float $floor): bool
    {
        $allowed = max(abs($reference) * ($pct / 100), $floor);

        return abs($reference - $actual) <= $allowed;
    }

    protected function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    protected function money(float $value): string
    {
        return number_format($value, 2);
    }
}
