@extends('layouts.app')
@section('title', 'Positions')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Website</span>
  <h1>Branding positions</h1>
  <p class="muted" style="margin:0">Positions are worked out automatically: the supplier's branding template, else Promodata's print areas, plus back, sleeves and back neck for garments, else one position for the product type (a guess). Curate a product type here, or one product on its Range page; curated positions always win.</p>
</div>

@if($report)
<div class="card" style="padding:10px 14px">
  <span class="small">{{ number_format($report['products']) }} live products ({{ \Illuminate\Support\Carbon::parse($report['at'])->diffForHumans() }}):
    @foreach($report['sources'] as $s => $n)<b>{{ number_format($n) }}</b> {{ ['template' => 'from templates', 'decoration' => 'from Promodata print areas', 'garment' => 'garment extras', 'default' => 'type-default guesses', 'staff' => 'curated', 'none (not brandable)' => 'not brandable'][$s] ?? $s }}@if(! $loop->last) · @endif @endforeach
  </span>
</div>
@else
<div class="card" style="padding:10px 14px"><span class="small muted">The source counts appear after the nightly report runs (or run <code>catalogue:positions-report</code>).</span></div>
@endif

<div class="row" style="align-items:flex-start;gap:20px">
  <div class="card" style="padding:6px 12px;flex:1 1 380px">
    <table class="list small">
      <thead><tr><th>Product type</th><th class="num">Live</th><th class="num" title="Products whose position is the type-default guess">Guesses</th><th>Curated</th></tr></thead>
      <tbody>
      @foreach($types as $t)
        <tr class="{{ $type === $t->type ? 'on' : '' }}">
          <td><a href="{{ route('range.positions', ['type' => $t->type]) }}">{{ $t->type ?: '(no type)' }}</a></td>
          <td class="num">{{ number_format($t->n) }}</td>
          <td class="num">{{ isset($report['guess_types'][$t->type]) ? number_format($report['guess_types'][$t->type]) : '' }}</td>
          <td>@if($rules->has($t->type))<span class="pill ok">{{ count($rules[$t->type]->positions) }} positions</span>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <p class="small muted">{{ $productRules }} products have their own positions.</p>
  </div>

  @if($type !== '')
  <div style="flex:1 1 460px;display:flex;flex-direction:column;gap:10px">
    <h2 style="margin:0">{{ $type }}</h2>
    <div class="row" style="gap:6px">@foreach($examples as $e)<a href="{{ route('range.show', $e) }}" title="{{ $e->name }}" class="thumb" style="width:52px;height:52px">@if($e->hero_image_url)<img src="{{ $e->hero_image_url }}" alt="" loading="lazy">@endif</a>@endforeach</div>
    @php($sample = $examples->first() ? \App\Models\Product::find($examples->first()->id) : null)
    @include('range._position-form', ['rule' => $rule, 'productType' => $type, 'auto' => $sample && ! $rule ? app(\App\Catalogue\PositionResolver::class)->forShopify($sample) : []])
  </div>
  @endif
</div>
@endsection
