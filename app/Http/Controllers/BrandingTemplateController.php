<?php

namespace App\Http\Controllers;

use App\Catalogue\BrandingTemplates;
use App\Models\AuditLog;
use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Http\Request;

/** Branding templates on the Range product screen (docs/branding-templates.md). */
class BrandingTemplateController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate(['url' => ['required', 'url:https', 'max:1024']]);
        $t = BrandingTemplate::query()->firstOrCreate(['product_id' => $product->id, 'url' => $data['url']], ['source' => 'staff']);
        AuditLog::record('range.template_added', $product, ['url' => $t->url]);

        return back()->with('status', 'Template added. Read it now, or it is read automatically within a few minutes.');
    }

    public function read(Product $product, BrandingTemplate $template, BrandingTemplates $templates)
    {
        abort_unless($template->product_id === $product->id, 404);
        $n = $templates->read($template);
        $template->refresh();

        return back()->with('status', $template->status === 'failed' ? 'Could not read the template: '.$template->message : "Template read: {$n} branding positions.");
    }

    public function destroy(Product $product, BrandingTemplate $template)
    {
        abort_unless($template->product_id === $product->id, 404);
        $template->positions()->delete();
        $template->delete();

        return back()->with('status', 'Template removed.');
    }
}
