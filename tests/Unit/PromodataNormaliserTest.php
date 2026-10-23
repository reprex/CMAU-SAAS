<?php

namespace Tests\Unit;

use App\Sources\Promodata\PromodataNormaliser;
use Tests\Support\PromodataFixtures;
use Tests\TestCase;

class PromodataNormaliserTest extends TestCase
{
    public function test_it_maps_a_promodata_product(): void
    {
        $p = (new PromodataNormaliser)->normalise(PromodataFixtures::product(2568));

        $this->assertSame('promodata', $p->source);
        $this->assertSame(2568, $p->id);
        $this->assertSame(3, $p->supplierId);
        $this->assertSame('Test Pen White Barrel', $p->name);
        $this->assertSame('TP2568', $p->code);
        $this->assertSame('plasticpens', $p->promodataTypeId);
        $this->assertSame('Plastic Pens', $p->promodataTypeName);
        $this->assertSame('Writing', $p->supplierCategory);
        $this->assertSame([['name' => 'Material', 'value' => 'Plastic (non-specific)'], ['name' => 'Ink Colour', 'value' => 'Black']], $p->promodataAttributes);
        $this->assertSame('2026-10-20 14:30', $p->lastChangedAt->format('Y-m-d H:i'));
    }

    public function test_keyed_object_collections_become_lists(): void
    {
        $p = (new PromodataNormaliser)->normalise(PromodataFixtures::product());

        $this->assertCount(2, $p->colours);
        $this->assertSame('Black', $p->colours[0]['name']);
        $this->assertSame(['Black'], $p->colours[0]['supplier_colours']);
        $this->assertSame(2, $p->colourCount());
    }

    public function test_price_breaks_are_sorted_and_lowest_base_cost_found(): void
    {
        $p = (new PromodataNormaliser)->normalise(PromodataFixtures::product());

        $base = $p->priceGroups[0]['base'];
        $this->assertSame([250, 500, 1000], array_column($base['breaks'], 'qty'));
        $this->assertSame(60.0, $base['setup']);
        $this->assertCount(1, $p->priceGroups[0]['additions']);

        // Additions are ignored: lowest base unit cost and smallest quantity.
        $this->assertSame([0.41, 250], $p->lowestBaseCost());
    }

    public function test_content_hash_changes_when_the_payload_changes(): void
    {
        $n = new PromodataNormaliser;
        $a = $n->normalise(PromodataFixtures::product());
        $b = $n->normalise(PromodataFixtures::product(overrides: ['product' => ['name' => 'Renamed']]));

        $this->assertNotSame($a->contentHash, $b->contentHash);
        $this->assertSame($a->contentHash, $n->normalise(PromodataFixtures::product())->contentHash);
    }
}
