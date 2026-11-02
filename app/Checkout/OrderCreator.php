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
        private readonly SamplesPricer $samples,
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
                    'kind' => WebsiteOrder::KIND_ORDER,
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
                        'product_id' => $l['product_id'], 'product_code' => $l['supplier_product_code'], 'our_code' => $l['product_code'], 'product_name' => $l['product_name'],
                        'colour' => $l['colour'], 'size' => $l['size'], 'supplier_sku' => $l['supplier_sku'], 'sku' => $l['sku'], 'qty' => $l['qty'],
                        'unit_ex_gst' => $l['unit'], 'total_ex_gst' => $l['total'], 'stock_at_order' => $l['stock'],
                    ]);
                }
                $help = [];
                foreach ($result['positions'] as $i => $p) {
                    $file = $logos[$i];
                    [$preflight, $notes] = ArtworkCheck::verdict($p['method'], $file, $data['positions'][array_keys($data['positions'])[$i]] ?? []);
                    if ($preflight === 'help') {
                        $help[] = $p['name'];
                    }
                    $ext = mb_strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
                    $path = "orders/{$order->number}/".($i + 1).'-'.Str::random(24).'.'.preg_replace('/[^a-z0-9]/', '', $ext);
                    Storage::disk(config('website.orders.artwork_disk'))->putFileAs(dirname($path), $file, basename($path));
                    $stored[] = $path;
                    $order->positions()->create([
                        'position' => $p['name'], 'method' => $p['method'], 'decoration_option_id' => $p['decoration_option_id'],
                        'decoration_name' => $p['decoration_name'], 'colours' => $p['colours'], 'width_mm' => $p['width_mm'],
                        'unit_ex_gst' => $p['unit'], 'setup_ex_gst' => $p['setup'],
                        'logo_path' => $path, 'logo_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'logo_mime' => mb_substr((string) $file->getMimeType(), 0, 100),
                        'preflight' => $preflight, 'preflight_notes' => $notes,
                    ]);
                }
                if ($help !== []) {
                    $order->forceFill(['alerts' => array_merge((array) $order->alerts, [[
                        'code' => 'artwork_help',
                        'message' => 'Artwork: our team will be in touch to help make your '.implode(' and ', $help).' artwork print-ready before your proof.',
                    ]])])->save();
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

    /**
     * The samples cart (docs/website-checkout.md): recompute, refuse if any rule
     * fails, then save one order (kind "samples") with a line per sample and its
     * factory group, make the invoice and prepare its email, as for an order.
     *
     * @param  array<string, mixed>  $data  validated request (WebsiteCheckoutController::samplesOrder)
     * @return array{order: ?WebsiteOrder, result: array<string, mixed>}
     */
    public function createSamples(array $data, ?string $ip = null): array
    {
        $result = $this->samples->evaluate([
            'items' => $data['items'],
            'postcode' => $data['address']['postcode'],
            'country' => $data['address']['country'] ?? 'AU',
        ]);
        if (! $result['can_order']) {
            return ['order' => null, 'result' => $result];
        }

        $order = DB::transaction(function () use ($data, $result, $ip) {
            $suppliers = collect($result['factories'])->pluck('supplier_id')->unique();
            $first = $result['factories'][0]['items'][0];
            $order = WebsiteOrder::query()->create([
                'number' => $this->nextNumber(),
                'kind' => WebsiteOrder::KIND_SAMPLES,
                'email' => mb_strtolower(trim($data['contact']['email'])),
                'contact_name' => trim($data['contact']['name']),
                'organisation' => filled($data['contact']['organisation'] ?? null) ? trim($data['contact']['organisation']) : null,
                'phone' => trim($data['contact']['phone']),
                'address_unit' => filled($data['address']['unit'] ?? null) ? trim($data['address']['unit']) : null,
                'address_street' => trim($data['address']['street']),
                'address_suburb' => trim($data['address']['suburb']),
                'address_state' => filled($data['address']['state'] ?? null) ? trim($data['address']['state']) : null,
                'address_postcode' => trim($data['address']['postcode']),
                'address_country' => 'AU',
                'need_by' => null,
                'product_id' => $first['product_id'],
                'supplier_id' => $suppliers->count() === 1 ? $suppliers->first() : null,
                'payment_terms' => config('website.payment_terms.default'),
                'freight_service' => 'samples',
                'freight_name' => 'Sample freight',
                // Each factory's freight and supply days, with its supplier (staff screens only).
                'freight_breakdown' => ['factories' => collect($result['factories'])->map(fn ($f) => collect($f)->only(['factory', 'supplier_id', 'days', 'goods', 'freight', 'subtotal'])->all() + ['items' => count($f['items'])])->all()],
                'eta' => $result['delivery_estimate']['delivery_date'],
                'eta_breakdown' => $result['delivery_estimate'],
                'export' => false,
                'goods' => $result['totals']['goods'],
                'setup' => 0,
                'freight' => $result['totals']['freight'],
                'subtotal' => $result['totals']['subtotal'],
                'gst' => $result['totals']['gst'],
                'total' => $result['totals']['total'],
                'alerts' => [],
                'stage' => 0,
                'stage_changed_at' => now(),
                'ip_hash' => $ip ? hash('sha256', $ip) : null,
            ]);
            $codes = Product::query()->whereIn('id', collect($result['factories'])->pluck('items')->flatten(1)->pluck('product_id'))->pluck('code', 'id');
            foreach ($result['factories'] as $f) {
                foreach ($f['items'] as $it) {
                    $order->lines()->create([
                        'product_id' => $it['product_id'], 'product_code' => $codes[$it['product_id']] ?? null, 'our_code' => $it['code'],
                        'product_name' => $it['name'], 'colour' => $it['colour'], 'size' => '', 'sku' => $it['sku'],
                        'factory' => $f['factory'], 'supplier_id' => $f['supplier_id'],
                        'qty' => 1, 'unit_ex_gst' => $it['unit'], 'total_ex_gst' => $it['unit'],
                    ]);
                }
            }

            return $order;
        });

        AuditLog::record('website_order.created', $order, ['kind' => 'samples', 'total' => $order->total, 'samples' => $result['samples']]);
        try {
            $this->invoices->store($order);
        } catch (Throwable $e) {
            report($e);
        }
        $this->emails->prepareInvoice($order);

        return ['order' => $order->fresh(['lines']), 'result' => $result];
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
