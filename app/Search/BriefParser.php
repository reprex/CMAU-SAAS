<?php

namespace App\Search;

use Carbon\CarbonImmutable;

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

    /**
     * @return array{text:string, qty:?int, budget:?float, budget_min:?float, under:bool, colours:list<string>, eco:bool, types:list<string>, terms:list<string>,
     *   groups:list<string>, negatives:list<string>, briefLike:bool, tags:list<string>, occasions:list<string>, rest:list<string>, need_by:?string}
     */
    public function parse(string $text): array
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
        [$budget, $budgetMin, $under, $noMoney] = self::budget($t);
        $noMoney = preg_replace('/\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b|\b(?:in|within)\s+\d{1,2}\s+(?:day|week)s?\b/', ' ', $noMoney); // dates aren't quantities
        $qty = preg_match('/\b(\d{1,3}(?:,\d{3})+|\d{2,6})\b(?!\s*(?:mm|cm|ml|l|gsm|mah|th|st|nd|rd)\b|\s*%|\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec))/', $noMoney, $m)
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

        // CrowdMerch's own synonym list names subcategories; occasions name a need ("gifts", "merch stand").
        $syn = app(Synonyms::class)->match(preg_replace('/\b(?:no|not|without|except|avoid)\s+[a-z\- ]{3,20}?(?=[,.;]|\band\b|$)/', ' ', $noMoney));
        $occasions = Occasions::find($t);

        $groups = array_values(array_filter(array_map('trim', preg_split('/\s+and\s+|,|\+/', $t))));
        $briefWords = count(array_intersect($words, self::BRIEF_WORDS));

        return [
            'text' => $t,
            'qty' => $qty && $qty <= 100000 ? $qty : null,
            'budget' => $budget,
            'budget_min' => $budgetMin,
            'under' => $under,
            'colours' => array_values(array_intersect(self::COLOURS, $words)),
            'eco' => (bool) preg_match('/\beco\b|sustain|recycl|organic|bamboo|green\b/', $t),
            'types' => array_values(array_unique($types)),
            'terms' => array_values(array_unique($terms)),
            'groups' => count($groups) > 1 ? $groups : [],
            'negatives' => $negatives,
            'briefLike' => $briefWords > 0 || $negatives !== [] || $occasions !== [] || ($types === [] && $syn['tags'] === [] && count($words) >= 5),
            'tags' => $syn['tags'],
            'occasions' => $occasions,
            'rest' => array_values(array_filter($syn['rest'], fn ($w) => strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::COLOURS, true) && ! in_array($w, self::BRIEF_WORDS, true))),
            'need_by' => self::needBy($t),
        ];
    }

    /**
     * The date the visitor needs it by (docs/lead-times.md, search by date): "by 20 Nov", "by 20th
     * November", "by 20/11", "before Christmas", "by Friday", "in 3 weeks", "within 10 days".
     * Returns Y-m-d, or null.
     */
    public static function needBy(string $t, ?CarbonImmutable $today = null): ?string
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $next = function (int $m, int $d) use ($today): ?string {
            if (! checkdate($m, $d, $today->year)) {
                return null;
            }
            $date = CarbonImmutable::create($today->year, $m, $d)->startOfDay();

            return ($date->lt($today) ? $date->addYear() : $date)->toDateString();
        };
        $lead = '(?:by|before|for|on|need(?:ed)?(?: it)?(?: by)?|deliver(?:ed|y)?(?: by)?|arrive(?: by)?|due)\s+(?:the\s+)?(?:(?:mon|tues?|wed(?:nes)?|thu(?:rs)?|fri|sat(?:ur)?|sun)(?:day)?\s+)?';
        if (preg_match('/'.$lead.'(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?([a-z]{3})[a-z]*\b/', $t, $m) && isset($months[$m[2]])) {
            return $next($months[$m[2]], (int) $m[1]);
        }
        if (preg_match('/'.$lead.'([a-z]{3})[a-z]*\s+(\d{1,2})(?:st|nd|rd|th)?\b/', $t, $m) && isset($months[$m[1]])) {
            return $next($months[$m[1]], (int) $m[2]);
        }
        if (preg_match('/'.$lead.'(\d{1,2})\/(\d{1,2})(?:\/\d{2,4})?\b/', $t, $m)) {
            return $next((int) $m[2], (int) $m[1]); // Australian day/month
        }
        if (preg_match('/\b(?:before|by|for)\s+(?:xmas|christmas)\b/', $t)) {
            return $next(12, 24);
        }
        if (preg_match('/\b(?:in|within)\s+(\d{1,2}|a|one|two|three|four|five|six)\s+(day|week)s?\b/', $t, $m)) {
            $n = ['a' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6][$m[1]] ?? (int) $m[1];

            return $today->addDays($m[2] === 'week' ? $n * 7 : $n)->toDateString();
        }
        if (preg_match('/\b(?:by|before|for)\s+(?:next\s+)?(mon|tues?|wed(?:nes)?|thu(?:rs)?|fri|sat(?:ur)?|sun)(?:day)?\b/', $t, $m)) {
            $map = ['mon' => 1, 'tue' => 2, 'tues' => 2, 'wed' => 3, 'wednes' => 3, 'thu' => 4, 'thurs' => 4, 'fri' => 5, 'sat' => 6, 'satur' => 6, 'sun' => 0];
            $dow = $map[$m[1]] ?? null;
            if ($dow !== null) {
                $d = $today->addDay();
                while ((int) $d->dayOfWeek !== $dow) {
                    $d = $d->addDay();
                }

                return $d->toDateString();
            }
        }

        return null;
    }

    /**
     * Price each from the text: "$20", "20 dollars", "under 20 each", "around $15", "$10-$20", "10 to 20 bucks".
     * Returns [max, min, strict (under/max/up to), the text without the money words].
     *
     * @return array{0: ?float, 1: ?float, 2: bool, 3: string}
     */
    public static function budget(string $t): array
    {
        $n = '(\d+(?:\.\d{1,2})?)';
        $money = '(?:\$\s*'.$n.'|'.$n.'\s*(?:dollars?|bucks|aud|ea(?:ch)?\b))';
        $max = $min = null;
        $strict = (bool) preg_match('/under|below|less than|max(?:imum)?|up to|no more than|cheaper than|within|budget of/', $t);
        $rest = $t;
        // A range: "$10-$20", "$10 to 20", "10-20 dollars", "between 10 and 20 dollars".
        if (preg_match('/(?:between\s*)?\$?\s*'.$n.'\s*(?:-|–|to|and)\s*\$?\s*'.$n.'\s*(?:dollars?|bucks|each|ea)?/', $t, $m, PREG_OFFSET_CAPTURE)
            && (str_contains($m[0][0], '$') || preg_match('/dollar|buck|each|\bea\b/', $m[0][0]))) {
            [$a, $b] = [(float) $m[1][0], (float) $m[2][0]];
            [$min, $max] = [min($a, $b), max($a, $b)];
            $strict = true;
            $rest = substr_replace($t, ' ', $m[0][1], strlen($m[0][0]));
        } elseif (preg_match('/'.$money.'/', $t, $m, PREG_OFFSET_CAPTURE)) {
            $max = (float) ($m[1][0] !== '' ? $m[1][0] : $m[2][0]);
            $rest = substr_replace($t, ' ', $m[0][1], strlen($m[0][0]));
        } elseif (preg_match('/(?:under|below|less than|up to|max(?:imum)?|around|about|roughly|budget(?: of)?|no more than)\s+'.$n.'\b(?!\s*(?:people|staff|guests|units|pcs|pieces|items|attendees|members|of|x\b))/', $t, $m, PREG_OFFSET_CAPTURE)) {
            $max = (float) $m[1][0];
            $rest = substr_replace($t, ' ', $m[0][1], strlen($m[0][0]));
        }

        return [$max, $min, $strict, $rest];
    }
}
