<?php

namespace Tests\Support;

use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\Supplier;
use App\Models\User;

trait QuoteFixtures
{
    protected function staff(string $role = 'sales'): User
    {
        $user = User::factory()->create(['role' => $role, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user)->withSession(['two_factor_passed' => $user->id]);

        return $user;
    }

    protected function supplier(int $id, string $name, array $freight = []): Supplier
    {
        return Supplier::create(['id' => $id, 'name' => $name] + $freight);
    }

    /**
     * A catalogue product with an unbranded option and one decoration option.
     * Prices: unbranded 25:5.00,50:4.21,100:3.90 (cost 25:3.00,50:2.60,100:2.40);
     * decoration 25:4.50,50:4.13,100:3.80 setup 45 (cost 25:2.50,50:2.20,100:2.00, setup 30).
     */
    protected function catalogueProduct(int $id, int $supplierId, string $name = 'Heavy brushed cotton cap', string $code = 'CH35'): Product
    {
        $product = Product::create(['id' => $id, 'supplier_id' => $supplierId, 'name' => $name, 'code' => $code, 'tier' => 'live', 'hero_image_url' => 'https://example.com/cap.png']);
        PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-undecorated", 'code' => 'undecorated', 'kind' => 'UNDEC', 'type_code' => 12, 'description' => 'Unbranded',
            'price_string' => '25:5.00,50:4.21,100:3.90', 'cost_string' => '25:3.00,50:2.60,100:2.40', 'setup_price' => 0, 'setup_cost' => 0]);
        PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-embroideryto5kstitches", 'code' => 'embroideryto5kstitches', 'kind' => 'PDADEC', 'type_code' => 10,
            'description' => 'Embroidery to 5K stitches', 'price_string' => '25:4.50,50:4.13,100:3.80', 'cost_string' => '25:2.50,50:2.20,100:2.00', 'setup_price' => 45, 'setup_cost' => 30]);
        ProductWebPrice::create(['product_id' => $id, 'decoration_product_id' => $id, 'undecorated_option_id' => "{$id}-undecorated", 'default_decoration_option_id' => "{$id}-embroideryto5kstitches"]);

        return $product;
    }

    /** A draft order as the DraftOrdersLocal export has it (PascalCase). */
    protected function exportDraft(int $id = 1628107178204, ?string $email = 'client@example.com', array $lines = []): array
    {
        $lines = $lines ?: [[
            'Name' => 'Heavy brushed cotton cap buckle on back [53-CH35] - Navy',
            'Title' => 'Heavy brushed cotton cap buckle on back [53-CH35]',
            'Sku' => '25384-navy', 'Price' => '8.79', 'Quantity' => 99, 'ProductVariantId' => 52080813441244,
            'Custom' => false, 'ProductVariantTitle' => 'Navy',
            'Properties' => [
                ['Name' => 'decsetup', 'Value' => '45.00'], ['Name' => 'decunit', 'Value' => '4.13'],
                ['Name' => 'undecsetup', 'Value' => '0.00'], ['Name' => 'undecunit', 'Value' => '4.21'],
                ['Name' => 'decdesc', 'Value' => 'Embroidery to 5K stitches'], ['Name' => 'decID', 'Value' => '25384-embroideryto5kstitches'],
            ],
        ]];

        return [
            'Id' => $id, 'Name' => '#D4021', 'Status' => 'Open', 'Email' => $email, 'Customer_Email' => $email,
            'Customer_FirstName' => 'Pat', 'Customer_LastName' => 'Client',
            'ShippingAddress_City' => 'Brunswick', 'ShippingAddress_ProvinceCode' => 'VIC', 'ShippingAddress_Zip' => '3056', 'ShippingAddress_Address1' => '1 Test St',
            'Note' => 'Urgency: I just need a quote for now Notes: Please quote 100 navy caps',
            'LineItems' => json_encode($lines), 'CreatedAt' => '2026-10-20 11:56:58', 'UpdatedAt' => '2026-10-20 11:56:59',
        ];
    }
}
