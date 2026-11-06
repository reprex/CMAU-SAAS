Hi {{ $po->supplier?->orders_contact ? explode(' ', $po->supplier->orders_contact)[0] : 'team' }},

Please find our purchase order {{ $po->number }} attached.

BLIND SHIP, always: no supplier name, logo, invoice, packing slip or prices on or in the cartons. Show the sender as CrowdMerch.
@if($po->despatch_by)
Despatch by: {{ $po->despatch_by->format('l j F Y') }}
@endif
@if($po->need_by)
The client needs it by: {{ $po->need_by->format('l j F Y') }}@if($po->event) for their event ({{ $po->event }})@endif

@endif
Client artwork: {{ $po->artworkUrl() }}

Please reply with your confirmation and despatch date, and the freight cost to the delivery address unless it's in your pricing.

Kind regards,
{{ config('quotes.pdf.signed_by') }}
{{ config('quotes.pdf.signed_title') }}
{{ config('quotes.pdf.phone') }}
