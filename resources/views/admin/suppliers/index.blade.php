@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
@php($m = fn ($v) => $v === null ? '' : number_format((float) $v, 2))
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:760px">
    <span class="eyebrow">Admin · Suppliers</span>
    <h1>Standard freight</h1>
    <p class="muted" style="margin:0">Quotes add one freight line per supplier per delivery at the sell price here. Staff see the line on the quote but can't change these settings.</p>
  </div>
  @if($missing)<a href="{{ route('admin.suppliers.index', ['show' => 'missing']) }}" class="pill accent" style="padding:8px 12px;text-decoration:none">{{ $missing }} quoted supplier{{ $missing === 1 ? ' has' : 's have' }} no standard freight yet</a>@endif
</div>

<div class="row">
  <form method="get" class="row" role="search"><input type="search" name="q" value="{{ $search }}" placeholder="Supplier name or id" aria-label="Search suppliers" style="width:240px"></form>
  <div class="seg">
    <a href="{{ route('admin.suppliers.index', ['show' => 'quoted']) }}" class="{{ $filter === 'quoted' ? 'on' : '' }}">On quotes, web or set</a>
    <a href="{{ route('admin.suppliers.index', ['show' => 'missing']) }}" class="{{ $filter === 'missing' ? 'on' : '' }}">Missing freight</a>
    <a href="{{ route('admin.suppliers.index', ['show' => 'all']) }}" class="{{ $filter === 'all' ? 'on' : '' }}">All</a>
  </div>
</div>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Supplier</th><th class="num">Cost</th><th class="num">Sell</th><th>Charged</th><th class="num">Free over</th><th>Recent quotes charged</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($suppliers as $s)
      @php($lc = $lastCharged->get($s->id))
      <tr>
        <td><a href="{{ route('admin.suppliers.edit', $s) }}"><b>{{ $s->name }}</b></a> <span class="muted small mono">{{ $s->id }}</span>@if($s->is_decorator) <span class="pill">decorator</span>@endif</td>
        <td class="num">{{ $m($s->freight_cost) }}</td>
        <td class="num">{{ $m($s->freight_sell) }}</td>
        <td class="small">{{ $s->hasStandardFreight() ? \App\Models\Supplier::FREIGHT_BASES[$s->freight_basis] ?? $s->freight_basis : '' }}</td>
        <td class="num">{{ $m($s->free_freight_over) }}</td>
        <td class="small muted">{{ $lc ? ($lc->lo == $lc->hi ? number_format($lc->hi, 2) : number_format($lc->lo, 2).' to '.number_format($lc->hi, 2)) : '–' }}</td>
        <td>@if($s->hasStandardFreight())<span class="pill ok">Set</span>@else<span class="pill warn">Not set · quotes say "to be confirmed"</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted" style="padding:24px 8px">No suppliers match.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $suppliers->links('partials.pager') }}
@endsection
