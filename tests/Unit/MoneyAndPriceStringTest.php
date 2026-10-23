<?php

namespace Tests\Unit;

use App\Pricing\DecorationCode;
use App\Pricing\Money;
use App\Pricing\PriceString;
use PHPUnit\Framework\TestCase;

class MoneyAndPriceStringTest extends TestCase
{
    public function test_round_up_to_cent_matches_tod_glblroundup(): void
    {
        $this->assertSame(0.66, Money::roundUpToCent(0.6525));
        $this->assertSame(0.65, Money::roundUpToCent(0.65));
        $this->assertSame(1.31, Money::roundUpToCent(1.305));
        $this->assertSame(0.38, Money::roundUpToCent(0.375));
        $this->assertSame(-2.55, Money::roundUpToCent(-2.555), 'Negatives round towards zero, as Int() does in Access.');
    }

    public function test_markup_is_kept_to_four_decimal_places(): void
    {
        $this->assertSame(1.305, Money::applyMarkup(0.90, 1.45));
        $this->assertSame(0.6525, Money::applyMarkup(0.45, 1.45));
    }

    public function test_numbers_format_like_access_text(): void
    {
        $this->assertSame('60', Money::format(60));
        $this->assertSame('62.5', Money::format(62.5));
        $this->assertSame('0.45', Money::format(0.45));
        $this->assertSame('0', Money::format(0));
        $this->assertSame('-2.55', Money::format(-2.55));
    }

    public function test_price_string_round_trips_and_sorts(): void
    {
        $s = PriceString::parse('500:0.66,1:0.67,1000:0.64');

        $this->assertSame('1:0.67,500:0.66,1000:0.64', $s->toString());
        $this->assertSame(1, $s->minQty());
    }

    public function test_zero_quantity_breaks_are_dropped(): void
    {
        $this->assertSame('50:2', PriceString::parse('0:5,50:2')->toString());
    }

    public function test_price_at_quantity_matches_tod_getprice(): void
    {
        $s = PriceString::parse('100:1.45,250:1.31,500:1.2');

        $this->assertSame(1.45, $s->priceAt(100));
        $this->assertSame(1.45, $s->priceAt(249));
        $this->assertSame(1.31, $s->priceAt(250));
        $this->assertSame(1.2, $s->priceAt(5000));
        // Below the minimum: the minimum-quantity total spread over the units ordered.
        $this->assertSame(2.9, $s->priceAt(50));
        $this->assertSame(0.0, $s->priceAt(0));
        $this->assertSame(0.0, PriceString::parse('')->priceAt(100));
    }

    public function test_decoration_codes_match_tod_dec_ids(): void
    {
        // Real DecID from the 19 Jul 2026 Matrixify reload (product 2568).
        $this->assertSame(
            '2568-padprintpercolourpositionpenpositionsde45mmx7mmpenpositionsde32mmx8mm',
            DecorationCode::optionId(2568, 'Pad Print Per Colour/Position (Pen - Positions D & E - 45mm x 7mm | Pen - Positions D & E - 32mm x 8mm)'),
        );
    }
}
