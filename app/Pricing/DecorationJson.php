<?php

namespace App\Pricing;

use App\Models\DecTypeCode;
use App\Models\PriceOption;
use Illuminate\Support\Collection;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Builds and validates the custom.decorationjson metafield read by the CMAU
 * PDP pricing calculator. The shape is a fixed contract with the theme:
 * see docs/contracts/decorationjson.md.
 *
 * Unlike TOD (string concatenation), the JSON is produced with a real
 * serialiser, so decoration names with quotes or line breaks can never
 * break the PDP.
 */
class DecorationJson
{
    /**
     * Decorations are listed as TOD's "DecJSON - ConcatQuery" orders them: by
     * display group (TOD DecJSONSort; here the json_sort setting, default 2),
     * then by price at 50 units plus setup. TOD puts an option in group 1
     * when it becomes the default, and supplier price files (Melbourne Merch)
     * set their own order, so most products are simply cheapest first.
     *
     * @param  Collection<int, PriceOption>  $decorations  type-10 options of the decoration product
     * @param  array<string, int>  $sortGroups  option id => display group
     */
    public function build(
        bool $brandingAvailable,
        ?PriceOption $undecorated,
        Collection $decorations,
        array $sortGroups = [],
    ): string {
        $sortQty = (int) config('pricing.decoration_sort_qty', 50);
        $group = fn (PriceOption $o) => $sortGroups[$o->option_id] ?? 2;

        $items = $decorations
            ->filter(fn (PriceOption $o) => $o->type_code === DecTypeCode::DECORATION && $o->price_string !== null && $o->price_string !== '')
            ->sortBy([
                fn (PriceOption $a, PriceOption $b) => $group($a) <=> $group($b),
                fn (PriceOption $a, PriceOption $b) => Money::toUnits($a->prices()->priceAt($sortQty) + $a->setup_price) <=> Money::toUnits($b->prices()->priceAt($sortQty) + $b->setup_price),
                // Ties: descending option id, which matches TOD for 86% of tied
                // products (Access's own tie order is not stable).
                fn (PriceOption $a, PriceOption $b) => strcmp($b->option_id, $a->option_id),
            ])
            ->values()
            ->map(fn (PriceOption $o) => [
                'DecID' => $o->option_id,
                'DecName' => self::cleanName($o->display_description ?: $o->description),
                'CombinedSetup' => Money::format($o->setup_price),
                'CombinedPrcString' => $o->price_string,
            ])
            ->all();

        $json = json_encode([
            'BrandFlag' => $brandingAvailable ? 'Price includes branding' : 'Price does not include branding',
            'UndecoratedPriceSetup' => Money::format($undecorated?->setup_price ?? 0),
            'UndecoratedPriceString' => $undecorated?->price_string ?: '1:0',
            'Decorations' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertValid($json);

        return $json;
    }

    /**
     * TOD removes commas from decoration names; line breaks and repeated
     * spaces are also collapsed here (they produced invalid JSON in TOD).
     */
    public static function cleanName(string $name): string
    {
        // TOD: Replace(name, ",", ""), other spacing kept as it is.
        $name = str_replace(',', '', $name);
        // TOD wrote line breaks straight into the JSON, which broke it (9 live
        // products); here each line break becomes one space.
        if (preg_match('/[\r\n]/', $name)) {
            $name = rtrim((string) preg_replace('/[ \t]*[\r\n]+[ \t]*/', ' ', $name));
        }

        return $name;
    }

    /** Throws if the JSON does not match docs/contracts/decorationjson.schema.json. */
    public function assertValid(string $json): void
    {
        $errors = $this->validate($json);
        if ($errors !== []) {
            throw new RuntimeException('DecorationJSON does not match the CMAU contract: '.implode('; ', $errors));
        }
    }

    /** @return list<string> */
    public function validate(string $json): array
    {
        $validator = new Validator;
        $schema = (string) file_get_contents(resource_path('contracts/decorationjson.schema.json'));
        $result = $validator->validate(json_decode($json), $schema);

        if ($result->isValid()) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $errors) {
            foreach ((array) $errors as $error) {
                $messages[] = ($path ?: '/').': '.$error;
            }
        }

        return $messages;
    }
}
