@php($o = $order)
Hi {{ explode(' ', trim($o->contact_name))[0] ?: 'there' }},

@if($o->isSamples())
Invoice PDF attached for your sample order {{ $o->number }}.

Please pay in full to the bank account listed on the invoice.

On receipt of payment, the factories will send your samples. {{ config('website.samples.note') }}
@else
Invoice PDF attached.

Please pay in full to the bank account listed on the invoice.

On receipt of payment, we will prepare a digital proof for your approval prior to production.
@endif

If you have any questions or need to make changes prior to payment, please contact us via return email.

Kind Regards

Scott Wilson
Owner / Director
Phone: +61 3 8456 7515, 1300 727 685, 0400 383 330
www.replicat.com.au · www.crowdmerch.com.au
