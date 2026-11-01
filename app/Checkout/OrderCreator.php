<?php

namespace App\Checkout;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\WebsiteOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Create order and send me an invoice" (docs/website-checkout.md): recompute
 * everything, refuse if any direct-order rule fails, then save the order with
 * its SKU lines and branding positions, store each logo privately, make the
 * invoice PDF and prepare the invoice email.
 */
class OrderCreator
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly InvoiceFiles $invoices,
        private readonly OutboundEmails $emails,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request (WebsiteCheckoutController::order)
     * @param  array<int, UploadedFile>  $logos  position index => logo file
     * @return array{order: ?WebsiteOrder, result: array<string, mixed>}
     */
    public function create(array $data, array $logos, ?string $ip = null): array
    {
        $result = $this->pricer->evaluate([
            'product_id' => (int) $data['product_id'],
            'lines' => $data['lines'],
            'positions' => collect($data['positions'])->map(fn ($p, $i) => [
                'name' => $p['name'], 'decID' => $p['decID'], 'colours' => $p['colours'] ?? null, 'width_mm' => $p['width_mm'] ?? null,
                'has_logo' => isset($logos[$i]) && $logos[$i]->isValid(),
            ])->values()->all(),
            'delivery' => ['country' => $data['address']['country'], 'postcode' => $data['address']['postcode']],
            'freight_service' => $data['freight_service'],
            'need_by' => $data['need_by'],
        ]);
        if (! ($result['found'] ?? false) || ! $result['can_order']) {
            return ['order' => null, 'result' => $result];
        }

        $stored = [];
        try {
            $order = DB::transaction(function () use ($data, $result, $logos, $ip, &$stored) {
                $chosen = collect($result['freight']['options'])->firstWhere('id', $result['freight']['chosen']);
                $order = WebsiteOrder::query()->create([
                    'number' => $this->nextNumber(),
                    'email' => mb_strtolower(trim($data['contact']['email'])),
                    'contact_name' => trim($data['contact']['name']),
                    'organisation' => filled($data['contact']['organisation'] ?? null) ? trim($data['contact']['organisation']) : null,
                    'phone' => trim($data['contact']['phone']),
                    'address_unit' => filled($data['address']['unit'] ?? null) ? trim($data['address']['unit']) : null,
                    'address_street' => trim($data['address']['street']),
                    'address_suburb' => trim($data['address']['suburb']),
                    'address_state' => filled($data['address']['state'] ?? null) ? trim($data['address']['state']) : null,
                    'address_postcode' => trim($data['address']['postcode']),
                    'address_country' => mb_strtoupper($data['address']['country']),
                    'need_by' => $data['need_by'],
                    'event_name' => $data['event']['name'] ?? null,
                    'event_date' => $data['event']['date'] ?? null,
                    'event_place' => $data['event']['place'] ?? null,
                    'product_id' => $result['product']['id'],
                    'supplier_id' => Product::query()->whereKey($result['product']['id'])->value('supplier_id'),
                    'payment_terms' => $result['payment_terms'],
                    'freight_service' => $chosen['id'],
                    'freight_name' => $chosen['name'],
                    'carrier' => $chosen['carrier'],
                    'freight_breakdown' => collect($result['freight'])->only(['country', 'zone', 'cartons', 'kg', 'm3', 'chargeable_kg', 'basis'])->all() + ['days' => $chosen['days']],
                    'eta' => $result['delivery_estimate']['delivery_date'],
                    'eta_breakdown' => $result['delivery_estimate'] + ['deadline' => $result['deadline']],
                    'export' => $result['export'],
                    'goods' => $result['totals']['goods'],
                    'setup' => $result['totals']['setup'],
                    'freight' => $result['totals']['freight'],
                    'subtotal' => $result['totals']['subtotal'],
                    'gst' => $result['totals']['gst'],
                    'total' => $result['totals']['total'],
                    'alerts' => $result['alerts'],
                    'stage' => 0,
                    'stage_changed_at' => now(),
                    'proof_acknowledged_at' => now(),
                    'ip_hash' => $ip ? hash('sha256', $ip) : null,
                ]);

                foreach ($result['lines'] as $l) {
                    $order->lines()->create([
                        'product_id' => $l['product_id'], 'product_code' => $l['product_code'], 'product_name' => $l['product_name'],
                        'colour' => $l['colour'], 'size' => $l['size'], 'supplier_sku' => $l['supplier_sku'], 'qty' => $l['qty'],
                        'unit_ex_gst' => $l['unit'], 'total_ex_gst' => $l['total'], 'stock_at_order' => $l['stock'],
                    ]);
                }
                foreach ($result['positions'] as $i => $p) {
                    $file = $logos[$i];
                    $ext = mb_strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
                    $path = "orders/{$order->number}/".($i + 1).'-'.Str::random(24).'.'.preg_replace('/[^a-z0-9]/', '', $ext);
                    Storage::disk(config('website.orders.artwork_disk'))->putFileAs(dirname($path), $file, basename($path));
                    $stored[] = $path;
                    $order->positions()->create([
                        'position' => $p['name'], 'method' => $p['method'], 'decoration_option_id' => $p['decoration_option_id'],
                        'decoration_name' => $p['decoration_name'], 'colours' => $p['colours'], 'width_mm' => $p['width_mm'],
                        'unit_ex_gst' => $p['unit'], 'setup_ex_gst' => $p['setup'],
                        'logo_path' => $path, 'logo_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'logo_mime' => mb_substr((string) $file->getMimeType(), 0, 100),
                    ]);
                }

                return $order;
            });
        } catch (Throwable $e) {
            Storage::disk(config('website.orders.artwork_disk'))->delete($stored);
            throw $e;
        }

        AuditLog::record('website_order.created', $order, ['total' => $order->total, 'units' => $result['units']]);
        try {
            $this->invoices->store($order);
        } catch (Throwable $e) {
            report($e); // the order stands; the PDF is made again when it is first needed
        }
        $this->emails->prepareInvoice($order);

        return ['order' => $order->fresh(['lines', 'positions']), 'result' => $result];
    }

    /** CM-xxxxx from the counters table (the same pattern as quote numbers). */
    public function nextNumber(): string
    {
        return DB::transaction(function () {
            $row = DB::table('counters')->where('name', 'website_order')->lockForUpdate()->first();
            $value = $row ? $row->value + 1 : (int) config('website.orders.number_start');
            DB::table('counters')->updateOrInsert(['name' => 'website_order'], ['value' => $value]);

            return config('website.orders.number_prefix').str_pad((string) $value, 5, '0', STR_PAD_LEFT);
        });
    }
}
