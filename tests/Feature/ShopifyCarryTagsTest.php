<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Shopify\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifyCarryTagsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function pen(): Product
    {
        $this->supplier(3, 'Trends');
        $p = $this->catalogueProduct(2568, 3, 'Borg Pen', '110811');
        $p->update(['promodata_type_name' => 'Writing > Plastic Pens', 'hydrated_at' => now()]);

        return $p->fresh();
    }

    private function export(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ct').'.csv';
        $h = fopen($path, 'w');
        fputcsv($h, ['Handle', 'Title', 'Tags', 'Metafield: custom.tagcategory [single_line_text_field]', 'Metafield: custom.tagspecial [single_line_text_field]',
            'Metafield: custom.tagcollection [single_line_text_field]', 'Metafield: custom.essentialssubcat [single_line_text_field]', 'Metafield: custom.urgentsubcat [single_line_text_field]',
            'Metafield: custom.undermoqtext [single_line_text_field]', 'Metafield: my_fields.pdpcollectionname [single_line_text_field]', 'Metafield:custom.mastersortorder [number_integer]', 'Option1 Value'], ',', '"', '');
        fputcsv($h, ['2568', 'Borg Pen [3-110811]', 'style, trends, plasticpens, urgent', 'any,writing', 'any', 'any,urgent', 'Writing', 'Plastic Pens', 'Small runs +$20', 'plasticpens', '20410', 'Black'], ',', '"', '');
        fputcsv($h, ['2568', '', '', '', '', '', '', '', '', '', '', 'Blue'], ',', '"', '');
        fputcsv($h, ['999', 'Not ours', 'x', 'any,bags', '', '', '', '', '', '', '5', ''], ',', '"', '');
        fclose($h);

        return $path;
    }

    private function metafields(Product $p): array
    {
        return collect(app(ProductPayload::class)->build($p->fresh())['input']['metafields'])->mapWithKeys(fn ($m) => [$m['namespace'].'.'.$m['key'] => $m['value']])->all();
    }

    public function test_without_carried_tags_values_come_from_the_promodata_type(): void
    {
        $m = $this->metafields($this->pen());

        $this->assertSame('any,writing', $m['custom.tagcategory']);
        $this->assertSame('Writing', $m['custom.essentialssubcat']);
        $this->assertSame('Plastic Pens', $m['custom.urgentsubcat']);
        $this->assertSame('plasticpens', $m['my_fields.pdpcollectionname']);
        $this->assertSame('any,', $m['custom.tagcollection']);
        $this->assertArrayNotHasKey('custom.undermoqtext', $m);
    }

    public function test_report_then_apply_carries_the_live_values(): void
    {
        Http::fake();
        $p = $this->pen();
        $file = $this->export();

        $this->artisan('shopify:carry-tags', ['file' => $file])->expectsOutputToContain('Report only')->assertSuccessful();
        $this->assertNull($p->fresh()->overrideValue('carried'));

        $this->artisan('shopify:carry-tags', ['file' => $file, '--apply' => true])->assertSuccessful();
        Http::assertNothingSent();

        $m = $this->metafields($p);
        $this->assertSame('any,urgent', $m['custom.tagcollection']);
        $this->assertSame('Small runs +$20', $m['custom.undermoqtext']);
        $this->assertSame('20410', $m['custom.mastersortorder']);
        $tags = app(ProductPayload::class)->build($p->fresh())['input']['tags'];
        $this->assertContains('urgent', $tags);
        $this->assertSame(1, collect($tags)->filter(fn ($t) => mb_strtolower($t) === 'plasticpens')->count(), 'no duplicate tags');
    }

    public function test_a_staff_sort_order_is_kept(): void
    {
        $p = $this->pen();
        $p->override()->updateOrCreate(['product_id' => $p->id], ['data' => ['master_sort_order' => 5]]);

        $this->artisan('shopify:carry-tags', ['file' => $this->export(), '--apply' => true])->assertSuccessful();

        $this->assertSame(5, (int) $p->fresh()->overrideValue('master_sort_order'));
        $this->assertSame('any,urgent', $p->fresh()->overrideValue('carried.tagcollection'));
    }

    public function test_a_second_file_adds_to_what_the_first_carried(): void
    {
        $p = $this->pen();
        $this->artisan('shopify:carry-tags', ['file' => $this->export(), '--apply' => true])->assertSuccessful();
        $path = tempnam(sys_get_temp_dir(), 'ct').'.csv';
        file_put_contents($path, "Handle,Title,Tags\n2568,Borg Pen,\"style, trends, newtag\"\n");

        $this->artisan('shopify:carry-tags', ['file' => $path, '--apply' => true])->assertSuccessful();

        $this->assertSame('any,urgent', $p->fresh()->overrideValue('carried.tagcollection'), 'metafields from the first file kept');
        $this->assertContains('newtag', $p->fresh()->overrideValue('carried.tags'), 'tags from the second file');
    }

    public function test_live_colour_spelling_is_kept_so_variants_keep_their_ids(): void
    {
        $p = $this->pen();
        $p->colours()->create(['name' => 'Natural/Black', 'image_url' => 'https://x/n.jpg', 'appa_colours' => ['Black'], 'position' => 0]);
        app(ImageTagger::class)->apply($p->fresh());
        $path = tempnam(sys_get_temp_dir(), 'ct').'.csv';
        file_put_contents($path, "Handle,Title,Tags,Option1 Name,Option1 Value\n2568,Borg Pen,style,Color,Natural/black\n");

        $this->artisan('shopify:carry-tags', ['file' => $path, '--apply' => true])->assertSuccessful();

        $this->assertSame([['label' => 'Natural/black', 'sku' => '2568-naturalblack']], app(ProductPayload::class)->variants($p->fresh()));
    }
}
