<?php

namespace App\Search;

/**
 * Searches that name an occasion or a need rather than a product (Scott, 3 Nov 2026): "gifts under $30",
 * "merch stand essentials", "conference giveaways", "staff welcome pack". Each occasion lists the
 * subcategories (TOD-style tags, as in the filter menu) that suit it, best first. The search then takes a
 * few of the best products from each, for a varied list rather than twelve of one thing.
 */
class Occasions
{
    /** name => [trigger pattern, subcategory tags best first, heading] */
    public const LIST = [
        'merch_stand' => ['/merch\s*(stand|table|desk|stall|booth|shop)|band\s*merch|\bgigs?\b|tour\s*merch|festival|market\s*stall|fan\s*merch|music\s*merch|essentials|\bmerch\b\s*for\s*(my|our)?\s*(band|tour|show|fans)/i',
            ['tshirts', 'hoodies', 'caps', 'totebags', 'stubbyholders', 'stickers', 'buttonbadges', 'beanies', 'brimbuckethats', 'drinkbottles', 'keyrings', 'lanyards', 'siliconwristbands', 'socks', 'lapelpins', 'bottleopenerkeyrings'],
            'Merch stand essentials'],
        'gift' => ['/\bgifts?\b|\bgifting\b|present|thank\s*you|christmas|xmas|corporate gift|client gift|hamper|appreciation|reward|end of year|eofy|birthday/i',
            ['giftsets', 'drinkbottles', 'travelmugs', 'hampers', 'powerbanks', 'notebooks', 'metalpens', 'speakers', 'wirelesschargers', 'coolerbags', 'backpacks', 'thermoses', 'chocolates', 'umbrellas', 'blankets', 'candles', 'wineglasses', 'cheeseboardsknives', 'earbuds', 'compendiumsportfolios'],
            'Gift ideas'],
        'conference' => ['/conference|expo|trade\s*show|exhibition|giveaway|give\s*away|show\s*bag|event\s*bag|seminar|summit|exhibitor|booth/i',
            ['totebags', 'plasticpens', 'lanyards', 'notebooks', 'drinkbottles', 'reusablecoffeecups', 'stickynotesflags', 'keyrings', 'lipbalms', 'mints', 'stressballs', 'handsanitisers', 'chargingcables', 'buttonbadges', 'namebadges', 'metalpens', 'mixedlollies'],
            'Event giveaways'],
        'staff' => ['/staff|employee|onboard|welcome\s*(pack|kit|box)|new\s*starter|induction|team\s*(pack|kit|merch|gift)|uniform|corporate wear/i',
            ['tshirts', 'poloshirts', 'hoodies', 'softshelljackets', 'drinkbottles', 'travelmugs', 'notebooks', 'metalpens', 'backpacks', 'lanyards', 'caps', 'laptopbags', 'reusablecoffeecups', 'powerbanks'],
            'Team and welcome packs'],
        'sport' => ['/\bsports?\b|\bclub\b|\bteam\b|footy|football|soccer|netball|cricket|basketball|\bgym\b|fitness|running|fun\s*run|marathon|school|swim/i',
            ['poloshirts', 'jerseys', 'tshirts', 'caps', 'brimbuckethats', 'drinkbottles', 'dufflebags', 'drawstringbags', 'gymtowels', 'hoodies', 'sportsshorts', 'proteinshakers', 'siliconwristbands', 'stubbyholders', 'coolingtowels', 'visors'],
            'Club and sport'],
        'summer' => ['/summer|beach|outdoor|\bsun\b|sunny|pool|picnic|camping|\bbbq\b|barbecue/i',
            ['brimbuckethats', 'caps', 'bathtowels', 'sunscreens', 'coolerbags', 'stubbyholders', 'sunglassesaccessories', 'beachumbrellas', 'picnicrugs', 'drinkbottles', 'coolingtowels', 'frisbeesthrowingtoys', 'thongs', 'strawhats'],
            'Summer and outdoors'],
        'winter' => ['/winter|\bcold\b|\bwarm\b|snow|\bski\b/i',
            ['beanies', 'hoodies', 'polarfleecejumpers', 'pufferjackets', 'softshelljackets', 'scarves', 'gloves', 'travelmugs', 'thermoses', 'blankets', 'umbrellas'],
            'Winter warmers'],
        'eco' => ['/\beco\b|sustainab|recycl|environment|green\b|planet|plastic\s*free|reusable/i',
            ['reusablecoffeecups', 'drinkbottles', 'totebags', 'reusablegrocerybags', 'woodenpens', 'notebooks', 'drinkingstraws', 'cutlerysets', 'lunchbagslunchboxes'],
            'Eco-friendly picks'],
        'kids' => ['/\bkids?\b|children|child|youth|junior|toddler|baby/i',
            ['tshirts', 'caps', 'brimbuckethats', 'drinkbottles', 'lunchbagslunchboxes', 'backpacks', 'colouredpencils', 'plushtoys', 'stressballs', 'temporarytattoos', 'frisbeesthrowingtoys', 'yoyos', 'jigsaws'],
            'For kids'],
    ];

    /** @return list<string> occasion names found in the text, in the order they're listed */
    public static function find(string $text): array
    {
        $found = [];
        foreach (self::LIST as $name => [$re]) {
            if (preg_match($re, $text)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Subcategory tags for these occasions, best first, the occasions' lists interleaved so two occasions
     * share the top of the results.
     *
     * @param  list<string>  $occasions
     * @return list<string>
     */
    public static function tags(array $occasions): array
    {
        $lists = array_map(fn ($o) => self::LIST[$o][1], $occasions);
        $longest = $lists ? max(array_map('count', $lists)) : 0;
        $out = [];
        for ($i = 0; $i < $longest; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i]) && ! in_array($list[$i], $out, true)) {
                    $out[] = $list[$i];
                }
            }
        }

        return $out;
    }

    public static function heading(array $occasions): ?string
    {
        return $occasions ? self::LIST[$occasions[0]][2] : null;
    }
}
