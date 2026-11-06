{{-- The website quote email (Scott, 7 Nov 2026): short; the quote PDF carries the detail. --}}
<div style="font-family:Arial,sans-serif;font-size:10pt;">
<p>Hi {{ $quote->client_name ? explode(' ', trim($quote->client_name))[0] : 'there' }},</p>
<p>Please find the quote PDF attached.</p>
<p>It contains everything you need, including detailed pricing, order instructions, mock-ups, and branding templates. If you need anything else, please reply to this email or follow the links in the quote document.</p>
@include('mail.partials.signature')
@include('mail.partials.disclaimer')
</div>
