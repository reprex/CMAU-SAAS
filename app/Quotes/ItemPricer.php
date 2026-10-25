<?php

namespace App\Quotes;

use App\Models\PriceOption;
use App\Models\ProductWebPrice;
use App\Models\QuoteItem;
use App\Pricing\Money;
use Illuminate\Support\Collection;

/**
 * Prices a quote item from the catalogue's price options at its quantity,
 * the same strings the website uses (sell from price_string, cost from
 * cost_string). Items without options (custom, or not in the catalogue)
 * keep the prices staff entered.
 */
class ItemPricer
{
    /** Fill missing option ids from the product's web price defaults. */
    public function attachDefaults(QuoteItem $item): void
    {
        if ($item->product_id === null) {
            return;
        }
        $web = ProductWebPrice::find($item->product_id);
        if ($web === null) {
            return;
        }
        $item->product_option_id ??= $web->undecorated_option_id;
        $item->decoration_product_id ??= $web->decoration_product_id;
    }

    /** Fill costs (only) for an item whose sell prices came from Shopify. */
    public function fillCosts(QuoteItem $item): void
    {
        $qty = max(1, $item->quantity);
        if ($option = $this->option($item->product_option_id)) {
            $item->product_unit_cost = $option->costs()->priceAt($qty);
            $item->product_setup_cost = $option->setup_cost;
        }
        if ($option = $this->option($item->decoration_option_id)) {
            $item->decoration_unit_cost = $option->costs()->priceAt($qty);
            $item->decoration_setup_cost = $option->setup_cost;
        }
    }

    /**
     * Re-price at the item's current quantity. Returns false when there is
     * nothing in the catalogue to price from.
     */
    public function reprice(QuoteItem $item): bool
    {
        $qty = max(1, $item->quantity);
        $priced = false;

        if ($option = $this->option($item->product_option_id)) {
            $item->product_unit_price = Money::roundToCent($option->prices()->priceAt($qty));
            $item->product_setup_price = $option->setup_price;
            $item->product_unit_cost = $option->costs()->priceAt($qty);
            $item->product_setup_cost = $option->setup_cost;
            $priced = true;
        }

        if ($option = $this->option($item->decoration_option_id)) {
            $item->decoration_unit_price = Money::roundToCent($option->prices()->priceAt($qty));
            $item->decoration_setup_price = $option->setup_price;
            $item->decoration_unit_cost = $option->costs()->priceAt($qty);
            $item->decoration_setup_cost = $option->setup_cost;
            $item->decoration_name ??= $option->display_description ?: $option->description;
            $priced = true;
        }

        return $priced;
    }

    /**
     * Decoration choices for a product: its own decorated options, or the
     * external decorator's when the product uses one.
     *
     * @return Collection<int, PriceOption>
     */
    public function decorationOptions(int $productId)
    {
        $web = ProductWebPrice::find($productId);
        $decProduct = $web?->decoration_product_id ?: $productId;

        return PriceOption::query()
            ->where('product_id', $decProduct)
            ->where('kind', '!=', PriceOption::KIND_UNDECORATED)
            ->where('placeholder', false)
            ->orderBy('position')
            ->get();
    }

    private function option(?string $optionId): ?PriceOption
    {
        return $optionId ? PriceOption::query()->where('option_id', $optionId)->first() : null;
    }
}
