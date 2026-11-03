<?php

namespace App\Search;

use App\Shopify\RangeCollections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Search words → range subcategory tags (Scott, 3 Nov 2026). The list is CrowdMerch's own filter-menu
 * synonym sheet ("Filter Menu - subcat", Web Updates › Website update 2024 › Final CrowdMerch PreOrder
 * Store), kept as database/data/search-synonyms.csv: every subcategory's menu name and synonyms, by
 * category. Category words ("bags", "drinkware") and a few broad words ("pens", "cups", "towels") cover
 * several subcategories. A search for "keep cup" or "water bottle" lands on the subcategory instead of
 * product names that happen to contain a word.
 */
class Synonyms
{
    public const FILE = 'data/search-synonyms.csv';

    /** Broad words a shopper uses for a group of subcategories. */
    private const BROAD = [
        'pen' => ['plasticpens', 'metalpens', 'woodenpens', 'styluspens'],
        'pencil' => ['greyleadpencils', 'colouredpencils'],
        'cup' => ['reusablecoffeecups', 'plasticcupstumblers', 'coffeemugs', 'travelmugs'],
        'coffee cup' => ['reusablecoffeecups', 'coffeemugs', 'travelmugs'],
        'bottle' => ['drinkbottles', 'thermoses', 'proteinshakers'],
        'towel' => ['bathtowels', 'handtowels', 'gymtowels', 'golftowels', 'coolingtowels', 'teatowels'],
        'umbrella' => ['umbrellas', 'golfumbrellas', 'beachumbrellas'],
        'hat' => ['caps', 'truckercaps', 'brimbuckethats', 'beanies', 'strawhats', 'visors'],
        'shirt' => ['tshirts', 'poloshirts', 'buttonupshirts', 'workshirts', 'worktshirts', 'workpoloshirts'],
        'tee' => ['tshirts', 'worktshirts'],
        'polo' => ['poloshirts', 'workpoloshirts'],
        'hoodie' => ['hoodies', 'workhoodies'],
        'jumper' => ['sweaters', 'hoodies', 'polarfleecejumpers', 'knittedjumpers', 'workjumpers', 'sportsjumpers'],
        'jacket' => ['softshelljackets', 'pufferjackets', 'rainjackets', 'polarfleecejacket', 'workjackets', 'trackjackets', 'varsityjackets'],
        'clothing' => ['tshirts', 'poloshirts', 'hoodies', 'sweaters', 'softshelljackets', 'caps'],
        'apparel' => ['tshirts', 'poloshirts', 'hoodies', 'sweaters', 'softshelljackets', 'caps'],
        'lolly' => ['mixedlollies', 'jellybeans', 'gummilollies', 'mints', 'chocolates', 'chocbeans', 'sugarfreelollies'],
        'candy' => ['mixedlollies', 'jellybeans', 'gummilollies', 'mints', 'chocolates', 'chocbeans'],
        'sweet' => ['mixedlollies', 'jellybeans', 'gummilollies', 'mints', 'chocolates', 'chocbeans'],
        'tech' => ['powerbanks', 'wirelesschargers', 'speakers', 'earbuds', 'chargingcables', 'flashdrives', 'phonestands'],
        'stationery' => ['notebooks', 'notepads', 'plasticpens', 'metalpens', 'stickynotesflags', 'highlighters', 'pencilcases'],
        'stress toy' => ['stressballs', 'stressanimals', 'stressfruitvegetables', 'stresstransport', 'miscstressshapes', 'stresskeyrings'],
        'glass' => ['beerglasses', 'wineglasses', 'glasstumblers', 'cocktailglasses', 'shotglasses'],
        'wristband' => ['siliconwristbands', 'eventwristbands'],
        'badge' => ['buttonbadges', 'namebadges', 'lapelpins'],
        'pin' => ['lapelpins', 'buttonbadges'],
        'cooler' => ['stubbyholders', 'coolerbags', 'bottlecoolers'],
        'stubby' => ['stubbyholders'],
        'stubbie' => ['stubbyholders'],
        'esky' => ['coolerbags'],
        'charger' => ['powerbanks', 'wirelesschargers', 'usbcarchargers'],
        'usb' => ['flashdrives', 'chargingcables', 'usbhubs'],
        'shorts' => ['sportsshorts', 'dressshorts', 'workshorts'],
        'pants' => ['trackpants', 'cargopants', 'workpants', 'slacks'],
        'hi vis' => ['safetywear'],
        'merch pack' => ['totebags', 'drinkbottles', 'tshirts', 'notebooks', 'plasticpens'],
    ];

    /** Words that mean nothing on their own in a search. */
    private const STOP = ['a', 'an', 'the', 'and', 'or', 'for', 'with', 'of', 'to', 'in', 'on', 'my', 'our', 'some', 'any', 'custom', 'printed', 'branded', 'logo', 'promo', 'promotional', 'merch', 'merchandise', 'other', 'misc', 'miscellaneous', 'assorted', 'various', 'item', 'product'];

    public static function norm(string $s): string
    {
        $s = mb_strtolower(str_replace(["'", '’'], '', $s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** "bottles" → "bottle", "boxes" → "box", "lollies" → "lolly"; short words are left alone. */
    public static function singular(string $w): string
    {
        if (strlen($w) <= 3 || preg_match('/(ss|us|is|ics)$/', $w)) {
            return $w;
        }
        if (preg_match('/(x|ch|sh)es$/', $w)) {
            return substr($w, 0, -2);
        }
        if (preg_match('/ies$/', $w)) {
            return substr($w, 0, -3).'y';
        }

        return str_ends_with($w, 's') ? substr($w, 0, -1) : $w;
    }

    public static function key(string $phrase): string
    {
        $words = array_filter(explode(' ', self::norm($phrase)), fn ($w) => $w !== '');

        return implode(' ', array_map([self::class, 'singular'], $words));
    }

    /** @return array<string, list<string>> term (normalised, singular) => subcategory tags */
    public function map(): array
    {
        return Cache::remember('search:synonyms:v2', 600, function () {
            $map = [];
            $add = function (string $term, array $tags) use (&$map) {
                $k = self::key($term);
                if ($k === '' || in_array($k, self::STOP, true)) {
                    return;
                }
                $map[$k] = array_values(array_unique(array_merge($map[$k] ?? [], $tags)));
                // "Water Bottles" also as one word ("waterbottles"), as the old filter matched.
                $joined = str_replace(' ', '', $k);
                if ($joined !== $k) {
                    $map[$joined] = array_values(array_unique(array_merge($map[$joined] ?? [], $tags)));
                }
            };
            $byCat = [];
            $f = fopen(database_path(self::FILE), 'r');
            $head = fgetcsv($f);
            while (($row = fgetcsv($f)) !== false) {
                $r = array_combine($head, array_pad($row, count($head), ''));
                $tag = strtolower(trim($r['TagCode']));
                if ($tag === '' || $tag === 'any' || $tag === 'z') {
                    continue;
                }
                $byCat[$r['CatCode']][] = $tag;
                $add(preg_replace('/^(other|misc)\s+/i', '', $r['MenuText']), [$tag]);
                foreach (explode(',', $r['Synonyms']) as $syn) {
                    $add($syn, [$tag]);
                }
            }
            fclose($f);
            // Category words: "bags", "drinkware", "headwear", "golf"…
            $catWords = ['bags' => 'bags', 'drinkware' => 'drinkware', 'headwear' => 'headwear', 'headgear' => 'headwear', 'glassware' => 'glassware', 'golf' => 'golf', 'writing' => 'writing',
                'confectionery' => 'confectionery', 'confectionary' => 'confectionery', 'footwear' => 'footwear', 'jackets' => 'jackets', 'jumpers' => 'jumpers', 'workwear' => 'workwear',
                'uniforms' => 'uniforms', 'homewares' => 'homeliving', 'homeware' => 'homeliving', 'kitchen' => 'homeliving', 'outdoors' => 'leisureoutdoors', 'office' => 'officebusiness',
                'technology' => 'phonetechnology', 'events' => 'exhibitionsevents', 'games' => 'fungames', 'toys' => 'fungames', 'tools' => 'keyringstools', 'print' => 'print', 'bottoms' => 'bottoms'];
            foreach ($catWords as $word => $cat) {
                if (! empty($byCat[$cat])) {
                    $map[self::key($word)] = array_values(array_unique(array_merge($map[self::key($word)] ?? [], $byCat[$cat])));
                }
            }
            foreach (self::BROAD as $word => $tags) {
                $map[self::key($word)] = $tags; // broad words cover the group, not one subcategory
            }

            return $map;
        });
    }

    /**
     * The subcategory tags a search names, longest phrase first ("travel mug" before "mug"), with the words used.
     *
     * @return array{tags: list<string>, used: list<string>, rest: list<string>}
     */
    public function match(string $text): array
    {
        $map = $this->map();
        $words = array_values(array_filter(explode(' ', self::key($text)), fn ($w) => $w !== ''));
        $tags = $used = [];
        $taken = array_fill(0, count($words), false);
        for ($n = min(4, count($words)); $n >= 1; $n--) {
            for ($i = 0; $i + $n <= count($words); $i++) {
                if (in_array(true, array_slice($taken, $i, $n), true)) {
                    continue;
                }
                $phrase = implode(' ', array_slice($words, $i, $n));
                $hit = $map[$phrase] ?? ($n > 1 ? ($map[str_replace(' ', '', $phrase)] ?? null) : null);
                if ($hit && ! ($n === 1 && in_array($phrase, self::STOP, true))) {
                    array_push($tags, ...$hit);
                    $used[] = $phrase;
                    for ($j = $i; $j < $i + $n; $j++) {
                        $taken[$j] = true;
                    }
                }
            }
        }
        $rest = [];
        foreach ($words as $i => $w) {
            if (! $taken[$i] && ! in_array($w, self::STOP, true)) {
                $rest[] = $w;
            }
        }

        return ['tags' => array_values(array_unique($tags)), 'used' => $used, 'rest' => $rest];
    }

    /**
     * Live product types for these subcategory tags.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function typesFor(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $collections = app(RangeCollections::class);
        $types = Cache::remember('search:live-types', 600, fn () => DB::table('products')->where('tier', 'live')->where('discontinued', false)
            ->whereNotNull('promodata_type_name')->distinct()->pluck('promodata_type_name')->all());
        $order = array_flip($tags);
        $out = array_values(array_filter($types, fn ($t) => isset($order[$collections->tagFor($t) ?? ''])));
        usort($out, fn ($a, $b) => $order[$collections->tagFor($a)] <=> $order[$collections->tagFor($b)]);

        return $out;
    }

    /** @return list<string> every live product type */
    public static function liveTypes(): array
    {
        return Cache::remember('search:live-types', 600, fn () => DB::table('products')->where('tier', 'live')->where('discontinued', false)
            ->whereNotNull('promodata_type_name')->distinct()->pluck('promodata_type_name')->all());
    }
}
