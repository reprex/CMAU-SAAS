<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Pdf\QuotePdf;
use App\Quotes\QuoteNumbers;

class PdfController extends Controller
{
    public function __construct(private readonly QuotePdf $pdf) {}

    public function quote(Quote $quote)
    {
        AuditLog::record('quote.pdf', $quote);

        return $this->inline($this->pdf->quote($quote), $quote->number.($quote->revision > 1 ? '-'.$quote->revision : '').'.pdf');
    }

    public function comparison(Quote $quote)
    {
        AuditLog::record('quote.comparison_pdf', $quote);

        return $this->inline($this->pdf->comparison($quote), QuoteNumbers::comparisonNumber($quote->number).'.pdf');
    }

    private function inline(string $bytes, string $name)
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
