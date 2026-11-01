@php($o = $change->order)
Change request for website order {{ $o->number }} ({{ $o->stageName() }}).

From: {{ $o->contact_name }}{{ $o->organisation ? ', '.$o->organisation : '' }} · {{ $o->email }} · {{ $o->phone }}
Need by: {{ $o->need_by->format('j M Y') }} · estimated delivery {{ $o->eta->format('j M Y') }}

{{ $change->message }}

Open it under Website orders: {{ route('website-orders.show', $o) }}
