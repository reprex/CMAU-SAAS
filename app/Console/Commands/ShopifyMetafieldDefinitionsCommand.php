<?php

namespace App\Console\Commands;

use App\Shopify\ShopifyAdmin;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create the metafield definitions for the new additive fields (docs/publisher.md)
 * on the store, so the new templates can read them and the colour family can be
 * used as a collection filter. Existing definitions are left alone.
 */
class ShopifyMetafieldDefinitionsCommand extends Command
{
    protected $signature = 'shopify:metafield-definitions';

    protected $description = 'Create metafield definitions for the publisher\'s new fields';

    public const DEFINITIONS = [
        ['PRODUCT', 'custom', 'suppliercode', 'single_line_text_field', 'Supplier code', 'Supplier name and product code, e.g. AS Colour 5001'],
        ['PRODUCT', 'custom', 'supplierskus', 'json', 'Supplier SKUs', 'Colour, size and supplier SKU for every SKU'],
        ['PRODUCT', 'custom', 'printarea', 'json', 'Logo area', 'Where the logo goes on the hero image (percent)'],
        ['PRODUCT', 'custom', 'brandingpositions', 'json', 'Branding positions', 'From the supplier template: name, view, max size, box on the picture'],
        ['PRODUCT', 'custom', 'brandingtemplate', 'url', 'Branding template', 'The supplier\'s branding template (PDF or drawing)'],
        ['PRODUCTVARIANT', 'custom', 'altimages', 'list.url', 'Other colour images', 'More pictures of this colour'],
        ['PRODUCTVARIANT', 'custom', 'colourfamily', 'list.single_line_text_field', 'Colour family', 'APPA colour families, for the colour filter'],
    ];

    public function handle(ShopifyAdmin $shopify): int
    {
        $mutation = <<<'GQL'
        mutation def($definition: MetafieldDefinitionInput!) {
          metafieldDefinitionCreate(definition: $definition) { createdDefinition { id } userErrors { field message code } }
        }
        GQL;
        foreach (self::DEFINITIONS as [$owner, $ns, $key, $type, $name, $desc]) {
            try {
                $r = $shopify->graphql($mutation, ['definition' => [
                    'ownerType' => $owner, 'namespace' => $ns, 'key' => $key, 'type' => $type, 'name' => $name, 'description' => $desc,
                    'access' => ['storefront' => 'PUBLIC_READ'],
                ]])['metafieldDefinitionCreate'] ?? [];
                $err = collect($r['userErrors'] ?? [])->pluck('message')->implode('; ');
                $this->line(sprintf('%-16s %s.%s: %s', $owner, $ns, $key, $err === '' ? 'created' : $err));
            } catch (Throwable $e) {
                $this->error("{$ns}.{$key}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
