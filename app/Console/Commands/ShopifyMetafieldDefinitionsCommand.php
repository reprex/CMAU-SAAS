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

    /**
     * Fields no longer sent: their definition and every product's value are deleted from the store.
     * custom.brandingtemplate was the template link (Scott, 3 Nov 2026: no template links where a bot can read them).
     */
    public const REMOVED = [['PRODUCT', 'custom', 'brandingtemplate']];

    public const DEFINITIONS = [
        ['PRODUCT', 'custom', 'suppliercode', 'single_line_text_field', 'Supplier code', 'Supplier name and product code, e.g. AS Colour 5001'],
        ['PRODUCT', 'custom', 'supplierskus', 'json', 'Supplier SKUs', 'Colour, size and supplier SKU for every SKU'],
        ['PRODUCT', 'custom', 'ourcode', 'single_line_text_field', 'Our code', 'CrowdMerch code SupplierID-Code, e.g. 3-104743 (shown under the title)'],
        ['PRODUCT', 'custom', 'leadtimedays', 'number_integer', 'Lead time (working days)', 'Fastest production lead time from the supplier, in working days (shown beside the sizes)'],
        ['PRODUCT', 'custom', 'leadtimes', 'json', 'Lead times', 'Working days per decoration and the rules to count delivery (extra decorations, Christmas rush, transit, holidays, closures); docs/lead-times.md'],
        ['PRODUCT', 'custom', 'supplierproductcode', 'single_line_text_field', 'Supplier product code', "The supplier's product code alone, e.g. 5001 (title chip; never the supplier name)"],
        ['PRODUCT', 'custom', 'printarea', 'json', 'Logo area', 'Where the logo goes on the hero image (percent)'],
        ['PRODUCT', 'custom', 'brandingpositions', 'json', 'Branding positions', 'From the supplier template, else Promodata decoration print areas, else the type default: name, view, methods, max size, box on the picture'],
        ['PRODUCT', 'custom', 'mockupphotos', 'json', 'Mockup photos', 'Per colour and side: whether its photo can carry a logo mockup (ok, skip, or example branding to paint over first)'],
        ['PRODUCT', 'custom', 'mockupgarment', 'single_line_text_field', 'Mockup garment size', 'Size chart chest width for the mockup, size:cm laid flat, e.g. L:56'],
        ['PRODUCT', 'custom', 'sizeguide', 'json', 'Size guide', 'Fit, measurements per size (cm) and the supplier size chart picture or link'],
        ['PRODUCT', 'custom', 'hasbrandingtemplate', 'boolean', 'Branding template available', 'A supplier branding template is available: sent with the quote or invoice email, never linked (docs/branding-templates.md)'],
        ['PRODUCT', 'custom', 'options', 'json', 'Product options', 'Options that are not branding: clips, packaging, embroidery extras, personalisation, samples (docs/product-options.md)'],
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

        foreach (self::REMOVED as [$owner, $ns, $key]) {
            try {
                $id = $shopify->graphql('query($o: MetafieldOwnerType!, $ns: String!, $k: String!) { metafieldDefinitions(first: 1, ownerType: $o, namespace: $ns, key: $k) { nodes { id } } }',
                    ['o' => $owner, 'ns' => $ns, 'k' => $key])['metafieldDefinitions']['nodes'][0]['id'] ?? null;
                if (! $id) {
                    $this->line(sprintf('%-16s %s.%s: not on the store', $owner, $ns, $key));

                    continue;
                }
                $r = $shopify->graphql('mutation($id: ID!) { metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: true) { deletedDefinitionId userErrors { message } } }', ['id' => $id])['metafieldDefinitionDelete'] ?? [];
                $err = collect($r['userErrors'] ?? [])->pluck('message')->implode('; ');
                $this->line(sprintf('%-16s %s.%s: %s', $owner, $ns, $key, $err === '' ? 'deleted with every product\'s value' : $err));
            } catch (Throwable $e) {
                $this->error("{$ns}.{$key}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
