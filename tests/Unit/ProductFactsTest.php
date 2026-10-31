<?php

namespace Tests\Unit;

use App\Catalogue\ProductFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductFactsTest extends TestCase
{
    public static function dimensionTexts(): array
    {
        return [
            'mm pair' => ['140mm x 10mm', [140.0, 10.0]],
            'unit once at end' => ['14 x 7.5 x 2cm', [140.0, 75.0, 20.0]],
            'diameter and height' => ['Ø75mm x H100mm', [100.0, 75.0]],
            'labelled cm' => ['W 30cm x H 40cm', [400.0, 300.0]],
            'decimal comma' => ['7,5 x 3 cm', [75.0, 30.0]],
            'apparel sizes' => ['S, M, L, XL, 2XL', []],
            'no unit' => ['140 x 10', []],
        ];
    }

    #[DataProvider('dimensionTexts')]
    public function test_dimensions_are_read_in_millimetres_largest_first(string $text, array $expected): void
    {
        $this->assertSame($expected, ProductFacts::dimensions([['name' => 'Dimensions', 'detail' => $text]]));
    }

    public function test_dimensions_ignore_other_details(): void
    {
        $this->assertSame([], ProductFacts::dimensions([['name' => 'Print Area', 'detail' => '40mm x 10mm']]));
    }

    public function test_material_comes_from_attributes_then_details(): void
    {
        $this->assertSame('plastic', ProductFacts::material([['name' => 'Material', 'value' => 'Plastic (non-specific)']], []));
        $this->assertSame('cotton, polyester', ProductFacts::material([], [['name' => 'Fabric', 'detail' => '65% Cotton / 35% Polyester, 180gsm']]));
        $this->assertSame('stainless steel', ProductFacts::material([['name' => 'Material', 'value' => 'Stainless Steel']], []));
        $this->assertNull(ProductFacts::material([['name' => 'Ink Colour', 'value' => 'Black']], []));
    }

    public function test_lead_times_are_working_days(): void
    {
        $this->assertSame(10, ProductFacts::parseLeadTime('10 working days'));
        $this->assertSame(7, ProductFacts::parseLeadTime('5-7 days'));
        $this->assertSame(10, ProductFacts::parseLeadTime('2 weeks'));
        $this->assertSame(2, ProductFacts::parseLeadTime('48 hours'));
        $this->assertNull(ProductFacts::parseLeadTime('Contact us'));
    }

    public function test_fastest_lead_time_across_base_lines(): void
    {
        $groups = [
            ['base' => ['lead_time' => '15 working days'], 'additions' => []],
            ['base' => ['lead_time' => '5 days'], 'additions' => []],
            ['base' => ['lead_time' => null], 'additions' => []],
        ];
        $this->assertSame(5, ProductFacts::leadTimeDays($groups));
    }

    public function test_unbranded_moq_and_cost_use_undecorated_lines_only(): void
    {
        $groups = [
            ['base' => ['undecorated' => false, 'breaks' => [['qty' => 25, 'price' => 1.00]]], 'additions' => []],
            ['base' => ['undecorated' => true, 'breaks' => [['qty' => 100, 'price' => 2.10], ['qty' => 50, 'price' => 2.40], ['qty' => 0, 'price' => 9]]], 'additions' => []],
        ];
        $this->assertSame([50, 2.40], ProductFacts::undecorated($groups));
        $this->assertSame([null, null], ProductFacts::undecorated([$groups[0]]));
    }
}
