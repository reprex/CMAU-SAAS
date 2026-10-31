@extends('layouts.app')
@section('title', 'Add a whole supplier')
@section('content')
@php
  $toAdd = $types->sum('to_add');
  $live = $types->sum('live');
@endphp
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:780px">
    <span class="eyebrow">Admin · Range curation</span>
    <h1>Add a whole supplier</h1>
    <p class="muted" style="margin:0">Put every current product from one supplier on the web range, e.g. all of JB's Wear. Discontinued products are skipped, and you can leave out product types. Products already on the range are not touched. Run it again later to pick up the supplier's new products.</p>
  </div>
  <a class="btn" href="{{ route('range.index') }}">Back to Range</a>
</div>

<form method="get" class="row" role="search">
  <select name="supplier" aria-label="Supplier" style="width:320px" data-autosubmit>
    <option value="">Choose a supplier…</option>
    @foreach($suppliers as $s)
      <option value="{{ $s->id }}" @selected($supplier?->id === $s->id)>{{ $s->name }} ({{ $s->id }}) · {{ number_format($s->live_count) }} of {{ number_format($s->available_count) }} on range</option>
    @endforeach
  </select>
  <button class="btn">Show</button>
</form>

@if($supplier)
<div class="card">
  <h2>{{ $supplier->name }} <span class="muted small">Promodata supplier {{ $supplier->id }}</span></h2>
  <p class="small" style="margin:0 0 8px">
    Last sync that covered this supplier: <b>{{ $lastSync?->finished_at?->diffForHumans() ?? 'none yet' }}</b>.
    The list below is only as complete as that sync.
    @if($running)
      A sync is running now ({{ $running->kind }}); wait for it to finish before adding.
    @endif
  </p>
  @unless($running)
  <form method="post" action="{{ route('admin.sync.start') }}" class="row small" style="gap:8px">@csrf
    <input type="hidden" name="kind" value="supplier"><input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
    <button class="btn small">Sync {{ $supplier->name }} from Promodata first</button>
    <span class="muted">A few minutes; it runs in the background. Come back here when the Promodata sync screen shows it finished.</span>
  </form>
  @endunless
</div>

@if($types->isEmpty())
  <div class="card"><p class="muted" style="margin:0">No current products stored for {{ $supplier->name }}. Run the supplier sync above first.</p></div>
@else
<form method="post" action="{{ route('admin.range-supplier.store') }}">@csrf
  <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
  <div class="card" style="padding:6px 12px">
    <table class="list">
      <thead><tr>
        <th style="width:28px"><input type="checkbox" data-check-all="types[]" checked aria-label="Tick all types"></th>
        <th>Product type (Promodata)</th><th class="num">Already on range</th><th class="num">To add</th>
      </tr></thead>
      <tbody>
      @foreach($types as $t)
        <tr>
          <td>@if($t->to_add > 0)<input type="checkbox" name="types[]" value="{{ $t->type_name }}" checked aria-label="Include {{ $t->type_name ?: 'no type' }}">@endif</td>
          <td>{{ $t->type_name !== '' ? $t->type_name : 'No product type' }}</td>
          <td class="num">{{ number_format($t->live) }}</td>
          <td class="num"><b>{{ number_format($t->to_add) }}</b></td>
        </tr>
      @endforeach
      </tbody>
      <tfoot><tr><td></td><td><b>Total</b></td><td class="num">{{ number_format($live) }}</td><td class="num"><b>{{ number_format($toAdd) }}</b></td></tr></tfoot>
    </table>
  </div>
  <div class="row small" style="gap:8px">
    <button class="btn primary" @disabled($toAdd === 0 || $running) data-confirm="Add the ticked types from {{ $supplier->name }} to the web range? Each product is fetched from Promodata and priced, about 25 a minute.">Add ticked types to the range</button>
    <span class="muted">About {{ max(1, (int) ceil($toAdd / 25)) }} minutes for all of them. Each added product is marked "to publish" for the Shopify publisher. New decoration wording from this supplier will appear under Admin → Decoration types to classify.</span>
  </div>
</form>
@endif
@endif
@endsection
