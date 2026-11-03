@php($o = $order)
Hi {{ explode(' ', trim($o->contact_name))[0] ?: 'there' }},

@if($o->isSamples())
Thanks for your sample order {{ $o->number }}. One invoice for all your samples is attached: ${{ number_format((float) $o->total, 2) }} inc GST.

Estimated delivery: {{ $o->eta->format('l j F Y') }}. Samples from each factory ship together, so they may arrive separately.

{{ config('website.samples.note') }}

Pay by card or bank transfer from the invoice. Once you've paid, the factories send your samples.
@else
Thanks for your order {{ $o->number }}. Your invoice is attached: ${{ number_format((float) $o->total, 2) }} {{ $o->export ? 'ex GST (GST-free export)' : 'inc GST' }}.

Estimated delivery: {{ $o->eta->format('l j F Y') }}@if($o->need_by), for your date of {{ $o->need_by->format('j F Y') }}@endif.
@foreach($o->alerts ?? [] as $alert)

Please note: {{ $alert['message'] }}
@endforeach

Pay by card or bank transfer from the invoice. Once you've paid, we will provide a digital proof for your approval before proceeding with production.
@include('mail.partials.templates')
@endif

To change anything, reply to this email or use My Orders on crowdmerch.com.au before your order is dispatched.

Kind regards,
{{ config('quotes.pdf.signed_by') }}
{{ config('quotes.pdf.signed_title') }}
{{ config('quotes.pdf.phone') }} · {{ config('quotes.pdf.website') }}
