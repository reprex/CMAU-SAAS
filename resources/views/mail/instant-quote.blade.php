Hi {{ $quote->client_name ? explode(' ', $quote->client_name)[0] : 'there' }},

Thanks for your quote request. Your instant quote {{ $quote->number }} is attached: ${{ number_format((float) $quote->total, 2) }} inc GST.

Prices are subject to sighting your artwork. To go ahead, or to change anything, just reply to this email or call {{ config('quotes.pdf.phone') }}.

@if($quote->shopify_invoice_url)
You can also complete your order online: {{ $quote->shopify_invoice_url }}

@endif
Kind regards,
{{ config('quotes.pdf.signed_by') }}
{{ config('quotes.pdf.signed_title') }}
{{ config('quotes.pdf.website') }}
