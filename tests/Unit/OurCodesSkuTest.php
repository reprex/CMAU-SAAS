<?php

namespace Tests\Unit;

use App\Catalogue\OurCodes;
use PHPUnit\Framework\TestCase;

class OurCodesSkuTest extends TestCase
{
    public function test_our_sku(): void
    {
        $this->assertSame('7-5001-BLAC-M', OurCodes::sku('7-5001', 'Black', 'M'));
        $this->assertSame('7-5001-LIGR', OurCodes::sku('7-5001', 'Light Grey Marle'));
        $this->assertSame('7-5001-NATU', OurCodes::sku('7-5001', 'Natural', 'One size'), 'no size part for a product with no sizes');
        $this->assertNull(OurCodes::sku(null, 'Black'));
    }
}
