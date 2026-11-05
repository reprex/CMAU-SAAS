@extends('layouts.app')
@section('title', 'Vet')
@section('content')
@php($types = \App\Http\Controllers\Admin\DecorationTypeController::TYPES)
<div style="display:flex;flex-direction:column;gap:6px;max-width:900px">
  <span class="eyebrow">Vet</span>
  <h1>Vet</h1>
  <p class="muted" style="margin:0">Everything about the range and website data that still needs a person, in one place. The rest is worked out automatically from the suppliers' own product pages, the branding templates and the garment size charts. An empty section needs nothing.</p>
</div>

@php($todo = collect($sections)->where('action', true)->sum('count'))
<div class="card" style="padding:8px 12px"><b>{{ $todo ? number_format($todo).' to look at' : 'Nothing to vet.' }}</b>
  <span class="small muted">@foreach(collect($sections)->where('action', true) as $k => $s)<a href="#{{ $k }}">{{ $s['title'] }}</a> {{ number_format($s['count']) }}@if(! $loop->last) · @endif @endforeach</span></div>

{{-- New products --}}
@php($s = $sections['new'])
<section id="new" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>
    @if($s['count'])<a class="small" href="{{ $s['more'] }}">All, with the checks</a>@endif</div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}</p>
  @if($s['count'] && $isAdmin)
    <form method="post" action="{{ route('range.changes.decide') }}">@csrf
      <div class="row small" style="gap:8px;margin-bottom:8px">
        <button class="btn primary" name="action" value="approve_ready" @disabled(! $s['ready'])>Approve all {{ number_format($s['ready']) }} ready</button>
        <button class="btn" name="action" value="approve">Approve ticked</button>
        <button class="btn" name="action" value="decline">Decline ticked</button>
      </div>
      <table class="list small"><tbody>
      @foreach($s['rows'] as $r)
        <tr><td style="width:24px"><input type="checkbox" name="ids[]" value="{{ $r['id'] }}" aria-label="Tick {{ $r['name'] }}"></td>
          <td><a href="{{ route('range.show', $r['product_id']) }}"><b>{{ $r['name'] ?? 'Product '.$r['product_id'] }}</b></a> <span class="muted">{{ collect([$r['supplier'], $r['code']])->filter()->implode(' · ') }}</span></td>
          <td>{!! $r['ready'] ? '<span class="pill">Ready</span>' : '<span class="pill warn">Check</span>' !!}</td></tr>
      @endforeach
      </tbody></table>
    </form>
  @elseif($s['count'])
    <p class="small">Approving is for admins.</p>
  @endif
</section>

{{-- Discontinued --}}
@php($s = $sections['discontinued'])
<section id="discontinued" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>
    @if($s['count'])<a class="small" href="{{ $s['more'] }}">Quotes and closest substitutes</a>@endif</div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}</p>
  @foreach($s['rows'] as $r)
    <p class="small" style="margin:2px 0"><a href="{{ route('range.substitutes', $r['product_id']) }}"><b>{{ $r['name'] ?? 'Product '.$r['product_id'] }}</b></a> <span class="muted">{{ $r['code'] }}</span></p>
  @endforeach
</section>

{{-- Decoration types --}}
@php($s = $sections['types'])
<section id="types" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>
    <a class="small" href="{{ $s['more'] }}">Decoration types</a></div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}@if($s['at']) Last run {{ \Illuminate\Support\Carbon::parse($s['at'])->diffForHumans() }}.@endif</p>
  @php($rows = collect($s['left'])->map(fn ($l) => ['code' => $l['code'], 'description' => $l['description'], 'note' => ($l['supplier'] ? $l['supplier'].': ' : '').(($l['shared'] ?? false) ? 'no type (pages not read yet)' : 'no rule yet: Claude adds one from the supplier\'s pages'), 'shared' => $l['shared'] ?? false, 'on_range' => $l['on_range']]))
  @if($rows->isNotEmpty())
    <table class="list small"><thead><tr><th>Wording</th><th>Why it's here</th><th class="num">On the range</th><th>Type</th></tr></thead><tbody>
    @foreach($rows as $r)
      <tr><td><b>{{ $r['description'] }}</b></td><td class="muted">{{ $r['note'] }}</td><td class="num">{{ number_format($r['on_range']) }}</td>
        <td>@if($isAdmin && $r['shared'])
          <form method="post" action="{{ route('admin.dectypes.update', $r['code']) }}" class="row" style="gap:4px">@csrf @method('PUT')
            <select name="type_code" aria-label="Type for {{ $r['description'] }}">@foreach($types as $t => $label)<option value="{{ $t }}">{{ $t }} {{ $label }}</option>@endforeach</select>
            <button class="btn small">Save</button></form>
        @endif</td></tr>
    @endforeach
    </tbody></table>
  @endif
</section>

{{-- Templates --}}
@php($s = $sections['templates'])
<section id="templates" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }} @if($s['waiting']){{ number_format($s['waiting']) }} more are waiting to be read (about 20 a minute).@endif</p>
  @foreach($s['rows'] as $r)
    <div class="row small" style="gap:8px;margin:2px 0"><a href="{{ route('range.show', $r['product_id']) }}"><b>{{ $r['name'] }}</b></a> <span class="muted">{{ $r['file'] }} · {{ $r['message'] }}</span>
      @if($canEdit && $r['status'] === 'failed')<form method="post" action="{{ route('range.templates.read', [$r['product_id'], $r['id']]) }}">@csrf<button class="btn small">Read again</button></form>@endif</div>
  @endforeach
</section>

{{-- Publishing --}}
@php($s = $sections['publishing'])
<section id="publishing" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>@if($isAdmin)<a class="small" href="{{ $s['more'] }}">Shopify publishing</a>@endif</div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}</p>
  @foreach($s['rows'] as $r)
    <p class="small" style="margin:2px 0"><a href="{{ route('range.show', $r['product_id']) }}"><b>{{ $r['name'] ?? 'Product '.$r['product_id'] }}</b></a> <span class="muted">{{ \Illuminate\Support\Str::limit((string) $r['message'], 160) }}</span></p>
  @endforeach
</section>

{{-- Price check --}}
@php($s = $sections['prices'])
<section id="prices" class="card" style="{{ $s['count'] ? 'border-left:4px solid var(--warn,#c77700)' : '' }}">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }} patterns · {{ number_format($s['products']) }} products on the range</span></h2>@if($isAdmin)<a class="small" href="{{ $s['more'] }}">Price check: examples and screengrab list</a>@endif</div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }} @if($s['flagged']){{ number_format($s['flagged']) }} products on the range don't fit their pattern.@endif</p>
  @if($s['rows'])
    <table class="list small"><thead><tr><th>Supplier</th><th>Pattern</th><th class="num">On the range</th><th>Status</th></tr></thead><tbody>
    @foreach($s['rows'] as $r)<tr><td>{{ $r['supplier'] }}</td><td>{{ \Illuminate\Support\Str::limit($r['label'], 110) }}</td><td class="num">{{ number_format($r['on_range']) }}</td><td>{{ $r['status'] === 'mixed' ? 'Checks disagree' : 'To check' }}</td></tr>@endforeach
    </tbody></table>
  @endif
</section>

<h2 style="margin:16px 0 0">For information</h2>
<p class="small muted" style="margin:0">Nothing to decide here: these improve as Claude reads more supplier pages and templates.</p>

@php($s = $sections['options'])
<section id="options" class="card">
  <h2 style="margin:0">{{ $s['title'] }} <span class="muted small">{{ number_format($s['count']) }}</span></h2>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}</p>
  @foreach($s['rows'] as $r)<p class="small" style="margin:2px 0">{{ $r['description'] }} <span class="muted">{{ $r['supplier'] }} · {{ number_format($r['products']) }} products</span></p>@endforeach
</section>

@php($s = $sections['mockups'])
<section id="mockups" class="card">
  <div class="row between"><h2 style="margin:0">{{ $s['title'] }}</h2><a class="small" href="{{ $s['more'] }}">Positions</a></div>
  <p class="small muted" style="margin:4px 0 8px">{{ $s['why'] }}</p>
  @if($s['summary'])
    <p class="small" style="margin:0">{{ number_format($s['summary']['mockup']) }} with a mockup from a template or staff · {{ number_format($s['summary']['garment']) }} garments to scale · <b>{{ number_format($s['summary']['guide']) }} guide card only</b>
      @if($s['by_supplier'])<span class="muted">({{ collect($s['by_supplier'])->map(fn ($n, $k) => $k.' '.number_format($n))->implode(', ') }})</span>@endif</p>
    @if($s['garments'])<p class="small" style="margin:6px 0 0"><span class="muted">Garments with no size chart in the supplier data:</span>
      @foreach($s['garments'] as $g)<a href="{{ route('range.show', $g->id) }}">{{ $g->name }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
  @else
    <p class="small muted" style="margin:0">Counted nightly (04:30).</p>
  @endif
</section>
@endsection
