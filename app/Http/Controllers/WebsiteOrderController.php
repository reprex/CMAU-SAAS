<?php

namespace App\Http\Controllers;

use App\Checkout\InvoiceFiles;
use App\Checkout\OutboundEmails;
use App\Models\AuditLog;
use App\Models\PendingEmail;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderChange;
use App\Models\WebsiteOrderPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Staff: website orders (CM-xxxxx) from the CMAUDEV-CHECKOUT checkout (docs/website-checkout.md). */
class WebsiteOrderController extends Controller
{
    public function index(Request $request)
    {
        $stage = $request->query('stage');
        $q = trim((string) $request->query('q'));
        $orders = WebsiteOrder::query()
            ->withCount(['changeRequests as open_changes_count' => fn ($c) => $c->whereNull('handled_at')])
            ->withCount(['emails as draft_emails_count' => fn ($e) => $e->whereIn('status', ['draft', 'failed'])])
            ->when(is_numeric($stage), fn ($w) => $w->where('stage', (int) $stage))
            ->when($stage === 'open', fn ($w) => $w->where('stage', '<', WebsiteOrder::STAGE_DELIVERED))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhere('contact_name', 'like', "%{$q}%")->orWhere('organisation', 'like', "%{$q}%")))
            ->orderByRaw('case when stage < ? then 0 else 1 end', [WebsiteOrder::STAGE_DELIVERED])
            ->orderBy('need_by')->latest('id')
            ->paginate(50)->withQueryString();

        return view('website-orders.index', compact('orders', 'stage', 'q'));
    }

    public function show(WebsiteOrder $order)
    {
        $order->load(['lines', 'positions', 'changeRequests.handler', 'emails.sender', 'product', 'supplier']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', 'WebsiteOrder')->where('subject_id', (string) $order->id)->latest('id')->limit(20)->get();

        return view('website-orders.show', compact('order', 'history'));
    }

    public function update(Request $request, WebsiteOrder $order)
    {
        $data = $request->validate([
            'stage' => ['required', 'integer', Rule::in(array_keys(WebsiteOrder::STAGES))],
            'carrier' => ['nullable', Rule::in(WebsiteOrder::CARRIERS)],
            'consignment' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9 -]+$/'],
            'delivered_on' => 'nullable|date',
        ]);
        $before = $order->only(array_keys($data));
        if ((int) $data['stage'] !== $order->stage) {
            $data['stage_changed_at'] = now();
        }
        if ((int) $data['stage'] === WebsiteOrder::STAGE_DELIVERED && empty($data['delivered_on'])) {
            $data['delivered_on'] = now()->toDateString();
        }
        $order->update($data);
        AuditLog::record('website_order.updated', $order, ['before' => $before, 'after' => $order->only(array_keys($before))]);

        return redirect()->route('website-orders.show', $order)->with('status', 'Order updated.');
    }

    public function logo(WebsiteOrder $order, WebsiteOrderPosition $position)
    {
        abort_unless($position->website_order_id === $order->id && $position->logo_path, 404);
        $disk = Storage::disk(config('website.orders.artwork_disk'));
        abort_unless($disk->exists($position->logo_path), 404);
        AuditLog::record('website_order.logo_downloaded', $order, ['position' => $position->position]);
        $ext = pathinfo($position->logo_path, PATHINFO_EXTENSION);

        return $disk->download($position->logo_path, "{$order->number} {$position->position}.{$ext}", [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function invoice(WebsiteOrder $order, InvoiceFiles $invoices)
    {
        return response($invoices->pdf($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Invoice '.$order->number.'.pdf"',
        ]);
    }

    public function sendEmail(Request $request, WebsiteOrder $order, PendingEmail $email, OutboundEmails $emails)
    {
        abort_unless($email->website_order_id === $order->id, 404);
        $ok = $emails->send($email, $request->user());

        return redirect()->route('website-orders.show', $order)->with('status', $ok ? "Sent to {$email->recipient}." : 'Sending failed: '.$email->fresh()->error);
    }

    public function handleChange(Request $request, WebsiteOrder $order, WebsiteOrderChange $change)
    {
        abort_unless($change->website_order_id === $order->id, 404);
        $change->update(['handled_at' => now(), 'handled_by' => $request->user()->id]);
        AuditLog::record('website_order.change_handled', $order, ['change_id' => $change->id]);

        return redirect()->route('website-orders.show', $order)->with('status', 'Change request marked as handled.');
    }
}
