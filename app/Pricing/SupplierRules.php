<?php

namespace App\Pricing;

use App\Models\DecTypeCode;

/**
 * Supplier-specific pricing rules (config/pricing.php "supplier_rules"),
 * reproducing TOD's "PDS - Supplier-specific updates" macro.
 */
final class SupplierRules
{
    /** @param  array<string, mixed>  $rules */
    private function __construct(private readonly array $rules) {}

    public static function for(?int $supplierId): self
    {
        return new self((array) config("pricing.supplier_rules.{$supplierId}", []));
    }

    public function undecoratedCostAdd(): float
    {
        return (float) ($this->rules['undecorated_cost_add'] ?? 0);
    }

    public function roundsUndecoratedToNearest(): bool
    {
        return ($this->rules['undecorated_price_rounding'] ?? 'up') === 'nearest';
    }

    /** Type code that sets every option's markup as well as its type (Shiny), or null. */
    public function markupType(): ?int
    {
        return isset($this->rules['force_type']) ? (int) $this->rules['force_type'] : null;
    }

    /**
     * Type code this supplier's rules give an option, or null to keep the
     * DecTypeCode classification.
     */
    public function typeFor(string $description, string $costString): ?int
    {
        if (isset($this->rules['force_type'])) {
            return (int) $this->rules['force_type'];
        }

        if (($this->rules['type_by_description'] ?? null) === 'logoline') {
            // TOD order: hide all, then Undecorated -> 12, "ADD*" -> 10, "*Variable*" -> 11
            // (the last two only for priced options). Access Like is case-insensitive.
            $text = mb_strtolower(trim($description));
            $priced = $costString !== '1:0';
            $type = DecTypeCode::HIDDEN;
            if ($text === 'undecorated') {
                $type = DecTypeCode::UNDECORATED;
            }
            if ($priced && str_starts_with($text, 'add')) {
                $type = DecTypeCode::DECORATION;
            }
            if ($priced && str_contains($text, 'variable')) {
                $type = DecTypeCode::ACCESSORY;
            }

            return $type;
        }

        return null;
    }

    /** Collection (PDP load) quantity from supplier rules, given the product's actual MOQ. */
    public function collectionMoq(?int $actualMoq): ?int
    {
        if (isset($this->rules['collection_moq'])) {
            return (int) $this->rules['collection_moq'];
        }
        if (isset($this->rules['min_collection_moq'])) {
            return max((int) $this->rules['min_collection_moq'], (int) $actualMoq);
        }

        return $actualMoq;
    }
}
