<?php

namespace App\Search;

/**
 * Reads the useful facts out of what a visitor typed, without AI: quantity,
 * budget, colour, eco, the product types mentioned (via synonyms for the
 * Promodata product types) and whether it reads like a brief rather than a
 * plain product request.
 */
class BriefParser
{
    /** Visitor words → words found in Promodata product type names. */
    public const SYNONYMS = [
        'tee' => 't-shirt', 'tees' => 't-shirt', 'tshirt' => 't-shirt', 'tshirts' => 't-shirt', 't-shirts' => 't-shirt', 'shirts' => 'shirt',
        'polos' => 'polo', 'hoodies' => 'hood', 'hoodie' => 'hood', 'jumpers' => 'jumper', 'sweatshirt' => 'jumper',
        'caps' => 'cap', 'hats' => 'hat', 'beanies' => 'beanie', 'bucket' => 'bucket hat',
        'stubbies' => 'stubby', 'stubbie' => 'stubby', 'coolers' => 'cooler', 'koozie' => 'stubby',
        'bottles' => 'bottle', 'drink' => 'bottle', 'mugs' => 'mug', 'cups' => 'cup', 'keepcup' => 'keep cup', 'tumblers' => 'tumbler',
        'totes' => 'tote', 'bags' => 'bag', 'backpacks' => 'backpack', 'drawstring' => 'drawstring',
        'pens' => 'pen', 'notebooks' => 'notebook', 'lanyards' => 'lanyard', 'wristbands' => 'wristband',
        'usb' => 'usb', 'powerbank' => 'power bank', 'powerbanks' => 'power bank', 'chargers' => 'charger',
        'umbrellas' => 'umbrella', 'towels' => 'towel', 'socks' => 'sock', 'mints' => 'mint', 'keyrings' => 'key ring', 'keyring' => 'key ring',
    ];

    public const COLOURS = ['black', 'white', 'navy', 'blue', 'red', 'green', 'yellow', 'orange', 'pink', 'purple', 'grey', 'gray', 'natural', 'brown', 'maroon', 'teal', 'gold', 'silver'];

    private const BRIEF_WORDS = ['staff', 'team', 'event', 'conference', 'expo', 'bbq', 'party', 'christmas', 'gift', 'gifts', 'visitors', 'guests', 'clients', 'customers', 'members', 'club', 'school', 'launch', 'something', 'ideas', 'suggest', 'occasion', 'festival', 'wedding', 'onboarding', 'welcome', 'summer', 'winter'];

    /** @return array{text:string, qty:?int, budget:?float, under:bool, colours:list<string>, eco:bool, types:list<string>, terms:list<string>, groups:list<string>, negatives:list<string>, briefLike:bool} */
    public function parse(string $text): array
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
        $budget = preg_match('/\$\s*(\d+(?:\.\d{1,2})?)/', $t, $m) ? (float) $m[1] : null;
        $noMoney = preg_replace('/\$\s*\d+(?:\.\d+)?/', ' ', $t);
        $qty = preg_match('/\b(\d{1,3}(?:,\d{3})+|\d{2,6})\b(?!\s*(?:mm|cm|ml|l|gsm|mah|%|th|st|nd|rd|\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)))/', $noMoney, $m)
            ? (int) str_replace(',', '', $m[1]) : null;

        $negatives = [];
        if (preg_match_all('/\b(?:no|not|without|except|avoid)\s+([a-z\- ]{3,20}?)(?=[,.;]|\band\b|$)/', $t, $mm)) {
            $negatives = array_map('trim', $mm[1]);
        }

        $words = preg_split('/[^a-z0-9\-]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
        $types = [];
        $terms = [];
        foreach ($words as $w) {
            if (isset(self::SYNONYMS[$w])) {
                $types[] = self::SYNONYMS[$w];
            } elseif (strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::COLOURS, true) && ! in_array($w, self::BRIEF_WORDS, true)) {
                $terms[] = $w;
            }
        }

        $groups = array_values(array_filter(array_map('trim', preg_split('/\s+and\s+|,|\+/', $t))));
        $briefWords = count(array_intersect($words, self::BRIEF_WORDS));

        return [
            'text' => $t,
            'qty' => $qty && $qty <= 100000 ? $qty : null,
            'budget' => $budget,
            'under' => (bool) preg_match('/under|below|less than|max|up to/', $t),
            'colours' => array_values(array_intersect(self::COLOURS, $words)),
            'eco' => (bool) preg_match('/\beco\b|sustain|recycl|organic|bamboo|green\b/', $t),
            'types' => array_values(array_unique($types)),
            'terms' => array_values(array_unique($terms)),
            'groups' => count($groups) > 1 ? $groups : [],
            'negatives' => $negatives,
            'briefLike' => $briefWords > 0 || $negatives !== [] || ($types === [] && count($words) >= 5),
        ];
    }
}
