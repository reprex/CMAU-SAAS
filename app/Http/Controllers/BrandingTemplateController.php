<?php

namespace App\Http\Controllers;

use App\Catalogue\BrandingTemplates;
use App\Catalogue\TemplateFiles;
use App\Models\AuditLog;
use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Branding templates on the Range product screen (docs/branding-templates.md). */
class BrandingTemplateController extends Controller
{
    public function store(Request $request, Product $product)
    {
        if ($request->hasFile('file')) { // a reviewed PDF, kept privately for the quote and invoice emails
            $request->validate(['file' => ['file', 'mimes:pdf,png,jpg,jpeg,ai,eps', 'max:51200']]);
            $f = $request->file('file');
            $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '', $f->getClientOriginalName())) ?: 'template.pdf';
            $path = $product->supplier_id.'/'.$product->id.'-'.$name;
            Storage::disk(TemplateFiles::DISK)->putFileAs(dirname($path), $f, basename($path));
            $t = BrandingTemplate::query()->updateOrCreate(['product_id' => $product->id, 'filename' => $name], ['url' => 'file:'.$path, 'file_path' => $path, 'bytes' => $f->getSize(), 'source' => 'file', 'status' => 'new']);
            $product->forceFill(['range_changed_at' => now()])->save();
            AuditLog::record('range.template_uploaded', $product, ['file' => $name]);

            return back()->with('status', 'Template uploaded. It goes with quote and invoice emails for this product, and is read for branding positions within a few minutes.');
        }
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

    /** Staff download (signed in only): the private file, else a copy fetched from the template's address. */
    public function download(Product $product, BrandingTemplate $template, TemplateFiles $files)
    {
        abort_unless($template->product_id === $product->id, 404);
        $data = $files->bytes($template);
        abort_if($data === null, 404, 'The template file is not available.');
        $name = $files->attachName($template, $product);

        return response($data, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.addslashes($name).'"', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Product $product, BrandingTemplate $template)
    {
        abort_unless($template->product_id === $product->id, 404);
        $template->positions()->delete();
        if ($template->file_path) {
            Storage::disk(TemplateFiles::DISK)->delete($template->file_path);
        }
        $template->delete();

        return back()->with('status', 'Template removed.');
    }
}
