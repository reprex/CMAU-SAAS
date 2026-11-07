Hi {{ $quote->client_name ? explode(' ', trim($quote->client_name))[0] : 'there' }},

Please find the quote PDF attached.

It contains everything you need, including {{ $contains }}.

@if($embroidery ?? null)
{{ $embroidery }}

@endif
If you need anything else, please reply to this email or follow the links in the quote document.

Kind Regards

Scott Wilson
Owner / Director
Phone: +61 3 8456 7515, 1300 727 685, 0400 383 330
www.replicat.com.au · www.crowdmerch.com.au
