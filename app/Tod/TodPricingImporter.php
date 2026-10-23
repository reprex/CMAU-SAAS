<?php

namespace App\Tod;

use App\Models\Product;
use App\Pricing\DecorationCode;
use App\Pricing\Money;
use App\Pricing\SupplierRules;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads TOD's pricing data (exported with the ExportPricingTables VBA) into
 * the new schema. Used for two things:
 *
 *  1. Migration: suppliers and their markups, DecTypeCode classifications,
 *     staff price settings (LocalPrice) and product price overrides.
 *  2. Verification: for the live range it also loads TOD's own cost breaks
 *     (dbo_PDS_CostPriceBreak) as price lines, standing in for the Promodata
 *     detail, so the pricing engine can be run and compared with TOD.
 *     These lines are tagged source_key "tod-cpb" and are replaced the first
 *     time a product is hydrated from Promodata.
 *
 * Expected files in $folder: dbo_PDS_Supplier.tsv, DecTypeCode.tsv,
 * dbo_PDS_Products.tsv, dbo_PDS_CostPriceBreak.tsv,
 * dbo_PDS_CostPriceSummary.tsv, dbo_PDS_LocalPrice.tsv.
 */
class TodPricingImporter
{
    /** @var callable(string): void */
    private $log;

    /**
     * @param  bool  $carryDefaults  keep each live product's TOD default decoration
     *                               (DefaultDecCPSID) so collection prices do not change
     *                               at cutover; false shows what the engine's own rule gives
     */
    public function __construct(private readonly string $folder, ?callable $log = null, private readonly bool $carryDefaults = true)
    {
        $this->log = $log ?? fn (string $m) => null;
    }

    /** @return array<string, int> counts */
    public function import(): array
    {
        $counts = [];
        $counts['suppliers'] = $this->importSuppliers();
        $counts['dec_type_codes'] = $this->importDecTypeCodes();

        $products = $this->liveProducts();
        $counts['products'] = $this->importProducts($products);
        $ids = array_keys($products);

        $this->clearPricing($ids);
        $counts['price_lines'] = $this->importPriceLines($ids);
        $counts['external_options'] = $this->importExternalOptions($ids);
        $counts['price_option_settings'] = $this->importSettings($ids);
        $counts['product_overrides'] = $this->importOverrides($products, $this->moqOneProducts($products));

        return $counts;
    }

    private function path(string $file): string
    {
        $path = rtrim($this->folder, '/').'/'.$file;
        if (! is_file($path)) {
            throw new RuntimeException("Missing TOD export: {$path}");
        }

        return $path;
    }

    private function importSuppliers(): int
    {
        $n = 0;
        foreach (TsvReader::rows($this->path('dbo_PDS_Supplier.tsv')) as $r) {
            DB::table('suppliers')->updateOrInsert(['id' => (int) $r['APPASupplierID']], [
                'name' => $r['SupplierName'] ?: 'Supplier '.$r['APPASupplierID'],
                'country' => $r['SupplierCountry'] ?: null,
                'appa_member_number' => $r['APPASupplierCode'] ?: null,
                'web_flag' => TsvReader::bool($r['SupplierWebFlag']),
                'rank' => TsvReader::intOrNull($r['SupplierRank']),
                // TOD defaults null markups to 1.5 / 1.45 / 1.45 (query "set null markups").
                'markup_undecorated' => TsvReader::floatOrNull($r['SupplierMarkupUnDec']) ?? 1.45,
                'markup_decoration' => TsvReader::floatOrNull($r['SupplierMarkupDec']) ?? 1.50,
                'markup_accessory' => TsvReader::floatOrNull($r['SupplierMarkupAcc']) ?? 1.45,
                'markup_setup' => TsvReader::floatOrNull($r['SupplierMarkupSetup']) ?? 1.00,
                'quote_markup_to_300' => TsvReader::floatOrNull($r['SupplierMarkupTo300']),
                'quote_markup_to_1000' => TsvReader::floatOrNull($r['SupplierMarkTo1000']),
                'quote_markup_over_1000' => TsvReader::floatOrNull($r['SupplierMarkOver1001']),
                'markup_sample' => TsvReader::floatOrNull($r['SupplierMarkupSample']),
                'sample_freight' => TsvReader::floatOrNull($r['SupplierSampleFreight']),
                'sample_available' => TsvReader::bool($r['SupplierSampleAvailFlag']),
                'stock_columns' => $r['SupplierStockColumnCSV'] ?: null,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            $n++;
        }

        return $n;
    }

    private function importDecTypeCodes(): int
    {
        $rows = [];
        foreach (TsvReader::rows($this->path('DecTypeCode.tsv')) as $r) {
            if ($r['DecCodeID'] === '') {
                continue;
            }
            $rows[] = [
                'code' => $r['DecCodeID'],
                'type_code' => TsvReader::intOrNull($r['DecTypeCode']),
                'overwrite_description' => $r['OverwriteDesc'] ?: null,
                'full_description' => mb_substr($r['FullDesc'], 0, 1024) ?: null,
                'promodata_decoration' => $r['PromoDecoration'] ?: null,
                'first_supplier_id' => TsvReader::intOrNull($r['FirstSupplierID']),
                'first_supplier_name' => $r['FirstSupplierName'] ?: null,
                'spot_colour' => TsvReader::bool($r['SpotColourPrintFlag']),
                'full_colour' => TsvReader::bool($r['FullColourPrintFlag']),
                'embroider' => TsvReader::bool($r['EmbroiderFlag']),
                'engrave' => TsvReader::bool($r['EngraveFlag']),
                'emboss_deboss' => TsvReader::bool($r['EmbossDebossFlag']),
                'foil_stamp' => TsvReader::bool($r['FoilStampFlag']),
                'sublimation' => TsvReader::bool($r['SublimationFlag']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('dec_type_codes')->upsert($chunk, ['code']);
        }

        return count($rows);
    }

    /**
     * Live products (TOD WebProdAddFlag and not WebProdRemoveFlag), plus any
     * product they borrow decorations from.
     *
     * @return array<int, array<string, string>>
     */
    private function liveProducts(): array
    {
        $all = [];
        $live = [];
        foreach (TsvReader::rows($this->path('dbo_PDS_Products.tsv')) as $r) {
            $id = (int) $r['APPAProductID'];
            $all[$id] = $r;
            if (TsvReader::bool($r['WebProdAddFlag']) && ! TsvReader::bool($r['WebProdRemoveFlag'])) {
                $live[$id] = true;
            }
        }

        $wanted = $live;
        foreach (array_keys($live) as $id) {
            foreach (['WebDecLiveAPPAID', 'WebOverrideDecAPPAID'] as $field) {
                $other = (int) $all[$id][$field];
                if ($other > 0 && $other !== $id && isset($all[$other])) {
                    $wanted[$other] = true;
                }
            }
        }

        $result = [];
        foreach (array_keys($wanted) as $id) {
            $result[$id] = $all[$id] + ['_live' => isset($live[$id]) ? '1' : '0'];
        }
        ksort($result);

        return $result;
    }

    /** @param  array<int, array<string, string>>  $products */
    private function importProducts(array $products): int
    {
        $suppliers = DB::table('suppliers')->pluck('id')->flip();
        foreach ($products as $id => $r) {
            $supplierId = (int) $r['SupplierID'];
            if (! $suppliers->has($supplierId)) {
                DB::table('suppliers')->insert(['id' => $supplierId, 'name' => $r['SupplierAPPAName'] ?: "Supplier {$supplierId}", 'created_at' => now(), 'updated_at' => now()]);
                $suppliers[$supplierId] = true;
            }
            DB::table('products')->updateOrInsert(['id' => $id], [
                'source' => $id > 999999 ? 'external' : 'promodata',
                'supplier_id' => $supplierId,
                'code' => $r['SupplierProductID'] ?: null,
                'name' => mb_substr($r['WebShortDesc'] ?: ($r['ShortDescription'] ?: (string) $r['SupplierProductID']), 0, 255) ?: (string) $id,
                'tier' => $r['_live'] === '1' ? Product::TIER_LIVE : Product::TIER_ACTIVE,
                'discontinued' => TsvReader::bool($r['SuppplierDiscontinuedFlag']),
                'hydrated_at' => now(),
                'last_used_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        return count($products);
    }

    /** @param  list<int>  $ids */
    private function clearPricing(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('product_web_prices')->whereIn('product_id', $chunk)->delete();
            DB::table('price_options')->whereIn('product_id', $chunk)->delete();
            DB::table('price_option_settings')->whereIn('product_id', $chunk)->delete();
            $lineIds = DB::table('price_lines')->whereIn('product_id', $chunk)->pluck('id');
            foreach ($lineIds->chunk(500) as $lines) {
                DB::table('price_breaks')->whereIn('price_line_id', $lines->all())->delete();
            }
            DB::table('price_lines')->whereIn('product_id', $chunk)->update(['base_price_line_id' => null]);
            DB::table('price_lines')->whereIn('product_id', $chunk)->delete();
        }
    }

    /**
     * One price line per TOD option (PDSCPB_TextPDID) with its quantity breaks.
     *
     * @param  list<int>  $ids
     */
    private function importPriceLines(array $ids): int
    {
        $wanted = array_flip($ids);
        $lines = [];
        foreach (TsvReader::rows($this->path('dbo_PDS_CostPriceBreak.tsv')) as $r) {
            $productId = (int) $r['PDSCPB_APPAProdID'];
            if (! isset($wanted[$productId]) || TsvReader::bool($r['PDSCPB_DeleteFlag'])) {
                continue;
            }
            $key = $r['PDSCPB_TextPDID'];
            $lines[$productId][$key] ??= [
                'description' => $r['PDSCPB_DecString'],
                'setup_cost' => (float) $r['PDSCPB_SetupCost'],
                'undecorated' => $r['PDSCPB_Type'] === 'UNDEC',
                'type' => $r['PDSCPB_Type'],
                'promodata_decoration' => $r['PDSCPB_PromodataDecoration'] ?: null,
                'breaks' => [],
            ];
            $lines[$productId][$key]['breaks'][(int) $r['PDSCPB_Qty']] = (float) $r['PDSCPB_UnitCost'];
        }

        $supplierOf = DB::table('products')->whereIn('id', $ids)->pluck('supplier_id', 'id');

        $n = 0;
        foreach ($lines as $productId => $productLines) {
            // TOD stores supplier adjustments (JB's Wear undecorated cost + $2) in its
            // cost breaks; price lines hold the supplier's own cost, so take them off.
            $costAdd = SupplierRules::for((int) $supplierOf[$productId])->undecoratedCostAdd();
            // Undecorated lines first, so decoration lines can point at a base line.
            uasort($productLines, fn ($a, $b) => $b['undecorated'] <=> $a['undecorated']);
            $baseId = null;
            $position = 0;
            foreach ($productLines as $key => $line) {
                $lineId = DB::table('price_lines')->insertGetId([
                    'product_id' => $productId,
                    'base_price_line_id' => $line['undecorated'] ? null : $baseId,
                    'position' => $position++,
                    'source_key' => 'tod-cpb:'.$key,
                    'type' => $line['type'],
                    'description' => mb_substr($line['description'], 0, 1024),
                    'setup_cost' => $line['setup_cost'],
                    'undecorated' => $line['undecorated'],
                    'promodata_decoration' => $line['promodata_decoration'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($line['undecorated'] && $baseId === null) {
                    $baseId = $lineId;
                }
                ksort($line['breaks']);
                $breaks = [];
                foreach ($line['breaks'] as $qty => $cost) {
                    $cost = $line['undecorated'] ? Money::currency($cost - $costAdd) : $cost;
                    $breaks[] = ['price_line_id' => $lineId, 'qty' => $qty, 'unit_cost' => $cost];
                }
                DB::table('price_breaks')->insert($breaks);
                $n++;
            }
        }

        return $n;
    }

    /**
     * EXT options come from supplier price files outside Promodata (e.g. AS
     * Colour). The engine never recalculates them, so they are loaded as-is.
     *
     * @param  list<int>  $ids
     */
    private function importExternalOptions(array $ids): int
    {
        $wanted = array_flip($ids);
        $n = 0;
        foreach (TsvReader::rows($this->path('dbo_PDS_CostPriceSummary.tsv')) as $r) {
            $productId = (int) $r['PDSCP_APPAID'];
            if (! isset($wanted[$productId]) || $r['PDSCP_Type'] !== 'EXT') {
                continue;
            }
            $code = DecorationCode::fromDescription(substr($r['PDSCP_TextPDID'], strlen((string) $productId) + 1));
            DB::table('price_options')->insert([
                'product_id' => $productId,
                'option_id' => $r['PDSCP_TextPDID'],
                'code' => $code,
                'kind' => 'EXT',
                'type_code' => TsvReader::intOrNull($r['PDSCP_TypeCode']),
                'description' => $r['PDSCP_Desc'],
                'display_description' => $r['PDSCP_OverwriteDec'] ?: $r['PDSCP_Desc'],
                'setup_cost' => (float) $r['PDSCP_SetupCost'],
                'setup_price' => (float) $r['PDSCP_SetupPrice'],
                'cost_string' => $r['PDSCP_QtyCostString'],
                'price_string' => $r['PDSCP_QtyPriceString'],
                'placeholder' => false,
                'position' => 1000 + $n,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $n++;
        }

        return $n;
    }

    /** @param  list<int>  $ids */
    private function importSettings(array $ids): int
    {
        $wanted = array_flip($ids);
        $rows = [];
        foreach (TsvReader::rows($this->path('dbo_PDS_LocalPrice.tsv')) as $r) {
            $productId = (int) $r['PDSLP_APPAID'];
            if (! isset($wanted[$productId]) || $r['PDSLP_CPSID'] === '') {
                continue;
            }
            $rows[$r['PDSLP_CPSID']] = [
                'product_id' => $productId,
                'option_id' => $r['PDSLP_CPSID'],
                'type_code_override' => TsvReader::intOrNull($r['PDSLP_DecType']),
                'is_default' => TsvReader::bool($r['PDSLP_DefaultDec']),
                'is_other' => TsvReader::bool($r['PDSLP_OtherDec']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        // DecorationJSON display groups (TOD DecJSONSort) other than the normal 2.
        foreach (TsvReader::rows($this->path('dbo_PDS_CostPriceSummary.tsv')) as $r) {
            $productId = (int) $r['PDSCP_APPAID'];
            if (! isset($wanted[$productId]) || in_array($r['PDSCP_DecJSONSort'], ['', '2'], true)) {
                continue;
            }
            $rows[$r['PDSCP_TextPDID']] ??= [
                'product_id' => $productId,
                'option_id' => $r['PDSCP_TextPDID'],
                'type_code_override' => null,
                'is_default' => false,
                'is_other' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $rows[$r['PDSCP_TextPDID']]['json_sort'] = (int) $r['PDSCP_DecJSONSort'];
        }
        $rows = array_map(fn ($row) => $row + ['json_sort' => null], $rows);

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('price_option_settings')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Trends products on Trends' "MOQ 1" list: TOD rewrote their first price
     * break to quantity 1, so the stored strings start "1:" where the cost
     * breaks start higher.
     *
     * @param  array<int, array<string, string>>  $products
     * @return array<int, true>
     */
    private function moqOneProducts(array $products): array
    {
        // First break quantity of each TOD option (source_key "tod-cpb:<option id>").
        $firstBreak = DB::table('price_lines')
            ->join('price_breaks', 'price_breaks.price_line_id', '=', 'price_lines.id')
            ->whereIn('price_lines.product_id', array_keys($products))
            ->groupBy('price_lines.source_key')
            ->selectRaw('price_lines.source_key, min(price_breaks.qty) as first_qty')
            ->pluck('first_qty', 'source_key');

        $result = [];
        foreach (TsvReader::rows($this->path('dbo_PDS_CostPriceSummary.tsv')) as $r) {
            $id = (int) $r['PDSCP_APPAID'];
            if (isset($products[$id]) && $r['PDSCP_Type'] !== 'EXT'
                && str_starts_with($r['PDSCP_QtyCostString'], '1:')
                && $r['PDSCP_QtyCostString'] !== '1:0' // placeholders always start "1:"
                && (int) ($firstBreak['tod-cpb:'.$r['PDSCP_TextPDID']] ?? 1) > 1) {
                $result[$id] = true;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, string>>  $products
     * @param  array<int, true>  $moqOne
     */
    private function importOverrides(array $products, array $moqOne): int
    {
        $n = 0;
        foreach ($products as $id => $r) {
            $ruleMoq = SupplierRules::for((int) $r['SupplierID'])->collectionMoq(TsvReader::intOrNull($r['ActualMOQ']));
            $data = array_filter([
                'moq_one' => isset($moqOne[$id]) ? true : null,
                // TOD's pick depends on Access row order, so it is carried over as data
                // rather than recalculated (decision 24 Oct 2026).
                'carried_default_decoration_option_id' => ($this->carryDefaults && $r['DefaultDecCPSID'] !== '') ? $r['DefaultDecCPSID'] : null,
                'decoration_option_id' => $r['WebDecPDIDManual'] ?: null,
                // TOD keeps the borrowed decoration product in WebDecLiveAPPAID (set by
                // supplier rules such as "MM 10002000 Dec for ..." or by staff);
                // WebOverrideDecAPPAID is unused in the live range.
                'decoration_product_id' => ((int) $r['WebDecLiveAPPAID'] > 0 && (int) $r['WebDecLiveAPPAID'] !== $id) ? (int) $r['WebDecLiveAPPAID'] : null,
                'actual_moq' => (int) $r['OverrideActualMOQ'] > 0 ? (int) $r['OverrideActualMOQ'] : null,
                // Only a value the supplier rules (or ActualMOQ) would not produce is a
                // real override, e.g. Trends' display quantity from its product file.
                'collection_moq' => ((int) $r['CollectionMOQ'] > 0 && (int) $r['CollectionMOQ'] !== (int) $ruleMoq) ? (int) $r['CollectionMOQ'] : null,
            ], fn ($v) => $v !== null);

            DB::table('product_overrides')->where('product_id', $id)->delete();
            if ($data !== []) {
                DB::table('product_overrides')->insert([
                    'product_id' => $id,
                    'data' => json_encode($data),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $n++;
            }
        }

        return $n;
    }
}
