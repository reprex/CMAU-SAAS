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
     * @param  Collection<int, PriceOption>  $decorations  type-10 options of the decoration product
     */
    public function build(
        bool $brandingAvailable,
        ?PriceOption $undecorated,
        Collection $decorations,
        ?string $defaultOptionId,
    ): string {
        $sortQty = (int) config('pricing.decoration_sort_qty', 50);

        $items = $decorations
            ->filter(fn (PriceOption $o) => $o->type_code === DecTypeCode::DECORATION && $o->price_string !== null && $o->price_string !== '')
            ->sortBy([
                // Default decoration first (TOD DecJSONSort 1), then the others (2)...
                fn (PriceOption $a, PriceOption $b) => ($a->option_id === $defaultOptionId ? 1 : 2) <=> ($b->option_id === $defaultOptionId ? 1 : 2),
                // ...ordered by price at 50 units plus setup.
                fn (PriceOption $a, PriceOption $b) => ($a->prices()->priceAt($sortQty) + $a->setup_price) <=> ($b->prices()->priceAt($sortQty) + $b->setup_price),
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
        $name = str_replace(',', '', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return trim($name);
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
