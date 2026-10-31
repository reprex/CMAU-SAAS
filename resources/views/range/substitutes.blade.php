@extends('layouts.app')
@section('title', 'Substitutes for '.$ref->name)
@section('content')
@php
  $dims = fn ($p) => ($d = $finder->dims($p)) ? implode(' × ', array_map(fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.'), $d)).' mm' : null;
  $money = fn ($v) => $v === null ? '–' : number_format($v, 2);
  $refCost = $finder->cost($ref);
  $refMoq = $finder->moq($ref);
  $need = $filters['need'] ?? null;
  $partNames = ['material' => 'Material', 'size' => 'Size', 'cost' => 'Cost', 'moq' => 'MOQ', 'lead' => 'Lead time'];
  $tierText = ['live' => 'On range', 'active' => 'Kept', 'summary' => 'Summary'];
@endphp

<div style="display:flex;flex-direction:column;gap:6px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Substitute finder</span>
  <h1>Substitutes for {{ $ref->overrideValue('title') ?: $ref->name }}</h1>
  <p class="muted" style="margin:0;max-width:820px">Products of the same type, ranked on material ({{ \App\Catalogue\SubstituteFinder::WEIGHTS['material'] }}), size ({{ \App\Catalogue\SubstituteFinder::WEIGHTS['size'] }}), unbranded cost ({{ \App\Catalogue\SubstituteFinder::WEIGHTS['cost'] }}), minimum order ({{ \App\Catalogue\SubstituteFinder::WEIGHTS['moq'] }}) and lead time ({{ \App\Catalogue\SubstituteFinder::WEIGHTS['lead'] }}). Anything the supplier doesn't state scores half. Live stock fills in from Promodata after the page loads.</p>
</div>

<div class="card">
  <div class="row" style="align-items:flex-start;gap:16px">
    <div class="thumb" style="width:76px;height:76px">@if($ref->hero_image_url)<img src="{{ $ref->hero_image_url }}" alt="">@endif</div>
    <div class="grow" style="display:flex;flex-direction:column;gap:4px">
      <div><a href="{{ route('range.show', $ref) }}"><b>{{ $ref->name }}</b></a> <span class="muted small mono">{{ $ref->id }} · {{ $ref->supplier?->name }} · {{ $ref->code }}</span>@if($ref->discontinued) <span class="pill bad">discontinued</span>@endif</div>
      <div class="small">{{ $ref->promodata_type_name ?: $ref->supplier_subcategory ?: 'No product type' }}</div>
      <div class="row small" style="gap:18px">
        <span><span class="muted">Material</span> {{ $ref->material ?: '–' }}</span>
        <span><span class="muted">Size</span> {{ $dims($ref) ?: '–' }}</span>
        <span><span class="muted">MOQ</span> {{ $refMoq ?? '–' }}</span>
        <span><span class="muted">Unbranded cost</span> {{ $money($refCost) }}</span>
        <span><span class="muted">Lead time</span> {{ $ref->lead_time_days ? $ref->lead_time_days.' days' : '–' }}</span>
        <span><span class="muted">Stock</span> <span data-stock-url="{{ route('range.stock', $ref) }}" data-need="{{ $need }}">…</span></span>
      </div>
      @if($ref->summary_version < 1)<div class="small" style="color:var(--warn)">This product's material, size and lead time arrive with the next Promodata sync; until then they score half.</div>@endif
    </div>
  </div>
</div>

<form method="get" class="row">
  <label class="f" style="width:190px">Match on
    <select name="scope">
      <option value="type" @selected($filters['scope'] === 'type')>Same product type</option>
      <option value="subcategory" @selected($filters['scope'] === 'subcategory')>Same supplier subcategory</option>
    </select></label>
  <label class="f" style="width:140px">Max unbranded cost<input name="max_cost" value="{{ $filters['max_cost'] ?? '' }}" inputmode="decimal" class="n"></label>
  <label class="f" style="width:110px">Max MOQ<input name="max_moq" value="{{ $filters['max_moq'] ?? '' }}" inputmode="numeric" class="n"></label>
  <label class="f" style="width:150px">Max lead time (days)<input name="max_lead" value="{{ $filters['max_lead'] ?? '' }}" inputmode="numeric" class="n"></label>
  <label class="f" style="width:150px">Quantity needed<input name="need" value="{{ $need }}" inputmode="numeric" class="n" placeholder="for stock"></label>
  <label class="check small" style="align-self:flex-end;padding-bottom:10px"><input type="checkbox" name="other_suppliers" value="1" @checked(! empty($filters['other_suppliers']))> Other suppliers only</label>
  <button class="btn" style="align-self:flex-end">Find</button>
  <label class="check small" style="align-self:flex-end;padding-bottom:10px"><input type="checkbox" id="hide-short"> Hide short on stock</label>
</form>

<div class="card" style="padding:6px 12px">
  <table class="list" id="subs">
    <thead><tr><th class="num">Match</th><th style="width:56px"></th><th>Product</th><th>Material</th><th>Size</th><th class="num">MOQ</th><th class="num">Unbranded cost</th><th class="num">Lead</th><th class="num">Stock</th><th></th></tr></thead>
    <tbody>
    @forelse($results as $r)
      @php($p = $r['product'])
      @php($cost = $finder->cost($p))
      <tr data-row>
        @php($breakdown = collect($r['parts'])->map(fn ($v, $k) => $partNames[$k].' '.($v === null ? 'no data' : round($v * 100)))->implode(' · '))
        @php($weak = collect($r['parts'])->filter(fn ($v) => $v !== null && $v < 0.6)->keys()->map(fn ($k) => mb_strtolower($partNames[$k]))->implode(', '))
        <td class="num" title="{{ $breakdown }}" style="width:96px"><b>{{ $r['score'] }}</b>
          <div style="background:#EFE8DC;border-radius:4px;height:6px;margin-top:4px;overflow:hidden"><div style="width:{{ $r['score'] }}%;height:100%;background:var(--accent)"></div></div>
          @if($weak)<div class="small muted" style="white-space:normal;text-align:right;margin-top:3px">weaker: {{ $weak }}</div>@endif</td>
        <td><div class="thumb" style="width:48px;height:48px">@if($p->hero_image_url)<img src="{{ $p->hero_image_url }}" alt="" loading="lazy">@endif</div></td>
        <td><a href="{{ route('range.show', $p) }}"><b>{{ $p->name }}</b></a><br><span class="muted small mono">{{ $p->id }} · {{ $p->code }}</span><br><span class="small">{{ $p->supplier?->name }}</span> <span class="pill {{ $p->tier === 'live' ? 'ok' : '' }}">{{ $tierText[$p->tier] ?? $p->tier }}</span></td>
        <td class="small">{{ $p->material ?: '–' }}</td>
        <td class="small" style="white-space:nowrap">{{ $dims($p) ?: '–' }}</td>
        <td class="num">{{ $finder->moq($p) ?? '–' }}</td>
        <td class="num">{{ $money($cost) }}@if($cost !== null && $refCost)<br><span class="small {{ $cost > $refCost ? 'muted' : '' }}" style="{{ $cost < $refCost ? 'color:var(--ok)' : '' }}">{{ $cost >= $refCost ? '+' : '' }}{{ round(($cost - $refCost) / $refCost * 100) }}%</span>@endif @if($p->undecorated_cost === null && $p->min_unit_cost !== null)<br><span class="small muted" title="No unbranded price: lowest branded base cost shown">branded</span>@endif</td>
        <td class="num">{{ $p->lead_time_days ? $p->lead_time_days.' d' : '–' }}</td>
        <td class="num" data-stock-url="{{ $p->source === 'promodata' && $p->can_check_stock ? route('range.stock', $p) : '' }}" data-need="{{ $need }}">{{ $p->source === 'promodata' && $p->can_check_stock ? '…' : 'n/a' }}</td>
        <td><a class="btn small" href="{{ route('range.substitutes', $p) }}" title="Find products like this one">Similar</a></td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted" style="padding:24px 8px">No other products of this type match. Try "Same supplier subcategory" or loosen the limits.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
