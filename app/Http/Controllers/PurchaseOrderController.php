<?php

namespace App\Http\Controllers;

use App\Checkout\OutboundEmails;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\WebsiteOrder;
use App\Orders\PurchaseOrders;
use App\Pdf\QuotePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Supplier purchase orders (Scott, 7 Nov 2026; docs/purchase-orders.md). */
class PurchaseOrderController extends Controller
{
    public function store(Request $request, WebsiteOrder $order, PurchaseOrders $orders)
    {
        abort_if($order->isSamples() || ! $order->supplier_id, 422, 'Purchase orders are for branded website orders.');
        $po = $orders->fromWebsiteOrder($order, $request->user());

        return redirect()->route('purchase-orders.show', $po)->with('status', "{$po->number} created. Check it, add any notes, then send it.");
    }

    public function show(PurchaseOrder $po)
    {
        $po->load(['supplier', 'order', 'emails.sender']);

        return view('purchase-orders.show', ['po' => $po]);
    }

    public function update(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate(['freight_cost' => 'nullable|numeric|min:0|max:99999', 'notes' => 'nullable|string|max:2000']);
        $po->fill($data);
        $po->recalculate();
        $po->save();
        if ($po->order) {
            AuditLog::record('purchase_order.updated', $po->order, ['po' => $po->number] + $data);
        }

        return redirect()->route('purchase-orders.show', $po)->with('status', 'Purchase order updated.');
    }

    public function pdf(PurchaseOrder $po, QuotePdf $pdf)
    {
        return response($pdf->purchaseOrder($po), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$po->number.'.pdf"']);
    }

    /** Staff's one click: the PO email to the supplier's orders email, with the PDF. */
    public function send(Request $request, PurchaseOrder $po, OutboundEmails $emails)
    {
        $po->load('supplier');
        if (! filter_var((string) $po->supplier?->orders_email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('purchase-orders.show', $po)->with('status', 'Add the supplier\'s orders email first (Admin › Suppliers).');
        }
        $email = $emails->preparePurchaseOrder($po);
        $ok = $emails->send($email, $request->user());

        return redirect()->route('purchase-orders.show', $po)->with('status', $ok ? "Sent to {$email->recipient}." : 'Sending failed: '.$email->fresh()->error);
    }

    // ---- The supplier's artwork link (no login: the PO's token is the key; 120 days) ----------------

    public function artwork(string $token)
    {
        $po = $this->byToken($token);

        return view('purchase-orders.artwork', ['po' => $po, 'positions' => $po->order?->positions ?? collect()]);
    }

    public function artworkFile(string $token, int $n)
    {
        $po = $this->byToken($token);
        $p = $po->order?->positions->values()->get($n);
        $disk = Storage::disk(config('website.orders.artwork_disk'));
        abort_unless($p && $p->logo_path && $disk->exists($p->logo_path), 404);
        if ($po->order) {
            AuditLog::record('purchase_order.artwork_downloaded', $po->order, ['po' => $po->number, 'position' => $p->position]);
        }
        $ext = pathinfo($p->logo_path, PATHINFO_EXTENSION);

        return $disk->download($p->logo_path, "{$po->number} {$p->position}.{$ext}", ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function byToken(string $token): PurchaseOrder
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token), 404);
        $po = PurchaseOrder::query()->where('artwork_token', $token)->with('order.positions')->first();
        abort_unless($po && $po->created_at->gt(now()->subDays(120)), 404);

        return $po;
    }
}
