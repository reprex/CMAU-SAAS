@extends('layouts.app')
@section('title', 'Quotes')
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
<div class="row between">
  <div><div class="eyebrow">Quote desk</div><h1>Quotes</h1></div>
  <div class="row">
    <form method="get" action="{{ route('quotes.index') }}" class="row" role="search">
      <input type="search" name="q" value="{{ $search }}" placeholder="Number, email, name or #D…" aria-label="Search quotes" style="width:260px">
      <input type="hidden" name="tab" value="{{ $tab }}">
    </form>
    @if(auth()->user()->canEdit())<a class="btn primary" href="{{ route('quotes.create') }}">New quote</a>@endif
  </div>
</div>

<div class="stats">
  <div class="stat"><b>{{ $counts['requests'] }}</b><span>new quote requests</span></div>
  <div class="stat"><b>{{ $counts['open'] }}</b><span>open quotes</span></div>
  <div class="stat"><b>{{ number_format($counts['anonymous_month']) }}</b><span>anonymous wish lists, last 30 days (<a href="{{ route('drafts.index') }}">view</a>)</span></div>
  @if($counts['errors'])<div class="stat"><b style="color:var(--bad)">{{ $counts['errors'] }}</b><span>draft orders that failed to import (<a href="{{ route('drafts.index') }}">fix</a>)</span></div>@endif
</div>

<nav class="tabs" aria-label="Quote lists">
  @foreach (\App\Http\Controllers\QuoteController::TABS as $key => $label)
    <a href="{{ route('quotes.index', ['tab' => $key]) }}" class="{{ $tab === $key && $search === '' ? 'on' : '' }}">{{ $label }}</a>
  @endforeach
</nav>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Quote</th><th>Client</th><th>Items</th><th>Status</th><th>Received</th><th class="num">Total ex GST</th></tr></thead>
    <tbody>
    @forelse ($quotes as $q)
      <tr>
        <td><a href="{{ route('quotes.show', $q) }}"><b>{{ $q->number }}</b></a>@if($q->shopify_draft_name)<div class="muted small">{{ $q->shopify_draft_name }} · {{ $q->source }}</div>@endif</td>
        <td>{{ $q->displayName() }}@if($q->urgency)<div class="muted small">{{ $q->urgency }}</div>@endif</td>
        <td>{{ $q->items_count }}</td>
        <td><span class="pill {{ $q->status === 'NEW' ? 'accent' : ($q->status === 'LOST' ? 'bad' : '') }}">{{ \App\Models\Quote::STATUSES[$q->status] ?? $q->status }}</span></td>
        <td class="small">{{ $q->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</td>
        <td class="num">{{ $m($q->subtotal) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:24px 8px">Nothing here yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $quotes->links('partials.pager') }}
@endsection
