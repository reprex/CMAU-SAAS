@extends('layouts.app')
@section('title', 'Logo areas')
@section('content')
@php($canEdit = auth()->user()->canEdit())
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Website</span>
  <h1>Logo areas</h1>
  <p class="muted" style="margin:0">The website shows the CrowdMerch logo on every hero image where the branding goes, and swaps in the shopper's own logo when they try theirs. Drag the logo into place, drag its corner to size it, then approve. Only approved areas go to the website.</p>
</div>

<nav class="tabs" aria-label="Logo area views">
  @foreach(\App\Http\Controllers\LogoAreaController::VIEWS as $key => $label)
    <a href="{{ route('range.logo-areas', ['view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }} <span class="muted small">{{ number_format($counts[$key]) }}</span></a>
  @endforeach
</nav>

<div class="logo-grid">
@forelse($cards as $c)
  @php($p = $c['product'])
  <form method="post" action="{{ route('range.logo-areas.store', $p) }}" class="card logo-card" data-logo-area>
    @csrf
    <div class="logo-stage">
      @if($c['hero'])
        <img src="{{ $c['hero'] }}" alt="{{ $p->name }}" class="logo-hero" loading="lazy">
        <div class="logo-box" style="left:{{ $c['area'][0] }}%;top:{{ $c['area'][1] }}%;width:{{ $c['area'][2] }}%" data-start="{{ json_encode($c['area']) }}" @if($canEdit) tabindex="0" aria-label="Logo position: drag to move, arrow keys to nudge, plus and minus to resize" @endif>
          <img src="{{ asset('img/crowdmerch-logo.png') }}" alt="" draggable="false">
          @if($canEdit)<span class="logo-handle" aria-hidden="true"></span>@endif
        </div>
      @else
        <p class="muted small" style="padding:20px">No hero image.</p>
      @endif
    </div>
    <div class="small"><a href="{{ route('range.show', $p) }}"><b>{{ $p->name }}</b></a><br><span class="muted">{{ $p->supplier?->name }} {{ $p->code }} · {{ $p->promodata_type_name }}</span></div>
    <div class="small muted">
      @if($c['stored']?->approved_at) Approved {{ $c['stored']->approved_at->timezone(config('app.timezone'))->format('j M') }}{{ $c['stored']->approver ? ' by '.$c['stored']->approver->name : '' }}
      @elseif($c['stored']?->source === 'ai') Suggested by Claude
      @else Default for {{ $p->promodata_type_name ?: 'this kind of product' }} @endif
    </div>
    <input type="hidden" name="left" value="{{ $c['area'][0] }}"><input type="hidden" name="top" value="{{ $c['area'][1] }}"><input type="hidden" name="width" value="{{ $c['area'][2] }}">
    @if($canEdit && $c['hero'])
      <div class="row" style="gap:6px"><button class="btn small primary">Approve</button><button type="button" class="btn small" data-logo-reset>Reset</button></div>
    @endif
  </form>
@empty
  <p class="muted">{{ $view === 'todo' ? 'Nothing to vet. Every web-range product has an approved logo area.' : 'No approved logo areas yet.' }}</p>
@endforelse
</div>
{{ $products->links() }}
@endsection
