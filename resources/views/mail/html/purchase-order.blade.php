{{-- The purchase order email (Scott, 7 Nov 2026): short; the PO PDF carries the detail. --}}
<div style="font-family:Arial,sans-serif;font-size:10pt;">
<p>Hi {{ $po->supplier?->orders_contact ? explode(' ', trim($po->supplier->orders_contact))[0] : 'there' }},</p>
<p>Please find our purchase order attached. Let us know if any issues.</p>
<p>PS: If you have an API that can accept purchase orders automatically, please let us know. We'd be happy to integrate with our systems on future orders.</p>
@include('mail.partials.signature')
@include('mail.partials.disclaimer')
</div>
