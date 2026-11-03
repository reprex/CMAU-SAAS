<?php

namespace Tests\Feature;

use App\Catalogue\TemplateFiles;
use App\Mail\QuoteMail;
use App\Models\BrandingTemplate;
use App\Models\PendingEmail;
use App\Models\Product;
use App\Models\Quote;
use App\Shopify\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Branding templates: private files, never linked, attached to the quote and invoice emails (docs/branding-templates.md). */
class TemplateEmailTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private string $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('templates');
        $this->supplier(19, 'Dex');
        Product::create(['id' => 8385, 'supplier_id' => 19, 'name' => 'Jackys Cotton Apron', 'code' => 'AP001', 'tier' => 'live']);
        $this->inbox = sys_get_temp_dir().'/tpl-inbox-'.uniqid();
        mkdir($this->inbox.'/Dex', 0777, true);
        file_put_contents($this->inbox.'/Dex/AP001 Jackys Cotton Apron.pdf', '%PDF-1.4 '.str_repeat('x', 3000));
        file_put_contents($this->inbox.'/Dex/ZZ999 Not ours.pdf', '%PDF-1.4 x');
    }

    private function quote(): Quote
    {
        $q = Quote::create(['number' => 'MR20001', 'client_name' => 'Pat Lee', 'client_email' => 'pat@example.com']);
        $q->items()->create(['supplier_id' => 19, 'product_id' => 8385, 'name' => 'Jackys Cotton Apron', 'quantity' => 100,
            'product_unit_price' => 7.40, 'decoration_unit_price' => 0.6, 'decoration_setup_price' => 60, 'decoration_name' => 'Print', 'decoration_option_id' => '8385-12daysprintpercolpos']);

        return $q;
    }

    public function test_a_reviewed_folder_is_imported_privately_and_flagged_without_a_link(): void
    {
        $this->artisan('catalogue:import-templates', ['path' => $this->inbox])
            ->expectsOutputToContain('Imported 1 templates')->expectsOutputToContain('ZZ999 Not ours.pdf')->assertSuccessful();

        $t = BrandingTemplate::query()->where('product_id', 8385)->sole();
        $this->assertSame(['file', '19/AP001 Jackys Cotton Apron.pdf', 'new'], [$t->source, $t->file_path, $t->status]); // read for positions too
        Storage::disk('templates')->assertExists('19/AP001 Jackys Cotton Apron.pdf');
        $this->assertNotNull(Product::find(8385)->range_changed_at); // goes to Shopify again with the flag

        $this->artisan('catalogue:import-templates', ['path' => $this->inbox])->expectsOutputToContain('1 already there')->assertSuccessful();

        $mf = collect(app(ProductPayload::class)->build(Product::find(8385))['input']['metafields'])->keyBy('key');
        $this->assertSame('true', $mf['hasbrandingtemplate']['value']);
        $this->assertStringNotContainsString('AP001 Jackys', json_encode($mf->all())); // no file name or address on the page
    }

    public function test_a_zip_as_uploaded_is_read_without_extracting(): void
    {
        Product::create(['id' => 8386, 'supplier_id' => 19, 'name' => 'Junior Apron', 'code' => 'AP002', 'tier' => 'live']);
        $dir = sys_get_temp_dir().'/tpl-zip-'.uniqid();
        mkdir($dir.'/19', 0777, true);
        $zip = new \ZipArchive;
        $zip->open($dir.'/19/Dex branding templates 1 of 6.zip', \ZipArchive::CREATE);
        $zip->addFromString('AP002 Junior Cotton Apron – Age 4-7.pdf', '%PDF-1.4 '.str_repeat('y', 500));
        $zip->addFromString('__MACOSX/._AP002 Junior Cotton Apron – Age 4-7.pdf', 'junk');
        $zip->close();

        $this->artisan('catalogue:import-templates', ['path' => $dir.'/19', '--supplier' => 19])->expectsOutputToContain('Imported 1 templates')->assertSuccessful();
        $t = BrandingTemplate::query()->where('product_id', 8386)->sole();
        $this->assertSame('AP002 Junior Cotton Apron – Age 4-7.pdf', $t->filename);
        $this->assertStringStartsWith('%PDF', Storage::disk('templates')->get($t->file_path));
    }

    public function test_staff_draft_the_quote_email_and_it_goes_with_the_template(): void
    {
        app(TemplateFiles::class)->import($this->inbox);
        $quote = $this->quote();
        $this->staff('sales');
        Mail::fake();

        $this->post(route('quotes.email', $quote))->assertRedirect();
        $email = PendingEmail::query()->where('quote_id', $quote->id)->sole();
        $this->assertSame(['quote', 'draft', 'pat@example.com'], [$email->kind, $email->status, $email->recipient]);
        Mail::assertNothingSent(); // a draft until staff press Send (REPSTACK principle 6)
        $this->get(route('quotes.show', $quote))->assertOk()->assertSee('Quote email')->assertSee('AP001 Jackys Cotton Apron.pdf');

        $this->post(route('quotes.email.send', [$quote, $email]))->assertRedirect();
        $this->assertSame('sent', $email->fresh()->status);
        Mail::assertSent(QuoteMail::class, function (QuoteMail $m) {
            $names = collect($m->attachments())->map(fn ($a) => $a->as)->all();

            return $m->hasTo('pat@example.com') && count($names) === 2 && $names[0] === 'MR20001.pdf'
                && str_starts_with($names[1], 'Branding template ') && str_contains($names[1], '19-AP001 Jackys Cotton Apron') && ! str_contains($names[1], 'Dex');
        });
        $this->assertNotNull($quote->fresh()->sent_at);
    }

    public function test_templates_over_the_size_limit_are_listed_instead(): void
    {
        app(TemplateFiles::class)->import($this->inbox);
        config(['quotes.templates.max_attach_mb' => 0.001]); // about 1 KB
        $mail = new QuoteMail($this->quote());

        $this->assertSame([], $mail->templates()['files']);
        $this->assertCount(1, $mail->templates()['left_out']);
        $this->assertStringContainsString('too large to attach', $mail->render());
    }

    public function test_staff_download_the_file_but_the_public_cannot(): void
    {
        app(TemplateFiles::class)->import($this->inbox);
        $t = BrandingTemplate::query()->where('product_id', 8385)->sole();

        $this->get(route('range.templates.download', [8385, $t]))->assertRedirect(); // signed out: to sign in
        $this->staff('read_only');
        $r = $this->get(route('range.templates.download', [8385, $t]))->assertOk();
        $this->assertStringStartsWith('%PDF', $r->getContent());
    }
}
