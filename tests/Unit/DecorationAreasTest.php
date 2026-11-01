<?php

namespace Tests\Unit;

use App\Catalogue\DecorationAreas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Print areas read from supplier decoration wording. The strings are written
 * for the tests in the styles Promodata suppliers use; none is customer data.
 */
class DecorationAreasTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<array{0: ?string, 1: ?string, 2: int, 3: ?int}>}> [text, [[position, method, w, h], ...]] */
    public static function texts(): array
    {
        return [
            'stubby holder (Trends 104743 product page)' => ['Full colour printing (sublimation print area: 209mm x 108mm)', [[null, 'sublimation', 209, 108]]],
            'print area label' => ['Print Area: 209mm x 108mm', [[null, null, 209, 108]]],
            'wrap with the unit once at the end' => ['Wrap: 209 x 108 mm', [['Wrap around', null, 209, 108]]],
            'wrap around spelled out' => ['Sublimation wrap-around 200 x 90mm', [['Wrap around', 'sublimation', 200, 90]]],
            'front' => ['Front 280 x 350mm', [['Front', null, 280, 350]]],
            'front and back' => ['Front: 280 x 350mm, Back: 280 x 350mm', [['Front', null, 280, 350], ['Back', null, 280, 350]]],
            'method carried to later positions' => ['DTF: Front 280 x 350mm, Back 280 x 350mm, Sleeve 80 x 80mm', [['Front', 'dtf', 280, 350], ['Back', 'dtf', 280, 350], ['Sleeve', 'dtf', 80, 80]]],
            'a method per position' => ['Screen print front 280x350mm; embroidery left chest 90 x 90mm', [['Front', 'screen', 280, 350], ['Left chest', 'embroidery', 90, 90]]],
            'method after the size' => ['Front 250 x 300mm (screen print)', [['Front', 'screen', 250, 300]]],
            'left chest' => ['Left Chest 100 x 100mm', [['Left chest', null, 100, 100]]],
            'width and height marks' => ['Pad print 1 colour (40mm (w) x 15mm (h))', [[null, 'pad', 40, 15]]],
            'W and H after the numbers' => ['Decoration area 70mm W x 25mm H', [[null, null, 70, 25]]],
            'height first' => ['Print size H 40mm x W 30mm', [[null, null, 30, 40]]],
            'w and h with a trailing unit' => ['Max print 300w x 400h mm', [[null, null, 300, 400]]],
            'centimetres' => ['Laser engraving 6cm x 3cm', [[null, 'laser', 60, 30]]],
            'decimal centimetres' => ['Engraving area 4,5 x 2cm', [[null, 'laser', 45, 20]]],
            'inches' => ['Imprint area: 2" x 1"', [[null, null, 51, 25]]],
            'by' => ['Screen print area 100mm by 50mm', [[null, 'screen', 100, 50]]],
            'times sign' => ['Pad print 50 × 20 mm', [[null, 'pad', 50, 20]]],
            'unitless after an area word' => ['Print area: 60 x 40', [[null, null, 60, 40]]],
            'paper size' => ['DTF print up to A3', [[null, 'dtf', 297, 420]]],
            'oversize DTF is its own method' => ['Oversize DTF transfer up to 380 x 480mm', [[null, 'dtf_oversize', 380, 480]]],
            'diameter after' => ['Print area: 50mm diameter', [[null, null, 50, 50]]],
            'diameter sign, position after' => ['Ø 45mm pad print on lid', [['Lid', 'pad', 45, 45]]],
            'engraved lid' => ['Engraved on lid 50mm dia', [['Lid', 'laser', 50, 50]]],
            'pen barrel' => ['1 Colour Pad Print - Barrel 45 x 7mm', [['Barrel', 'pad', 45, 7]]],
            'embroidery' => ['Embroidery up to 100mm x 60mm (8000 stitches)', [[null, 'embroidery', 100, 60]]],
            'full colour digital' => ['Full colour digital print 80 x 50mm', [[null, 'digital', 80, 50]]],
            'uv print' => ['UV print area 60 x 60mm', [[null, 'digital', 60, 60]]],
            'deboss' => ['Deboss 40 x 20mm on the cover', [['Cover', 'deboss', 40, 20]]],
            'method from the hint only' => ['Position 1: 90 x 50mm', [[null, 'pad', 90, 50]], 'Pad Print'],
            'html' => ['<p>Print Area:&nbsp;<b>Back</b> 250 x 300mm</p>', [['Back', null, 250, 300]]],
            'sides of a bag' => ['Side 1: 250 x 300mm / Side 2: 250 x 300mm', [['Side', null, 250, 300], ['Side', null, 250, 300]]],
        ];
    }

    #[DataProvider('texts')]
    public function test_it_reads_print_areas(string $text, array $expected, ?string $hint = null): void
    {
        $got = array_map(fn ($a) => [$a['position'], $a['method'], $a['width_mm'], $a['height_mm']], DecorationAreas::parse($text, $hint));
        $this->assertSame($expected, $got, $text);
    }

    /** @return array<string, array{0: string}> */
    public static function noArea(): array
    {
        return [
            'no size' => ['Screen Print 1 Colour 1 Position'],
            'stitches are not a size' => ['Embroidery up to 8000 stitches'],
            'product size (three numbers)' => ['Carton 40 x 30 x 20 cm'],
            'bare numbers' => ['2 x 3'],
            'pack sizes' => ['Pack of 2 x 50ml'],
            'combined option without a size' => ['Front and back print'],
            'empty' => [''],
        ];
    }

    #[DataProvider('noArea')]
    public function test_it_never_guesses_a_size(string $text): void
    {
        $this->assertSame([], DecorationAreas::parse($text));
    }

    public function test_the_shape_and_view_are_given(): void
    {
        $a = DecorationAreas::parse('Back: 280 x 350mm, 60mm diameter on the lid');
        $this->assertSame(['back', 'rect'], [$a[0]['view'], $a[0]['shape']]);
        $this->assertSame(['top', 'circle'], [$a[1]['view'], $a[1]['shape']]);
        $this->assertSame('side', DecorationAreas::viewOf('Right sleeve'));
        $this->assertSame('front', DecorationAreas::viewOf('Somewhere new'));
    }

    public function test_method_names_use_the_existing_method_keys(): void
    {
        $this->assertSame('dtf', DecorationAreas::method('DTF print up to A3'));
        $this->assertSame('dtf_oversize', DecorationAreas::method('Jumbo transfer'));
        $this->assertSame('screen', DecorationAreas::method('Screen print 2 colours'));
        $this->assertSame('sublimation', DecorationAreas::method('Full colour printing (sublimation)'));
        $this->assertSame('Sublimation', DecorationAreas::methodName('sublimation'));
        $this->assertNull(DecorationAreas::method('Unbranded'));
    }
}
