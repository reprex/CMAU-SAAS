<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Shopify\ProductPayload;
use App\Support\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifyVariantMatchTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    private function tee(): Product
    {
        $this->supplier(88, 'AS Colour');
        $p = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        $p->update(['promodata_type_name' => 'T-Shirts', 'hero_image_url' => self::CDN.'5001_main.jpg', 'hydrated_at' => now()]);
        $p->images()->delete();
        $p->images()->create(['url' => self::CDN.'5001_main.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->colours()->create(['name' => 'Black', 'image_url' => self::CDN.'black.jpg', 'appa_colours' => ['Black'], 'position' => 0]);
        $p->colours()->create(['name' => 'Navy / White', 'image_url' => self::CDN.'navy.jpg', 'appa_colours' => ['Blue'], 'position' => 1]);
        $p->colours()->create(['name' => 'Bone', 'image_url' => self::CDN.'bone.jpg', 'appa_colours' => ['White'], 'position' => 2]);
        app(ImageTagger::class)->apply($p);

        return $p->fresh();
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vm').'.csv';
        $h = fopen($path, 'w');
        fputcsv($h, ['Handle', 'Title', 'Status', 'Option1 Value', 'Variant SKU', 'Variant ID'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ',', '"', '');
        }
        fclose($h);

        return $path;
    }

    public function test_variants_and_skus_match_the_payload(): void
    {
        $p = $this->tee();
        $built = collect(app(ProductPayload::class)->build($p)['input']['variants'])
            ->map(fn ($v) => ['label' => $v['optionValues'][0]['name'], 'sku' => $v['inventoryItem']['sku']])->all();

        $this->assertSame($built, app(ProductPayload::class)->variants($p));
        $this->assertSame('43446-navywhite', ProductPayload::sku(43446, 'Navy / White'));
    }

    public function test_report_groups_matches_renames_drops_and_live_only_products(): void
    {
        Storage::fake('local');
        Http::fake();
        $this->tee();
        $file = $this->csv([
            ['43446', 'Staple Tee [88-5001]', 'Active', 'Black', '43446-black', '111'],
            ['43446', '', '', 'Navy/White', '43446-navywhite', '112'],
            ['43446', '', '', 'Red', '43446-red', '113'],
            ['999', 'Old Mug [1-M1]', 'Active', 'White', '999-white', '201'],
            ['998', 'Gone Cap [1-C1]', 'Archived', 'Red', '998-red', '202'],
            ['50001-1', 'New Mug [88-M9]', 'Active', 'White', '50001-1-white', '301'],
        ]);
        $this->catalogueProduct(50001, 88, 'New Mug', 'M9');

        $this->artisan('shopify:variant-match', ['file' => $file])
            ->expectsOutputToContain('"Navy/White" -> "Navy / White"')
            ->expectsOutputToContain('43446: "Red"')
            ->expectsOutputToContain('999 Old Mug')
            ->expectsOutputToContain('live 50001-1 = SaaS 50001 [88-M9]')
            ->assertSuccessful();

        Http::assertNothingSent();
        $out = collect(Storage::disk('local')->files('variant-match'))->first();
        $rows = iterator_to_array(SheetReader::rows(Storage::disk('local')->path($out)));
        $by = collect($rows)->groupBy('result')->map->count()->all();
        $this->assertEqualsCanonicalizing(['same' => 1, 'renamed' => 1, 'new' => 1, 'dropped' => 1, 'live only' => 2, 'live only (archived)' => 1, 'new product' => 1], $by);
        $this->assertSame('111', collect($rows)->firstWhere('saas colour', 'Black')['live variant id']);
    }

    public function test_reads_an_xlsx_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vm').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Handle</t></si><si><t>Option1 Value</t></si><si><t>Black</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2"><v>43446</v></c><c r="B2" t="s"><v>2</v></c></row><row r="3"><c r="B3" t="inlineStr"><is><t>Navy</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        $rows = iterator_to_array(SheetReader::rows($path, 'Products'));

        $this->assertSame([['Handle' => '43446', 'Option1 Value' => 'Black'], ['Handle' => '', 'Option1 Value' => 'Navy']], $rows);
    }
}
