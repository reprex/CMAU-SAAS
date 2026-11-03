<?php

namespace App\Console\Commands;

use App\Catalogue\TemplateFiles;
use Illuminate\Console\Command;

/**
 * Import reviewed supplier branding templates from a folder on the server (docs/branding-templates.md).
 * Upload the extracted folder with cPanel File Manager (e.g. into ~/saas-app/storage/app/template-inbox/Dex),
 * then run this. Files are matched to products by the code at the start of the name and kept privately.
 */
class CatalogueImportTemplatesCommand extends Command
{
    protected $signature = 'catalogue:import-templates {path : Folder of template files, searched with its subfolders} {--supplier= : Promodata supplier id, when codes could belong to two suppliers}';

    protected $description = 'Import branding template files (named "<code> <name>.pdf") as private files for the quote and invoice emails';

    public function handle(TemplateFiles $files): int
    {
        $path = (string) $this->argument('path');
        if (! is_dir($path)) {
            $this->error("No folder at {$path}");

            return self::FAILURE;
        }
        $r = $files->import($path, $this->option('supplier') ? (int) $this->option('supplier') : null, fn ($m) => $this->line($m));
        $this->info("Imported {$r['imported']} templates; {$r['same']} already there.");
        if ($r['unmatched']) {
            $this->warn(count($r['unmatched']).' files matched no product: '.implode(', ', array_slice($r['unmatched'], 0, 20)).(count($r['unmatched']) > 20 ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
