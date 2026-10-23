<?php

namespace App\Tod;

use App\Models\PriceOption;
use App\Models\ProductWebPrice;
use App\Pricing\Money;

/**
 * Compares the pricing engine's output with TOD's stored results
 * (dbo_PDS_CostPriceSummary and the web fields of dbo_PDS_Products), field by
 * field, and writes CSV reports of every difference.
 */
class TodPricingComparer
{
    /** @var array<string, int> */
    private array $summary = [];

    /** @var list<array<string, string>> */
    private array $differences = [];

    public function __construct(private readonly string $folder) {}

    /**
     * @param  list<int>  $productIds  products to compare (live range)
     * @return array{summary: array<string, int>, differences: list<array<string, string>>}
     */
    public function compare(array $productIds): array
    {
        $wanted = array_flip($productIds);
        $this->compareOptions($wanted);
        $this->compareProducts($wanted);
        ksort($this->summary);

        return ['summary' => $this->summary, 'differences' => $this->differences];
    }

    /** @param  array<int, int>  $wanted */
    private function compareOptions(array $wanted): void
    {
        $tod = [];
        foreach (TsvReader::rows($this->folder.'/dbo_PDS_CostPriceSummary.tsv') as $r) {
            $productId = (int) $r['PDSCP_APPAID'];
            if (isset($wanted[$productId]) && $r['PDSCP_Type'] !== 'EXT') {
                $tod[$r['PDSCP_TextPDID']] = $r;
            }
        }

        $ours = PriceOption::query()
            ->whereIn('product_id', array_keys($wanted))
            ->where('kind', '!=', PriceOption::KIND_EXTERNAL)
            ->get()
            ->keyBy('option_id');

        foreach ($tod as $id => $r) {
            $o = $ours->get($id);
            if ($o === null) {
                $this->diff('option', 'missing_in_engine', (int) $r['PDSCP_APPAID'], $id, $r['PDSCP_Desc'], '');

                continue;
            }
            $this->check('option', 'type_code', (int) $r['PDSCP_APPAID'], $id, $r['PDSCP_TypeCode'], (string) $o->type_code);
            $this->check('option', 'cost_string', (int) $r['PDSCP_APPAID'], $id, $r['PDSCP_QtyCostString'], (string) $o->cost_string);
            $this->check('option', 'price_string', (int) $r['PDSCP_APPAID'], $id, $r['PDSCP_QtyPriceString'], (string) $o->price_string);
            $this->check('option', 'setup_price', (int) $r['PDSCP_APPAID'], $id, self::money($r['PDSCP_SetupPrice']), self::money($o->setup_price));
        }
        foreach ($ours as $id => $o) {
            if (! isset($tod[$id])) {
                $this->diff('option', 'missing_in_tod', $o->product_id, $id, '', $o->description);
            }
        }
    }

    /** @param  array<int, int>  $wanted */
    private function compareProducts(array $wanted): void
    {
        $web = ProductWebPrice::query()->whereIn('product_id', array_keys($wanted))->get()->keyBy('product_id');

        foreach (TsvReader::rows($this->folder.'/dbo_PDS_Products.tsv') as $r) {
            $id = (int) $r['APPAProductID'];
            if (! isset($wanted[$id])) {
                continue;
            }
            $w = $web->get($id);
            if ($w === null) {
                $this->diff('product', 'not_calculated', $id, '', '', '');

                continue;
            }

            $this->check('product', 'undecorated_option', $id, '', $r['UnDecCPSID'], (string) $w->undecorated_option_id);
            $this->check('product', 'default_decoration', $id, '', $r['DefaultDecCPSID'], (string) $w->default_decoration_option_id);
            $this->check('product', 'decoration_product', $id, '', $r['WebDecLiveAPPAID'] ?: (string) $id, (string) $w->decoration_product_id);
            $this->check('product', 'decoration_available', $id, '', TsvReader::bool($r['WebDecAvailableFlag']) ? '1' : '0', $w->decoration_available ? '1' : '0');
            $this->check('product', 'actual_moq', $id, '', $r['ActualMOQ'], (string) $w->actual_moq);
            $this->check('product', 'collection_moq', $id, '', $r['CollectionMOQ'], (string) $w->collection_moq);
            $this->check('product', 'order_value_100', $id, '', self::money($r['OrderVal100']), self::money($w->order_value_100));
            $this->check('product', 'order_value_moq', $id, '', self::money($r['OrderValMOQ']), self::money($w->order_value_moq));
            $this->check('product', 'order_value_display', $id, '', self::money($r['OrderValDisplayMOQ']), self::money($w->order_value_display));
            $this->check('product', 'collection_variant_price', $id, '', self::money($r['CollectionVariantPrice']), self::money($w->collection_variant_price));
            $this->check('product', 'accessory_list', $id, '', trim($r['WebAccessoryList']), trim((string) $w->accessory_list));
            $this->checkJson($id, $r['WebDecorationJSON'], (string) $w->decoration_json);
        }
    }

    private function checkJson(int $id, string $tod, string $ours): void
    {
        $todData = json_decode($tod, true);
        if (! is_array($todData)) {
            $this->diff('product', 'decoration_json_invalid_in_tod', $id, '', mb_substr($tod, 0, 300), mb_substr($ours, 0, 300));

            return;
        }
        $oursData = json_decode($ours, true);
        if ($todData == $oursData) {
            $this->count('product', 'decoration_json', true);

            return;
        }

        // Name the part of the JSON that differs, to make the report usable.
        $part = 'other';
        $todDecs = $todData['Decorations'] ?? [];
        $ourDecs = $oursData['Decorations'] ?? [];
        if (array_diff_key($todData, ['Decorations' => 1]) != array_diff_key((array) $oursData, ['Decorations' => 1])) {
            $part = 'header';
        } elseif (array_column($todDecs, 'DecID') == array_column($ourDecs, 'DecID')) {
            $part = 'decoration_values';
        } elseif (self::sorted(array_column($todDecs, 'DecID')) == self::sorted(array_column($ourDecs, 'DecID'))) {
            $part = 'decoration_order';
        } else {
            $part = 'decoration_set';
        }
        $this->diff('product', 'decoration_json:'.$part, $id, '', mb_substr($tod, 0, 600), mb_substr($ours, 0, 600));
    }

    /** @param  list<mixed>  $values */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function check(string $level, string $field, int $productId, string $optionId, string $tod, string $ours): void
    {
        if ($tod === $ours) {
            $this->count($level, $field, true);

            return;
        }
        $this->diff($level, $field, $productId, $optionId, $tod, $ours);
    }

    private function diff(string $level, string $field, int $productId, string $optionId, string $tod, string $ours): void
    {
        $this->count($level, $field, false);
        $this->differences[] = [
            'level' => $level,
            'field' => $field,
            'product_id' => (string) $productId,
            'option_id' => $optionId,
            'tod' => $tod,
            'engine' => $ours,
        ];
    }

    private function count(string $level, string $field, bool $match): void
    {
        $key = "{$level}.{$field}.".($match ? 'match' : 'differ');
        $this->summary[$key] = ($this->summary[$key] ?? 0) + 1;
    }

    private static function money(mixed $value): string
    {
        return ($value === null || $value === '') ? '' : Money::format((float) $value);
    }
}
