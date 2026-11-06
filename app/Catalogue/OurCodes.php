<?php

namespace App\Catalogue;

use App\Models\Product;

/**
 * Our product code and SKU codes, the only codes clients see in the SaaS's
 * client-facing outputs (Scott, 1 Nov 2026; docs/supplier-codes.md).
 *
 *  - Our code: "SupplierID-Code", e.g. 3-104743, as already used at the end of
 *    every product title ("[3-104743]").
 *  - Our SKU: our code + colour + size, e.g. 7-5001-BLAC-M. The supplier's own
 *    SKU is kept for staff and for ordering from the supplier.
 */
final class OurCodes
{
    public static function code(Product|int|null $productOrSupplierId, ?string $supplierCode = null): ?string
    {
        if ($productOrSupplierId instanceof Product) {
            $supplierCode = $productOrSupplierId->code;
            $productOrSupplierId = $productOrSupplierId->supplier_id;
        }
        $supplierCode = trim((string) $supplierCode);
        if (! $productOrSupplierId || $supplierCode === '') {
            return null;
        }

        return $productOrSupplierId.'-'.$supplierCode;
    }

    /** "7-5001" + "Black" + "M" => "7-5001-BLAC-M"; "Light Grey Marle" => "LIGR"; no size => no size part. */
    public static function sku(?string $ourCode, ?string $colour, ?string $size = null): ?string
    {
        if (blank($ourCode)) {
            return null;
        }

        // "One size" (a product with no sizes) adds no size part, as the website shows it.
        $size = preg_match('/^\s*one\s*size\s*$/i', (string) $size) ? '' : (string) $size;

        return implode('-', array_filter([$ourCode, self::colourPart((string) $colour), self::part($size)], fn ($p) => $p !== ''));
    }

    public static function colourPart(string $colour): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', mb_strtoupper(trim($colour))) ?: []));
        if ($words === []) {
            return '';
        }
        if (count($words) === 1) {
            return mb_substr($words[0], 0, 4);
        }

        return mb_substr($words[0], 0, 2).mb_substr($words[1], 0, 2);
    }

    private static function part(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(trim($value))), 0, 12);
    }
}
