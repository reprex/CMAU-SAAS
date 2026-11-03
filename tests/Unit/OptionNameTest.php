<?php

namespace Tests\Unit;

use App\Shopify\ProductPayload;
use PHPUnit\Framework\TestCase;

class OptionNameTest extends TestCase
{
    public function test_colour_names_are_made_safe_for_shopify(): void
    {
        $used = [];
        $this->assertSame('Navy/White', ProductPayload::optionName('Navy/White', 1, $used)); // ordinary names unchanged
        $this->assertSame('Black', ProductPayload::optionName("Black\u{00A0}\t", 2, $used));
        $this->assertSame('Red Gold', ProductPayload::optionName("Red\u{200B}  Gold", 3, $used));
        $this->assertSame('Colour 4', ProductPayload::optionName('  ', 4, $used));
        $this->assertSame('Colour 5', ProductPayload::optionName('-', 5, $used));
        $this->assertSame('black 2', ProductPayload::optionName('black', 6, $used)); // a repeat
        $this->assertSame('Sky Blue', ProductPayload::optionName('Sky Blue.png', 7, $used));
        $this->assertSame(200, mb_strlen(ProductPayload::optionName(str_repeat('a', 300), 8, $used)));
    }
}
