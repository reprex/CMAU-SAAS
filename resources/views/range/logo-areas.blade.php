@extends('layouts.app')
@section('title', 'Logo areas')
@section('content')
@php($canEdit = auth()->user()->canEdit())
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
    <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Website</span>
    <h1>Logo areas</h1>
    <p class="muted" style="margin:0">The website shows the CrowdMerch logo where the branding normally goes on each hero image, and swaps in the shopper's own logo when they try theirs. Every product now gets its logo area and branding positions automatically: from the supplier's branding template, else the print areas in Promodata's decoration options, else the product type default. <b>Vetting here is optional</b>: approve any product to place its logo by hand (that always wins), or vet a sample and the corrections are learned and applied to products that only have a prediction.</p>
  </div>
  @if($canEdit)
  <div class="row" style="gap:8px">
    <form method="post" action="{{ route('range.logo-areas.sample') }}">@csrf<input type="hidden" name="size" value="{{ $counts['done'] ? 25 : 100 }}"><button class="btn">{{ $counts['done'] ? 'Add 25 more samples' : 'Pick 100 samples (optional)' }}</button></form>
    <form method="post" action="{{ route('range.logo-areas.apply') }}">@csrf<button class="btn primary" @disabled(! $counts['done'])>Apply to the range</button></form>
  </div>
  @endif
</div>

<div class="card" style="padding:10px 14px">
  <span class="small">{{ number_format($live) }} web-range products · <b>{{ number_format($counts['done']) }}</b> vetted · <b>{{ number_format($counts['learned']) }}</b> learned from them · {{ number_format($counts['sample']) }} samples waiting</span>
</div>

<nav class="tabs" aria-label="Logo area views">
  @foreach(\App\Http\Controllers\LogoAreaController::VIEWS as $key => $label)
    <a href="{{ route('range.logo-areas', ['view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }}@isset($counts[$key]) <span class="muted small">{{ number_format($counts[$key]) }}</span>@endisset</a>
  @endforeach
</nav>

@if($view === 'groups')
  <div class="card" style="padding:6px 12px">
    <table class="list">
      <thead><tr><th>Supplier · product type</th><th class="num">Products</th><th class="num">Vetted</th><th class="num" title="Median correction to the prediction">Moved across</th><th class="num">Moved down</th><th class="num">Size</th><th>Status</th></tr></thead>
      <tbody>
      @forelse($groups as $g)
        @php([$sid, $type] = array_pad(explode('|', $g['key'], 2), 2, ''))
        <tr>
          <td>{{ $names[$sid] ?? 'Supplier '.$sid }} · {{ $type ? ucwords($type) : '–' }}</td>
          <td class="num">{{ number_format($g['products']) }}</td>
          <td class="num">{{ $g['samples'] }}</td>
          <td class="num">{{ sprintf('%+.1f', $g['left']) }}%</td>
          <td class="num">{{ sprintf('%+.1f', $g['top']) }}%</td>
          <td class="num">× {{ number_format($g['width'], 2) }}</td>
          <td class="small"><span class="pill {{ $g['status'] === 'consistent' ? 'ok' : 'warn' }}">{{ $g['status'] }}</span></td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted" style="padding:20px 8px">Nothing learned yet. Pick 100 samples and vet them.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <p class="small muted">Learning order for each product: its supplier and product type (2+ vetted), else its product type across suppliers, else all vetted samples (10+). Groups whose samples disagree get more samples from "Add 25 more samples".</p>
@else
<div class="logo-grid">
@forelse($cards as $c)
  @php($p = $c['product'])
  @php($a = $c['area'])
  <form method="post" action="{{ route('range.logo-areas.store', $p) }}" class="card logo-card" data-logo-area>
    @csrf
    <div class="logo-stage">
      <img src="{{ $a->url }}" alt="{{ $p->name }}" class="logo-hero" loading="lazy">
      <div class="logo-box" style="left:{{ $a->left }}%;top:{{ $a->top }}%;width:{{ $a->width }}%" data-start="{{ json_encode([$a->left, $a->top, $a->width]) }}" @if($canEdit) tabindex="0" aria-label="Logo position: drag to move, arrow keys to nudge, plus and minus to resize" @endif>
        <img src="{{ asset('img/crowdmerch-logo.png') }}" alt="" draggable="false">
        @if($canEdit)<span class="logo-handle" aria-hidden="true"></span>@endif
      </div>
    </div>
    <div class="small"><a href="{{ route('range.show', $p) }}"><b>{{ $p->name }}</b></a><br><span class="muted">{{ $p->supplier?->name }} {{ $p->code }} · {{ $p->promodata_type_name }}</span></div>
    <div class="small muted">
      @if($a->approved_at) Approved {{ $a->approved_at->timezone(config('app.timezone'))->format('j M') }}{{ $a->approver ? ' by '.$a->approver->name : '' }}
      @elseif($a->source === 'learned') Learned from {{ ['group' => 'this supplier and type', 'type' => 'this product type', 'all' => 'all samples'][$a->learned_from] ?? 'samples' }}
      @elseif($a->source === 'template') From the supplier's branding template
      @elseif($a->source === 'ai') Predicted by Claude
      @else Predicted from the product type @endif
    </div>
    <input type="hidden" name="left" value="{{ $a->left }}"><input type="hidden" name="top" value="{{ $a->top }}"><input type="hidden" name="width" value="{{ $a->width }}">
    @if($canEdit)
      <div class="row" style="gap:6px"><button class="btn small primary">Approve</button><button type="button" class="btn small" data-logo-reset>Reset</button><button type="button" class="btn small" data-logo-invert aria-pressed="false" title="Show the logo in white, for dark products">White logo</button></div>
    @endif
  </form>
@empty
  <p class="muted">@switch($view)
    @case('sample') No samples waiting. {{ $counts['done'] ? 'Add more samples if a group needs them, or spot-check the Learned tab.' : 'Pick 100 samples to start.' }} @break
    @case('learned') Nothing learned yet. Vet the sample, then Apply to the range. @break
    @default No approved logo areas yet.
  @endswitch</p>
@endforelse
</div>
@if($products){{ $products->links() }}@endif
@endif
@endsection
