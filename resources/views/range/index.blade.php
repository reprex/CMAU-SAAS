@extends('layouts.app')
@section('title', 'Range')
@section('content')
@php
  $canEdit = auth()->user()->canEdit();
  $tierPill = ['live' => 'ok', 'active' => 'accent', 'summary' => ''];
  $tierText = ['live' => 'On range', 'active' => 'Kept', 'summary' => 'Summary'];
@endphp
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:760px">
    <span class="eyebrow">Range curation</span>
    <h1>Range</h1>
    <p class="muted" style="margin:0">Search the whole Promodata catalogue, add products to the CrowdMerch web range or take them off. Prices follow the TOD rules; open a product to curate it.</p>
  </div>
</div>

<nav class="tabs" aria-label="Range views">
  @foreach(\App\Http\Controllers\RangeController::VIEWS as $key => $label)
    <a href="{{ route('range.index', array_filter(['view' => $key, 'q' => $q, 'supplier' => $supplierId, 'type' => $type])) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }}@isset($counts[$key]) <span class="muted small">{{ number_format($counts[$key]) }}</span>@endisset</a>
  @endforeach
</nav>

<form method="get" class="row" role="search">
  <input type="hidden" name="view" value="{{ $view }}">
  <input type="search" name="q" value="{{ $q }}" placeholder="Name, supplier code or APPAID" aria-label="Search products" style="width:260px">
  <select name="supplier" aria-label="Supplier" style="width:220px"><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($supplierId === $s->id)>{{ $s->name }}</option>@endforeach</select>
  <select name="type" aria-label="Product type" style="width:220px"><option value="">All product types</option>@foreach($types as $t)<option @selected($type === $t)>{{ $t }}</option>@endforeach</select>
  <select name="sort" aria-label="Sort" style="width:170px">
    <option value="name" @selected($sort === 'name')>Sort: name</option>
    <option value="changed" @selected($sort === 'changed')>Sort: recently changed</option>
    <option value="cost" @selected($sort === 'cost')>Sort: lowest cost</option>
    <option value="id" @selected($sort === 'id')>Sort: APPAID</option>
  </select>
  @if(in_array($view, ['all', 'active'], true))<label class="row small" style="gap:6px"><input type="checkbox" name="discontinued" value="1" @checked($showDiscontinued)> include discontinued</label>@endif
  <button class="btn">Search</button>
</form>

<form method="post" action="{{ route('range.bulk') }}" id="bulk">@csrf
@if($canEdit)
<div class="row small" style="gap:8px">
  <span class="muted">With ticked products:</span>
  <button class="btn small" name="action" value="add">Add to range</button>
  <button class="btn small" name="action" value="remove" data-confirm="Take the ticked products off the web range? Their details and overrides are kept.">Remove from range</button>
  <button class="btn small" name="action" value="refresh">Refresh from Promodata</button>
  <button class="btn small" name="action" value="reprice">Reprice</button>
</div>
@endif

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr>
      @if($canEdit)<th style="width:28px"><input type="checkbox" data-check-all="ids[]" aria-label="Tick all on this page"></th>@endif
      <th style="width:60px"></th><th>Product</th><th>Supplier</th><th>Type</th><th class="num">Cost from</th><th class="num">Web price</th><th class="num">Colours</th><th>Status</th><th>Supplier change</th>
    </tr></thead>
    <tbody>
    @forelse($products as $p)
      <tr>
        @if($canEdit)<td><input type="checkbox" name="ids[]" value="{{ $p->id }}" aria-label="Tick {{ $p->name }}"></td>@endif
        <td><div class="thumb" style="width:48px;height:48px">@if($p->hero_image_url)<img src="{{ $p->hero_image_url }}" alt="" loading="lazy">@endif</div></td>
        <td><a href="{{ route('range.show', $p) }}"><b>{{ $p->name }}</b></a><br><span class="muted small mono">{{ $p->id }}{{ $p->code ? ' · '.$p->code : '' }}</span></td>
        <td class="small">{{ $p->supplier?->name }}</td>
        <td class="small muted">{{ $p->promodata_type_name }}</td>
        <td class="num">{{ $p->min_unit_cost !== null ? number_format((float) $p->min_unit_cost, 2) : '' }}@if($p->min_qty)<br><span class="muted small">at {{ $p->min_qty }}</span>@endif</td>
        <td class="num">@if($p->webPrice?->collection_variant_price){{ number_format($p->webPrice->collection_variant_price, 2) }}<br><span class="muted small">at {{ $p->webPrice->collection_moq }}</span>@endif</td>
        <td class="num">{{ $p->colour_count }}</td>
        <td>
          <span class="pill {{ $tierPill[$p->tier] ?? '' }}">{{ $tierText[$p->tier] ?? $p->tier }}</span>
          @if($p->discontinued)<span class="pill bad">discontinued</span>@endif
          @if($p->reprice_error)<span class="pill bad" title="{{ $p->reprice_error }}">pricing error</span>@endif
          @if($p->tier === 'live' && $p->range_changed_at && (! $p->published_at || $p->range_changed_at->gt($p->published_at)))<span class="pill warn">to publish</span>@endif
        </td>
        <td class="small muted">{{ $p->last_changed_at?->format('j M Y') }}</td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted" style="padding:24px 8px">
        @if($counts['all'] === 0) The catalogue is empty. An admin starts the first full sync from <a href="{{ route('admin.sync.index') }}">Promodata sync</a>, or imports TOD's range. @else No products match. @endif
      </td></tr>
    @endforelse
    </tbody>
  </table>
</div>
</form>
{{ $products->links('partials.pager') }}
@endsection
