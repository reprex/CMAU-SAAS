@extends('layouts.app')
@section('title', 'Website orders')
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
@php($stages = \App\Models\WebsiteOrder::STAGES)
<div class="row between">
  <div><div class="eyebrow">Website checkout</div><h1>Website orders</h1></div>
  <form method="get" action="{{ route('website-orders.index') }}" class="row" role="search">
    <input type="search" name="q" value="{{ $q }}" placeholder="CM number, email or name" aria-label="Search website orders" style="width:240px">
    <select name="stage" aria-label="Stage" data-autosubmit>
      <option value="">All stages</option>
      <option value="open" @selected($stage === 'open')>Not delivered yet</option>
      @foreach($stages as $i => $label)<option value="{{ $i }}" @selected((string) $stage === (string) $i)>{{ $label }}</option>@endforeach
    </select>
    <button class="btn">Show</button>
  </form>
</div>
<p class="muted" style="margin:0;max-width:820px">Direct orders from the product page: one product and its related styles, in stock, ready-to-print artwork, and a delivery date that meets the client's. No online payment: the client pays the emailed invoice.@if(! config('website.orders.auto_send_invoice')) Invoice emails wait here for you to send (REPSTACK principle 6).@endif</p>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Order</th><th>Client</th><th>Stage</th><th>Need by</th><th>Estimated</th><th class="num">Total</th></tr></thead>
    <tbody>
    @forelse ($orders as $o)
      @php($late = $o->stage < \App\Models\WebsiteOrder::STAGE_DELIVERED && $o->eta->gt($o->need_by))
      <tr>
        <td><a href="{{ route('website-orders.show', $o) }}"><b>{{ $o->number }}</b></a><div class="muted small">{{ $o->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</div></td>
        <td>{{ $o->organisation ?: $o->contact_name }}<div class="muted small">{{ $o->email }}</div></td>
        <td><span class="pill {{ $o->stage === 0 ? 'accent' : ($o->stage === 6 ? 'ok' : '') }}">{{ $o->stageName() }}</span>
          @if($o->draft_emails_count)<div><span class="pill warn">invoice to send</span></div>@endif
          @if($o->open_changes_count)<div><span class="pill warn">{{ $o->open_changes_count }} change request{{ $o->open_changes_count === 1 ? '' : 's' }}</span></div>@endif</td>
        <td class="small"><b>{{ $o->need_by->format('D j M Y') }}</b>@if($o->event_name)<div class="muted">{{ $o->event_name }}</div>@endif</td>
        <td class="small">{{ ($o->delivered_on ?? $o->eta)->format('D j M') }}@if($late) <span class="pill bad">late</span>@endif</td>
        <td class="num">{{ $m($o->total) }}<div class="muted small">{{ $o->export ? 'ex GST, export' : 'inc GST' }}</div></td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:20px 8px">No website orders yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@include('partials.pager', ['paginator' => $orders])
@endsection
