<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Institution;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Support\AuditLogger;
use App\Support\ThreeWayMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * PURCHASE ORDERS, GOODS RECEIPTS & VENDOR INVOICES (3-way match).
 *
 *   GET   /meals/purchase-orders                 : the list
 *   POST  /meals/purchase-orders                 : create a PO (draft) with items
 *   GET   /meals/purchase-orders/{po}            : one PO, with match state
 *   PATCH /meals/purchase-orders/{po}/approve    : sign it off
 *   PATCH /meals/purchase-orders/{po}/status     : advance the lifecycle
 *   POST  /meals/purchase-orders/{po}/receipts   : record goods received
 *   POST  /meals/purchase-orders/{po}/invoices   : record the vendor's invoice + match
 *   POST  /meals/purchase-orders/invoices/{i}/match : re-run the match
 *
 * APPROVAL CONTROL: a PO cannot leave `draft` until it is approved, and only an
 * Institution Admin (or the SSA) may approve - so a Meal Manager can raise an
 * order but not authorise the spend.
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $vendor = (string) $request->query('vendor', '');

        $orders = PurchaseOrder::query()
            ->with(['vendor:id,name', 'approver:id,name'])
            ->withCount('items')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($vendor !== '', fn ($q) => $q->where('vendor_id', (int) $vendor))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'status_tone' => $order->statusTone(),
                'vendor' => $order->vendor?->name,
                'total' => (float) $order->total,
                'items_count' => $order->items_count,
                'ordered_on' => $order->ordered_on?->format('j M Y'),
                'expected_on' => $order->expected_on?->format('j M Y'),
                'approver' => $order->approver?->name,
                'is_approvable' => $order->isApprovable(),
                'created_at' => $order->created_at?->format('j M Y'),
            ]);

        return Inertia::render('Meals/PurchaseOrders/Index', [
            'orders' => $orders,
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => PurchaseOrder::STATUSES,
            'filters' => ['status' => $status, 'vendor' => $vendor],
            'summary' => [
                'open' => PurchaseOrder::query()->whereIn('status', ['submitted', 'approved', 'ordered'])->count(),
                'awaiting_approval' => PurchaseOrder::query()->where('status', 'submitted')->count(),
                'variance_invoices' => VendorInvoice::query()->where('match_status', 'variance')->count(),
                'committed_value' => (float) PurchaseOrder::query()
                    ->whereIn('status', ['approved', 'ordered', 'received'])
                    ->sum('total'),
            ],
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'vendor:id,name', 'receipts.receiver:id,name', 'invoices', 'approver:id,name']);

        return Inertia::render('Meals/PurchaseOrders/Show', [
            'order' => [
                'id' => $purchaseOrder->id,
                'reference' => $purchaseOrder->reference,
                'status' => $purchaseOrder->status,
                'status_tone' => $purchaseOrder->statusTone(),
                'vendor' => $purchaseOrder->vendor?->name,
                'vendor_id' => $purchaseOrder->vendor_id,
                'ordered_on' => $purchaseOrder->ordered_on?->toDateString(),
                'expected_on' => $purchaseOrder->expected_on?->toDateString(),
                'subtotal' => (float) $purchaseOrder->subtotal,
                'tax' => (float) $purchaseOrder->tax,
                'total' => (float) $purchaseOrder->total,
                'notes' => $purchaseOrder->notes,
                'approver' => $purchaseOrder->approver?->name,
                'approved_at' => $purchaseOrder->approved_at?->format('j M Y H:i'),
                'is_approvable' => $purchaseOrder->isApprovable(),
                'items' => $purchaseOrder->items->map(fn (PurchaseOrderItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                ]),
                'receipts' => $purchaseOrder->receipts->map(fn (GoodsReceipt $receipt) => [
                    'id' => $receipt->id,
                    'reference' => $receipt->reference,
                    'received_on' => $receipt->received_on?->format('j M Y'),
                    'lines' => $receipt->lines,
                    'notes' => $receipt->notes,
                    'receiver' => $receipt->receiver?->name,
                ]),
                'invoices' => $purchaseOrder->invoices->map(fn (VendorInvoice $invoice) => [
                    'id' => $invoice->id,
                    'reference' => $invoice->reference,
                    'status' => $invoice->status,
                    'match_status' => $invoice->match_status,
                    'match_tone' => $invoice->matchTone(),
                    'invoiced_on' => $invoice->invoiced_on?->format('j M Y'),
                    'total' => (float) $invoice->total,
                    'match_report' => $invoice->match_report,
                ]),
            ],
            'statuses' => PurchaseOrder::STATUSES,
            'canApprove' => $this->canApprove(request()),
        ]);
    }

    /** Create a PO with its line items, in one transaction. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'expected_on' => ['nullable', 'date'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'submit' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:160'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $order = DB::transaction(function () use ($data, $request) {
            $order = PurchaseOrder::create([
                'institution_id' => Institution::current()?->id,
                'vendor_id' => $data['vendor_id'],
                'reference' => PurchaseOrder::generateReference(),
                'status' => $request->boolean('submit') ? 'submitted' : 'draft',
                'expected_on' => $data['expected_on'] ?? null,
                'tax' => $data['tax'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'description' => $item['description'],
                    'unit' => $item['unit'] ?? 'unit',
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    // line_total is computed by the model's saving hook.
                    'line_total' => 0,
                ]);
            }

            // Totals are derived from the lines that now exist.
            $order->recalculateTotalsAndSave();

            return $order;
        });

        AuditLogger::log('created', "raised purchase order {$order->reference}", $order, [
            'vendor_id' => $order->vendor_id,
            'total' => (float) $order->total,
        ], ['subject_label' => $order->reference, 'institution_id' => $order->institution_id]);

        return redirect()
            ->route('meals.purchase-orders.show', $order)
            ->with('success', "Purchase order {$order->reference} created.");
    }

    /**
     * APPROVE a purchase order.
     *
     * Only an Institution Admin or the SSA may approve - the control that stops a
     * Meal Manager authorising their own spend.
     */
    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $this->canApprove($request)) {
            return back()->with('error', 'Only an Institution Admin can approve a purchase order.');
        }

        if (! $purchaseOrder->isApprovable()) {
            return back()->with('error', "A purchase order in \"{$purchaseOrder->status}\" status cannot be approved.");
        }

        $purchaseOrder->forceFill([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        AuditLogger::log('updated', "approved purchase order {$purchaseOrder->reference}", $purchaseOrder, [
            'total' => (float) $purchaseOrder->total,
        ], ['subject_label' => $purchaseOrder->reference, 'institution_id' => $purchaseOrder->institution_id]);

        return back()->with('success', "Purchase order {$purchaseOrder->reference} approved.");
    }

    /** Advance the lifecycle (ordered, received, closed, cancelled). */
    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseOrder::STATUSES)],
        ]);

        // Approval is a separate, authorised action - never a status shortcut.
        if ($data['status'] === 'approved' && ! $this->canApprove($request)) {
            return back()->with('error', 'Only an Institution Admin can approve a purchase order.');
        }

        $purchaseOrder->update([
            'status' => $data['status'],
            'ordered_on' => $data['status'] === 'ordered' && ! $purchaseOrder->ordered_on
                ? now()->toDateString()
                : $purchaseOrder->ordered_on,
        ]);

        return back()->with('success', "Purchase order marked as {$data['status']}.");
    }

    /** Record goods actually received (the middle leg of the match). */
    public function storeReceipt(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:60'],
            'received_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array'],
            'lines.*.item_id' => ['required', 'exists:purchase_order_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $receipt = $purchaseOrder->receipts()->create([
            'institution_id' => $purchaseOrder->institution_id,
            'reference' => $data['reference'] ?? ('GRN-'.now()->format('ymd').'-'.$purchaseOrder->id),
            'received_on' => $data['received_on'],
            'lines' => $data['lines'],
            'notes' => $data['notes'] ?? null,
            'received_by' => $request->user()->id,
        ]);

        // Receiving goods naturally advances an ordered PO.
        if ($purchaseOrder->status === 'ordered') {
            $purchaseOrder->update(['status' => 'received']);
        }

        // Any existing invoice's match verdict may now be stale.
        $purchaseOrder->invoices->each(fn (VendorInvoice $invoice) => (new ThreeWayMatcher)->matchAndSave($invoice));

        AuditLogger::log('created', "recorded goods received for {$purchaseOrder->reference}", $receipt, [
            'lines' => count($data['lines']),
        ], ['subject_label' => $receipt->reference, 'institution_id' => $purchaseOrder->institution_id]);

        return back()->with('success', 'Goods receipt recorded and the three-way match refreshed.');
    }

    /** Record the vendor's invoice and immediately run the three-way match. */
    public function storeInvoice(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:80'],
            'invoiced_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:purchase_order_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        // Totals come from the billed lines, never from a client-supplied number.
        $subtotal = collect($data['lines'])
            ->sum(fn (array $line) => (float) $line['quantity'] * (float) $line['unit_price']);

        $invoice = $purchaseOrder->invoices()->create([
            'institution_id' => $purchaseOrder->institution_id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'reference' => $data['reference'],
            'status' => 'pending',
            'invoiced_on' => $data['invoiced_on'],
            'due_on' => $data['due_on'] ?? null,
            'subtotal' => round($subtotal, 2),
            'tax' => $data['tax'] ?? 0,
            'total' => round($subtotal + (float) ($data['tax'] ?? 0), 2),
            'lines' => $data['lines'],
            'notes' => $data['notes'] ?? null,
        ]);

        // THE MATCH: compare ordered vs received vs billed and record the verdict.
        $report = (new ThreeWayMatcher)->matchAndSave($invoice);

        AuditLogger::log('created', "recorded invoice {$invoice->reference} for {$purchaseOrder->reference}", $invoice, [
            'match_status' => $report['status'],
            'variance' => $report['summary']['variance_total'],
        ], ['subject_label' => $invoice->reference, 'institution_id' => $invoice->institution_id]);

        if ($report['status'] === 'matched') {
            return back()->with('success', "Invoice {$invoice->reference} recorded and matched. It is clear to pay.");
        }

        return back()->with(
            'error',
            "Invoice {$invoice->reference} recorded, but the three-way match found "
            .$report['summary']['lines_flagged'].' line(s) that disagree. Review before paying.'
        );
    }

    /** Re-run the match for one invoice. */
    public function rematch(Request $request, VendorInvoice $vendorInvoice)
    {
        $report = (new ThreeWayMatcher)->matchAndSave($vendorInvoice);

        return back()->with(
            $report['status'] === 'matched' ? 'success' : 'error',
            $report['status'] === 'matched'
                ? 'The three documents now agree.'
                : $report['summary']['lines_flagged'].' line(s) still disagree.'
        );
    }

    /** Only an Institution Admin (or the SSA) may approve spend. */
    protected function canApprove(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->isInstitutionAdmin() || $user->isSuperAdmin());
    }
}
