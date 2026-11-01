<?php

namespace App\Checkout;

use App\Models\WebsiteOrder;
use App\Pdf\QuotePdf;
use Illuminate\Support\Facades\Storage;

/** The website order's invoice PDF, kept on the private disk as issued. */
class InvoiceFiles
{
    public function __construct(private readonly QuotePdf $pdf) {}

    public function store(WebsiteOrder $order): string
    {
        $path = "website-orders/{$order->number}/invoice-{$order->number}.pdf";
        Storage::disk(config('website.orders.invoice_disk'))->put($path, $this->pdf->websiteInvoice($order));
        $order->forceFill(['invoice_path' => $path])->save();

        return $path;
    }

    public function pdf(WebsiteOrder $order): string
    {
        $disk = Storage::disk(config('website.orders.invoice_disk'));
        if (! $order->invoice_path || ! $disk->exists($order->invoice_path)) {
            $this->store($order);
        }

        return (string) $disk->get($order->invoice_path);
    }
}
