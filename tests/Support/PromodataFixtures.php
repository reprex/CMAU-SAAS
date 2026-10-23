<?php

namespace Tests\Support;

/**
 * Synthetic Promodata payloads in the shape TOD's modApi.bas consumed.
 * Values are invented for tests; they are not real supplier data.
 */
class PromodataFixtures
{
    /** @return array<string, mixed> */
    public static function product(int $id = 1001, array $overrides = []): array
    {
        $product = [
            'meta' => [
                'id' => $id,
                'country' => 'AU',
                'data_source' => 'supplier',
                'discontinued' => false,
                'can_check_stock' => true,
                'discontinued_at' => null,
                'first_listed_at' => '2025-01-10T09:00:00+11:00',
                'last_changed_at' => '2026-10-20T14:30:00+11:00',
                'prices_changed_at' => '2026-10-01T08:00:00+10:00',
                'discontinued_reason' => null,
            ],
            'supplier' => [
                'supplier' => 'Test Supplier Pty Ltd',
                'supplier_id' => 3,
                'supplier_country' => 'AU',
                'supplier_appa_member_number' => '12345',
            ],
            'overview' => [
                'hero_image' => "https://res.cloudinary.com/promodata/test/{$id}-hero.jpg",
            ],
            'product' => [
                'name' => 'Test Pen White Barrel',
                'code' => "TP{$id}",
                'description' => "Retractable pen.\nBlack ink.",
                'supplier_brand' => 'TESTBRAND',
                'supplier_label' => null,
                'supplier_catalogue' => '2026 Catalogue',
                'supplier_website_page' => "https://supplier.example/products/{$id}",
                'categorisation' => [
                    'supplier_category' => 'Writing',
                    'supplier_subcategory' => 'Plastic Pens',
                    'appa_product_type' => ['Pens' => ['0' => 'Plastic Pens']],
                    'promodata_attributes' => ['Material: Plastic (non-specific)', 'Ink Colour: Black'],
                    'promodata_product_type' => [
                        'type_id' => 'plasticpens',
                        'type_name' => 'plasticpens',
                        'type_group_id' => 'writing',
                        'type_name_text' => 'Plastic Pens',
                        'type_group_name' => 'Writing',
                    ],
                ],
                'details' => [
                    ['name' => 'Dimensions', 'detail' => '140mm x 10mm'],
                ],
                'images' => [
                    "https://res.cloudinary.com/promodata/test/{$id}-hero.jpg",
                    "https://res.cloudinary.com/promodata/test/{$id}-side.jpg",
                ],
                'colours' => [
                    'supplier_text' => ['0' => ['name' => 'Barrel', 'detail' => 'White']],
                    // Keyed objects, as Promodata sometimes returns them.
                    'list' => [
                        '0' => [
                            'for' => 'Trim',
                            'name' => 'Black',
                            'image' => "https://res.cloudinary.com/promodata/test/{$id}-black.jpg",
                            'swatch' => [],
                            'colours' => ['0' => 'Black'],
                            'appa_colours' => ['0' => 'Black'],
                        ],
                        '1' => [
                            'for' => 'Trim',
                            'name' => 'Red',
                            'image' => "https://res.cloudinary.com/promodata/test/{$id}-red.jpg",
                            'swatch' => [],
                            'colours' => ['0' => 'Red'],
                            'appa_colours' => ['0' => 'Red'],
                        ],
                    ],
                ],
                'prices' => [
                    'price_groups' => [
                        [
                            'base_price' => [
                                'key' => 'base-1',
                                'type' => 'Decoration',
                                'setup' => 60,
                                'currency' => 'AUD',
                                'lead_time' => '10 working days',
                                'description' => 'Pad Print 1 Colour 1 Position',
                                'undecorated' => false,
                                'indent' => false,
                                'promodata_decoration' => 'Pad Print',
                                'tags' => ['pad'],
                                'price_breaks' => [
                                    ['qty' => 500, 'price' => 0.45],
                                    ['qty' => 250, 'price' => 0.52],
                                    ['qty' => 1000, 'price' => 0.41],
                                ],
                            ],
                            'additions' => [
                                [
                                    'key' => 'add-1',
                                    'type' => 'Addition',
                                    'setup' => 60,
                                    'currency' => 'AUD',
                                    'lead_time' => null,
                                    'description' => 'Extra colour/position',
                                    'undecorated' => false,
                                    'indent' => false,
                                    'promodata_decoration' => 'Pad Print',
                                    'tags' => [],
                                    'price_breaks' => [['qty' => 250, 'price' => 0.12]],
                                ],
                            ],
                        ],
                    ],
                    'price_tags' => ['Decoration' => ['pad']],
                ],
            ],
        ];

        return array_replace_recursive($product, $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public static function page(array $products, int $totalPages = 1): array
    {
        return ['total_pages' => $totalPages, 'data' => $products];
    }
}
