Hi {{ $po->supplier?->orders_contact ? explode(' ', $po->supplier->orders_contact)[0] : 'team' }},

Please find our purchase order {{ $po->number }} attached.

Please blind ship to the address on the PO: show the sender as Replicat / CrowdMerch, {{ config('quotes.pdf.phone') }}, and please do not put any supplier documentation or identification with the goods.
@if($po->despatch_by)
Despatch by: {{ $po->despatch_by->format('l j F Y') }}
@endif
@if($po->need_by)
The client needs it by: {{ $po->need_by->format('l j F Y') }}@if($po->event) for their event ({{ $po->event }})@endif

@endif
Client artwork: {{ $po->artworkUrl() }}

Please let us know before proofing if this date is a problem, and reply with your confirmation and despatch date, and the freight cost to the delivery address unless it's in your pricing. Please let us know if any prices have changed.

Kind regards,
{{ config('quotes.pdf.signed_by') }}
{{ config('quotes.pdf.signed_title') }}
{{ config('quotes.pdf.phone') }}
