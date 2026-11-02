@if($forStaff)
New project request {{ $r->number }} from the website. Reply by {{ $r->reply_by->format('l j F') }}.
{{ route('project-requests.show', $r) }}
@else
Hi {{ $r->first_name }},

Thanks for telling us about your project. We've got your request ({{ $r->number }}) and our team is researching the best products, suppliers and branding options for it.

We'll be back in touch by the end of {{ $r->reply_by->isTomorrow() ? 'tomorrow, ' : '' }}{{ $r->reply_by->format('l j F') }} (one working day). If anything changes in the meantime, just reply to this email or call {{ config('quotes.pdf.phone') }}.

Here's what you sent us:
@endif

What you're after
{{ $r->brief }}

@if($r->kinds)
Kind of merch: {{ implode(', ', $r->kindLabels()) }}
@endif
Quantity: {{ $r->quantity }}
@if($r->budget)
Budget: {{ $r->budget }}
@endif
@if($r->need_by)
Needed by: {{ $r->need_by->format('l j F Y') }}
@endif
@if($r->event)
Event: {{ $r->event }}
@endif

Name: {{ $r->name() }}
@if($r->organisation)
Organisation: {{ $r->organisation }}
@endif
Email: {{ $r->email }}
Phone: {{ $r->phone }}
@if($r->contact_by)
Best way to reach you: {{ $r->contact_by === 'phone' ? 'Phone' : 'Email' }}
@endif
@if($r->files)

Files: {{ implode(', ', array_column($r->files, 'name')) }}
@endif
@unless($forStaff)

Kind regards,
{{ config('quotes.pdf.signed_by') }}
{{ config('quotes.pdf.signed_title') }}
{{ config('quotes.pdf.website') }}
@endunless
