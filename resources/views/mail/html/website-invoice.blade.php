{{-- The website invoice email (Scott, 7 Nov 2026): short; the invoice PDF carries the detail. --}}
@php($o = $order)
<div style="font-family:Arial,sans-serif;font-size:10pt;">
<p>Hi {{ explode(' ', trim($o->contact_name))[0] ?: 'there' }},</p>
@if($o->isSamples())
<p>Invoice PDF attached for your sample order {{ $o->number }}.</p>
<p>Please pay in full to the bank account listed on the invoice.</p>
<p>On receipt of payment, the factories will send your samples. {{ config('website.samples.note') }}</p>
@else
<p>Invoice PDF attached.</p>
<p>Please pay in full to the bank account listed on the invoice.</p>
@if($embroidery ?? null)<p>{{ $embroidery }}</p>
@endif
<p>On receipt of payment, we will prepare a digital proof for your approval prior to production.</p>
@endif
<p>If you have any questions or need to make changes prior to payment, please contact us via return email.</p>
@include('mail.partials.signature')
@include('mail.partials.disclaimer')
</div>
