<?php

namespace App\Models;

use App\Catalogue\OurCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A direct website order (CM-xxxxx), docs/website-checkout.md. */
class WebsiteOrder extends Model
{
    public const STAGES = ['Order received', 'Invoice sent', 'Paid', 'Proof approved', 'In production', 'Dispatched', 'Delivered'];

    public const STAGE_INVOICE_SENT = 1;

    public const STAGE_DISPATCHED = 5;

    public const STAGE_DELIVERED = 6;

    public const CARRIERS = ['TNT', 'FedEx'];

    public const KIND_ORDER = 'order';

    public const KIND_SAMPLES = 'samples';

    /** Samples skip the proof and production stages; indexes into STAGES (so Dispatched and Delivered mean the same). */
    public const SAMPLE_STAGES = [0, 1, 2, 5, 6];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'need_by' => 'date',
            'event_date' => 'date',
            'eta' => 'date',
            'delivered_on' => 'date',
            'export' => 'boolean',
            'freight_breakdown' => 'array',
            'eta_breakdown' => 'array',
            'alerts' => 'array',
            'goods' => 'decimal:2',
            'setup' => 'decimal:2',
            'freight' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'gst' => 'decimal:2',
            'total' => 'decimal:2',
            'stage' => 'integer',
            'stage_changed_at' => 'datetime',
            'invoice_sent_at' => 'datetime',
            'proof_acknowledged_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WebsiteOrderLine::class)->orderBy('id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(WebsiteOrderPosition::class)->orderBy('id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(WebsiteOrderChange::class)->latest('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(PendingEmail::class)->latest('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stageName(): string
    {
        return self::STAGES[$this->stage] ?? 'Unknown';
    }

    public function isSamples(): bool
    {
        return $this->kind === self::KIND_SAMPLES;
    }

    /** The stages this order goes through: index into STAGES => name. @return array<int, string> */
    public function stageOptions(): array
    {
        return $this->isSamples() ? array_intersect_key(self::STAGES, array_flip(self::SAMPLE_STAGES)) : self::STAGES;
    }

    /** Our code for a line; older lines kept only the supplier's code, so it is rebuilt from the order's supplier. */
    public function ourCodeFor(WebsiteOrderLine $line): ?string
    {
        return $line->our_code ?: OurCodes::code($line->supplier_id ?? $this->supplier_id, $line->product_code);
    }

    /** Change requests are accepted until the order is dispatched. */
    public function acceptsChanges(): bool
    {
        return $this->stage < self::STAGE_DISPATCHED;
    }

    public function trackingUrl(): ?string
    {
        $pattern = config('website.tracking.'.$this->carrier);
        if (! $pattern || blank($this->consignment)) {
            return null;
        }

        return str_replace('{n}', rawurlencode((string) $this->consignment), $pattern);
    }

    public function addressLine(): string
    {
        $unit = trim((string) $this->address_unit);
        $street = $unit === '' ? $this->address_street : (preg_match('/^\w+$/', $unit) ? $unit.'/' : $unit.', ').$this->address_street;

        return trim($street.', '.$this->address_suburb.' '.trim($this->address_state.' '.$this->address_postcode)
            .($this->address_country !== 'AU' ? ', '.(config('freight.intl_countries.'.$this->address_country.'.name') ?? $this->address_country) : ''));
    }

    /**
     * For My Orders, with our codes only: "AS Colour Mens Staple Tee 7-5001 × 100, ... · 2 branding positions",
     * or for samples "Knitted beanie 3-4243 · Black, Aluminium sports bottle 3-110072 · Blue".
     */
    public function itemsSummary(): string
    {
        if ($this->isSamples()) {
            return $this->lines->map(fn ($l) => trim($l->product_name.' '.$this->ourCodeFor($l)).($l->colour !== '' ? ' · '.$l->colour : ''))->implode(', ');
        }
        $byProduct = $this->lines->groupBy('product_id')->map(fn ($lines) => trim($lines->first()->product_name.' '.$this->ourCodeFor($lines->first())).' × '.$lines->sum('qty'));
        $n = $this->positions->count();

        return $byProduct->implode(', ').' · '.$n.' branding position'.($n === 1 ? '' : 's');
    }

    /**
     * Samples: lines grouped by factory, in order ("From factory 1", ...), with
     * each factory's freight and subtotal. supplier_id is for staff screens only.
     *
     * @return list<array{factory: int, label: string, supplier_id: ?int, lines: Collection, goods: float, freight: float, subtotal: float}>
     */
    public function factoryGroups(): array
    {
        $saved = collect($this->freight_breakdown['factories'] ?? [])->keyBy('factory');

        return $this->lines->groupBy(fn ($l) => (int) $l->factory)->sortKeys()->map(function ($lines, $n) use ($saved) {
            $goods = round((float) $lines->sum(fn ($l) => (float) $l->total_ex_gst), 2);
            $freight = round((float) ($saved[$n]['freight'] ?? 0), 2);

            return ['factory' => $n, 'label' => 'From factory '.$n, 'supplier_id' => $saved[$n]['supplier_id'] ?? $lines->first()->supplier_id,
                'lines' => $lines->values(), 'goods' => $goods, 'freight' => $freight, 'subtotal' => round($goods + $freight, 2)];
        })->values()->all();
    }
}
