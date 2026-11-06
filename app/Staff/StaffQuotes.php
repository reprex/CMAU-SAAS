<?php

namespace App\Staff;

use App\Checkout\CheckoutPricer;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use App\Quotes\ItemPricer;
use App\Quotes\QuoteCalculator;
use App\Quotes\QuoteNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A quote for a client made by staff from the website's cart page (docs/website-staff.md).
 * The cart (product, sizes and quantities, branding positions) is priced exactly as the
 * checkout prices it, then written into the quote desk: one line for each style with its
 * first branding position, one more line for each further position, sizes as SKU rows.
 */
class StaffQuotes
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly QuoteNumbers $numbers,
        private readonly ItemPricer $items,
        private readonly QuoteCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $cart  product_id, lines[], positions[]
     * @param  array<string, mixed>|null  $client  name, organisation, email, phone, address
     * @param  array<int, UploadedFile>  $logos  by position index
     * @return array{quote: Quote, added: int, issues: list<array>}
     */
    public function build(?User $user, array $cart, ?array $client, ?Quote $into, array $logos, ?string $note, string $source = 'manual'): array
    {
        // The website's quote asks whether to deliver, and where (Scott, 7 Nov 2026): priced by the carrier.
        $delivery = isset($cart['delivery']) && is_array($cart['delivery']) ? $cart['delivery'] : null;
        $wanted = $delivery !== null && (bool) ($delivery['wanted'] ?? false);
        $country = $wanted ? mb_strtoupper((string) ($delivery['country'] ?? 'AU')) : 'AU';
        $in = [
            'product_id' => (int) $cart['product_id'],
            'lines' => $cart['lines'],
            'positions' => array_map(fn ($p) => $p + ['has_logo' => true], $cart['positions']),
            'options' => array_map(fn ($o) => ['id' => (string) $o['id'], 'n' => (int) ($o['n'] ?? 1)], $cart['options'] ?? []),
            'delivery' => $wanted ? ['country' => $country, 'postcode' => $delivery['postcode'] ?? null] : ['country' => 'AU', 'postcode' => $client['address']['postcode'] ?? null],
            'need_by' => $cart['need_by'] ?? null,
        ];
        $result = $this->pricer->evaluate($in);
        if (! ($result['found'] ?? false)) {
            throw new RuntimeException('That product cannot be quoted from the website.');
        }
        $blocking = array_values(array_filter($result['issues'], fn ($i) => in_array($i['code'], ['price', 'decoration', 'unrelated', 'qty'], true)));
        if ($blocking) {
            throw new RuntimeException(implode(' ', array_column($blocking, 'message')));
        }
        $quiet = $delivery !== null ? ['logo', 'review', 'need_by', 'deadline', 'late'] : ['logo', 'review'];
        if ($delivery !== null && ! $wanted) {
            $quiet[] = 'freight';
        }
        $other = array_values(array_filter($result['issues'], fn ($i) => ! in_array($i['code'], $quiet, true)));
        $breaks = $source === 'website' ? $this->pricer->priceBreaks($in, (int) $result['units']) : [];

        return DB::transaction(function () use ($user, $cart, $client, $into, $logos, $note, $result, $other, $source, $delivery, $wanted, $country, $breaks) {
            $quote = $into ?? Quote::query()->create([
                'number' => $this->numbers->next(),
                'status' => $source === 'website' ? 'QUOTE' : 'PHONE',
                'source' => $source,
                'title' => $result['product']['name'],
                'client_name' => $client['name'] ?? null,
                'client_company' => $client['organisation'] ?? null,
                'client_email' => isset($client['email']) ? mb_strtolower($client['email']) : null,
                'client_phone' => $client['phone'] ?? null,
                'delivery_name' => $client['name'] ?? null,
                'delivery_address1' => $client['address']['street'] ?? null,
                'delivery_address2' => $client['address']['unit'] ?? null,
                'delivery_city' => $client['address']['suburb'] ?? null,
                'delivery_state' => $client['address']['state'] ?? null,
                'delivery_postcode' => $client['address']['postcode'] ?? null,
                'delivery_country' => $client['address']['country'] ?? 'AU',
                'created_by' => $user?->id,
                'assigned_to' => $user?->id,
                'valid_until' => now()->addDays((int) config('quotes.valid_days'))->toDateString(),
                'lead_time' => config('quotes.default_lead_time'),
                'edited_at' => now(),
            ]);

            $products = Product::query()->with('supplier')->whereIn('id', array_column($result['styles'], 'product_id'))->get()->keyBy('id');
            $positions = $result['positions'];
            $first = $positions[0] ?? null;
            $added = 0;
            foreach ($result['styles'] as $si => $style) {
                $p = $products[$style['product_id']];
                $lines = array_values(array_filter($result['lines'], fn ($l) => $l['product_id'] === $style['product_id']));
                $colours = array_values(array_unique(array_column($lines, 'colour')));
                $item = new QuoteItem([
                    'quote_id' => $quote->id,
                    'position' => $quote->nextPosition(),
                    'stage' => 'sku',
                    'product_id' => $p->id,
                    'supplier_id' => $p->supplier_id,
                    'supplier_name' => $p->supplier?->name,
                    'product_code' => $p->code,
                    'name' => $p->name,
                    'colour' => count($colours) === 1 ? $colours[0] : 'Mixed: '.implode(', ', $colours),
                    'image_url' => $p->hero_image_url,
                    'quantity' => $style['units'],
                    'product_unit_price' => $style['unbranded_unit'],
                    'product_setup_price' => $si === 0 ? (float) $result['setup']['product'] : 0,
                    'decoration_option_id' => $first['decoration_option_id'] ?? null,
                    'decoration_name' => $first ? $first['name'].': '.$first['decoration_name'] : null,
                    'decoration_unit_price' => $first['unit'] ?? 0,
                    'decoration_setup_price' => $si === 0 ? ($first['setup'] ?? 0) : 0,
                    'sku_mode' => 'list',
                    'notes' => $note ?: null,
                ]);
                $this->items->attachDefaults($item);
                $this->items->fillCosts($item);
                $item->save();
                foreach ($lines as $i => $l) {
                    $item->skus()->create(['position' => $i, 'colour' => $l['colour'], 'size' => $l['size'], 'quantity' => $l['qty'], 'supplier_sku' => $l['supplier_sku'] ?? null, 'stock_on_hand' => $l['stock'] ?? null, 'source' => 'list']);
                }
                $added++;
            }
            // Further branding positions: a branding-only line each, for the whole run.
            foreach (array_slice($positions, 1) as $pos) {
                $item = new QuoteItem([
                    'quote_id' => $quote->id,
                    'position' => $quote->nextPosition(),
                    'product_id' => $result['product']['id'],
                    'supplier_id' => $products[$result['product']['id']]->supplier_id ?? null,
                    'supplier_name' => $products[$result['product']['id']]->supplier?->name ?? null,
                    'product_code' => $products[$result['product']['id']]->code ?? null,
                    'name' => $result['product']['name'].': '.$pos['name'].' branding',
                    'quantity' => $result['units'],
                    'product_unit_price' => 0,
                    'decoration_option_id' => $pos['decoration_option_id'],
                    'decoration_name' => $pos['name'].': '.$pos['decoration_name'],
                    'decoration_unit_price' => $pos['unit'],
                    'decoration_setup_price' => $pos['setup'],
                ]);
                $item->decoration_product_id = $products[$result['product']['id']] ? (ProductWebPrice::find($result['product']['id'])?->decoration_product_id) : null;
                $this->items->fillCosts($item);
                $item->save();
                $added++;
            }

            // Product options with a charge (clips, packaging, samples…): a line each, for the whole run.
            foreach ($result['options'] ?? [] as $opt) {
                if ($opt['unit'] <= 0 && $opt['once'] <= 0) {
                    continue;
                }
                $item = new QuoteItem([
                    'quote_id' => $quote->id,
                    'position' => $quote->nextPosition(),
                    'product_id' => $result['product']['id'],
                    'supplier_id' => $products[$result['product']['id']]->supplier_id ?? null,
                    'supplier_name' => $products[$result['product']['id']]->supplier?->name ?? null,
                    'product_code' => $products[$result['product']['id']]->code ?? null,
                    'name' => $result['product']['name'].': '.$opt['name'].($opt['n'] > 1 ? ' × '.$opt['n'] : ''),
                    'product_option_id' => $opt['id'],
                    'quantity' => $result['units'],
                    'product_unit_price' => $opt['unit'],
                    'product_setup_price' => $opt['once'],
                ]);
                $this->items->fillCosts($item);
                $item->save();
                $added++;
            }

            // Logo files: kept privately with the quote.
            $art = (array) ($quote->artwork ?? []);
            foreach ($logos as $i => $file) {
                $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
                $path = 'quote-artwork/'.$quote->number.'/'.Str::slug($cart['positions'][$i]['name'] ?? 'logo').'-'.Str::random(8).'.'.$ext;
                Storage::disk(config('website.orders.artwork_disk'))->putFileAs(dirname($path), $file, basename($path));
                $art[] = ['position' => $cart['positions'][$i]['name'] ?? 'Logo', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 160), 'size' => (int) $file->getSize()];
            }
            $notes = trim((string) $quote->internal_notes);
            $line = 'From the website'.($user ? ' by '.$user->name : ' (the shopper\'s quote and mockup PDF)').' '.now()->timezone(config('app.timezone'))->format('j M g:ia').': '.$result['product']['name'].', '.$result['units'].' units'
                .($positions ? ', '.implode('; ', array_map(fn ($p) => $p['name'].' '.$p['method_name'], $positions)) : '')
                .($other ? '. Check: '.implode(' ', array_column($other, 'message')) : '');
            $quote->forceFill(['artwork' => $art ?: null, 'internal_notes' => trim($notes."\n".$line), 'edited_at' => $quote->edited_at ?? now()])->save();
            if ($breaks !== []) {
                $quote->forceFill(['price_breaks' => $breaks])->save();
            }
            if ($delivery !== null) {
                $this->delivery($quote, $result, $wanted, $country, $delivery['postcode'] ?? null, $cart['need_by'] ?? null);
            }
            $this->calculator->recalculate($quote);
            AuditLog::record($into ? 'quote.website_items_added' : 'quote.created_from_website', $quote, ['product' => $result['product']['id'], 'units' => $result['units']]);

            return ['quote' => $quote->fresh(), 'added' => $added, 'issues' => $other];
        });
    }

    /**
     * Delivery on a website quote (Scott, 7 Nov 2026): the carrier's price (MachShip in Australia, FedEx
     * overseas) for the cheapest service that arrives by the date needed (else the fastest), or "to be
     * confirmed" when it can't be priced. Overseas is a GST-free export; local duties and taxes are the
     * client's, paid to the carrier. No delivery wanted: no freight lines at all.
     *
     * @param  array<string, mixed>  $result  CheckoutPricer::evaluate()
     */
    private function delivery(Quote $quote, array $result, bool $wanted, string $country, ?string $postcode, ?string $needBy): void
    {
        $quote->extras()->where('kind', 'freight')->where('auto', false)->where('auto_key', 'website')->delete();
        $quote->forceFill([
            'freight_source' => $wanted ? 'carrier' : 'none',
            'gst_free_export' => $wanted && $country !== 'AU',
            'delivery_country' => $wanted ? $country : $quote->delivery_country,
            'delivery_postcode' => $wanted ? ($postcode ?: null) : $quote->delivery_postcode,
        ])->save();
        if (! $wanted) {
            return;
        }
        $freight = $result['freight'] ?? null;
        $options = collect($freight['ok'] ?? false ? $freight['options'] : []);
        $chosen = null;
        if ($options->isNotEmpty()) {
            $byPrice = $options->sortBy('price');
            $chosen = $needBy ? $byPrice->first(fn ($o) => ! empty($o['delivery_date']) && $o['delivery_date'] <= $needBy) : null;
            $chosen ??= $needBy ? $options->sortBy('delivery_date')->first() : $byPrice->first();
        }
        $where = $country === 'AU' ? trim(($postcode ?? '').' '.($freight['zone'] ?? '')) : trim(($freight['zone'] ?? $country).' '.($postcode ?? ''));
        $eta = $chosen && ! empty($chosen['delivery_date']) ? ', ETA '.CarbonImmutable::parse($chosen['delivery_date'])->format('D j M') : '';
        // Weights not all from Promodata, or no live carrier rate: say it's an estimate (Scott, 7 Nov 2026).
        $estimate = ($freight['estimated'] ?? false) || ($freight['source'] ?? 'estimate') === 'estimate' ? ' (estimate)' : '';
        $quote->extras()->create([
            'kind' => 'freight', 'auto' => false, 'auto_key' => 'website', 'quantity' => 1, 'position' => 1000,
            'description' => $chosen ? "Delivery to {$where}: {$chosen['name']}{$eta}{$estimate}" : "Delivery to {$where} (to be confirmed)",
            'unit_price' => $chosen ? (float) $chosen['price'] : 0,
            'unit_cost' => $chosen ? ($chosen['cost'] ?? null) : null,
            'tbc' => $chosen === null,
        ]);
        $this->calculator->recalculate($quote);
    }
}
