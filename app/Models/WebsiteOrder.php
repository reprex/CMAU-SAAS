<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A direct website order (CM-xxxxx), docs/website-checkout.md. */
class WebsiteOrder extends Model
{
    public const STAGES = ['Order received', 'Invoice sent', 'Paid', 'Proof approved', 'In production', 'Dispatched', 'Delivered'];

    public const STAGE_INVOICE_SENT = 1;

    public const STAGE_DISPATCHED = 5;

    public const STAGE_DELIVERED = 6;

    public const CARRIERS = ['TNT', 'FedEx'];

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

    /** "AS Colour Mens Staple Tee 5001 × 100, ... · 2 branding positions" */
    public function itemsSummary(): string
    {
        $byProduct = $this->lines->groupBy('product_id')->map(fn ($lines) => trim($lines->first()->product_name.' '.$lines->first()->product_code).' × '.$lines->sum('qty'));
        $n = $this->positions->count();

        return $byProduct->implode(', ').' · '.$n.' branding position'.($n === 1 ? '' : 's');
    }
}
