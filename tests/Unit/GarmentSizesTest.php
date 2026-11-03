<?php

namespace Tests\Unit;

use App\Catalogue\GarmentSizes;
use PHPUnit\Framework\TestCase;

class GarmentSizesTest extends TestCase
{
    private function l(string $text, string $want = 'L'): ?array
    {
        $c = GarmentSizes::chart($text);

        return $c ? GarmentSizes::pick($c, $want) : null;
    }

    public function test_size_and_figure_pairs(): void
    {
        $this->assertSame(['size' => 'L', 'cm' => 58.0], $this->l('Chest (1/2): XS 49, S 52, M 55, L 58, XL 61, 2XL 64'));
        $this->assertSame(['size' => 'L', 'cm' => 56.0], $this->l('Half Chest: S-50cm M-53cm L-56cm XL-59cm'));
        $this->assertSame(['size' => 'L', 'cm' => 57.0], $this->l('Body width: S: 51 M: 54 L: 57 XL: 60'));
    }

    public function test_numbers_with_the_sizes_on_another_line(): void
    {
        $this->assertSame(['size' => 'L', 'cm' => 56.0], $this->l("Size | S | M | L | XL\nChest | 50 | 53 | 56 | 59"));
        $this->assertSame(['size' => 'L', 'cm' => 55.0], $this->l("Sizes: S, M, L, XL\nHalf chest (cm) 49 52 55 58"));
    }

    public function test_inches_full_chest_and_mm(): void
    {
        $this->assertSame(['size' => 'L', 'cm' => 55.9], $this->l('1/2 Chest (inches): S 20, M 21, L 22, XL 23'));
        $this->assertSame(['size' => 'L', 'cm' => 56.0], $this->l('Chest circumference: S 100, M 106, L 112, XL 118'));
        $this->assertSame(['size' => 'L', 'cm' => 56.0], $this->l('Chest width (mm): S 500, M 530, L 560'));
    }

    public function test_womens_and_kids_sizes(): void
    {
        $this->assertSame(['size' => '14', 'cm' => 50.0], $this->l('1/2 Chest: 8 44, 10 46, 12 48, 14 50, 16 52', '14'));
        $this->assertSame(['size' => '10', 'cm' => 43.0], $this->l('Chest: 4 35, 6 38, 8 40.5, 10 43, 12 45.5', '10'));
        $this->assertSame(['size' => 'L', 'cm' => 51.0], $this->l('Chest (1/2): XS 44, S 46, M 48.5, L 51', '14'), 'a womens chart in letters uses L');
        $this->assertSame('14', GarmentSizes::targetSize('AS Colour Womens Maple Tee'));
        $this->assertSame('10', GarmentSizes::targetSize('Kids full-zip fleecy hoodie'));
        $this->assertSame('L', GarmentSizes::targetSize('Mens Staple Tee'));
    }

    public function test_nearest_size_when_l_is_missing(): void
    {
        $this->assertSame(['size' => 'XL', 'cm' => 60.0], $this->l('Chest: S 51, M 54, XL 60, 2XL 63'));
    }

    public function test_print_areas_and_plain_text_are_not_size_charts(): void
    {
        $this->assertNull(GarmentSizes::chart('Print area: left chest 90 x 90mm'));
        $this->assertNull(GarmentSizes::chart('Embroidery left chest up to 100mm wide'));
        $this->assertNull(GarmentSizes::chart('Available sizes: S, M, L, XL'));
        $this->assertNull(GarmentSizes::chart('Mid-weight 180gsm cotton tee with a regular fit.'));
    }
}
