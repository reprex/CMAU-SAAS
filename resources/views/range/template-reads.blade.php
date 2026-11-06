@extends('layouts.app')
@section('title', 'Template reads')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Branding templates</span>
  <h1>Template reads</h1>
  <p class="muted" style="margin:0">What Claude read from each supplier template, newest first ({{ number_format($total) }} read). Each print area is drawn on the photo it was placed on: <b class="c-green">green</b> the logo's corners (it follows angle and curve), <b class="c-orange">orange</b> a plain box. Choose "Compare with" to see another model's reading of the same templates in <b class="c-blue">blue dashes</b>. A position with no photo of its side shows its size only (the website shows a guide card).</p>
</div>
<form method="get" class="row" style="gap:8px;margin:12px 0">
  <select name="supplier" data-autosubmit><option value="">All suppliers</option>@foreach($suppliers as $id => $name)<option value="{{ $id }}" @selected($supplier === $id)>{{ $name }}</option>@endforeach</select>
  @if($models->isNotEmpty())<select name="compare" data-autosubmit><option value="">No comparison</option>@foreach($models as $m)<option value="{{ $m }}" @selected($compare === $m)>Compare with {{ $m }}</option>@endforeach</select>@endif
  <label class="small"><input type="checkbox" id="show-logo"> Show the CrowdMerch logo in each print area</label>
  <label class="small"><input type="checkbox" name="only" value="boxes" @checked(request('only') === 'boxes') data-autosubmit> Only with a print area on a photo</label>
</form>
<div class="treads" data-logo="{{ asset('images/cm-logo.png') }}">
@foreach($templates as $t)
  @php($byImage = $t->positions->whereNotNull('image_url')->groupBy('image_url'))
  @php($cmp = $compare ? $t->comparisons->firstWhere('model', $compare) : null)
  @php($alt = collect($cmp?->positions ?? []))
  <div class="card tread">
    <div class="row between"><a href="{{ route('range.show', $t->product_id) }}"><b>{{ \Illuminate\Support\Str::limit($t->product?->name, 40) }}</b></a><span class="small muted">{{ $t->read_at?->diffForHumans() }}</span></div>
    <a class="small" href="{{ route('range.templates.download', [$t->product_id, $t->id]) }}">Branding template used{{ $t->filename ? ': '.\Illuminate\Support\Str::limit($t->filename, 36) : '' }}</a>
    @forelse($byImage as $url => $ps)
      <div class="tread-photo">
        <img src="{{ $url }}" alt="" loading="lazy">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none">
          @foreach($ps as $p)
            @if(is_array($p->quad) && count($p->quad) === 4)
              <polygon points="{{ collect($p->quad)->map(fn ($c) => $c[0].','.$c[1])->implode(' ') }}" fill="rgba(34,197,94,.18)" stroke="#22c55e" stroke-width="3" vector-effect="non-scaling-stroke"/>
            @elseif($p->width)
              @php($h = $p->max_width_mm && $p->max_height_mm ? $p->width * $p->max_height_mm / $p->max_width_mm : $p->width * .6)
              <rect x="{{ $p->left }}" y="{{ $p->top }}" width="{{ $p->width }}" height="{{ $h }}" fill="rgba(249,115,22,.18)" stroke="#f97316" stroke-width="3" vector-effect="non-scaling-stroke"/>
            @endif
          @endforeach
          @foreach($alt->where('image_url', $url) as $c)
            @if(is_array($c['quad'] ?? null) && count($c['quad']) === 4)
              <polygon points="{{ collect($c['quad'])->map(fn ($x) => $x[0].','.$x[1])->implode(' ') }}" fill="none" stroke="#2563eb" stroke-width="3" stroke-dasharray="6 4" vector-effect="non-scaling-stroke"/>
            @elseif(isset($c['width']))
              @php($ch = ($c['max_width_mm'] ?? 0) && ($c['max_height_mm'] ?? 0) ? $c['width'] * $c['max_height_mm'] / $c['max_width_mm'] : $c['width'] * .6)
              <rect x="{{ $c['left'] }}" y="{{ $c['top'] }}" width="{{ $c['width'] }}" height="{{ $ch }}" fill="none" stroke="#2563eb" stroke-width="3" stroke-dasharray="6 4" vector-effect="non-scaling-stroke"/>
            @endif
          @endforeach
        </svg>
        @foreach($ps as $p)
          @php($q = is_array($p->quad) && count($p->quad) === 4 ? $p->quad : ($p->width ? (function ($p) { $h = $p->max_width_mm && $p->max_height_mm ? $p->width * $p->max_height_mm / $p->max_width_mm : $p->width * .6; return [[$p->left, $p->top], [$p->left + $p->width, $p->top], [$p->left + $p->width, $p->top + $h], [$p->left, $p->top + $h]]; })($p) : null))
          @if($q)
            @php($d = fn ($a, $b) => hypot($b[0] - $a[0], $b[1] - $a[1]))
            @php($aspect = $p->max_width_mm && $p->max_height_mm ? $p->max_width_mm / $p->max_height_mm : (($d($q[0], $q[3]) + $d($q[1], $q[2])) > 0 ? ($d($q[0], $q[1]) + $d($q[3], $q[2])) / ($d($q[0], $q[3]) + $d($q[1], $q[2])) : 1))
            <img class="tread-logo" alt="" data-quad="{{ json_encode($q) }}" data-aspect="{{ round($aspect, 3) }}" data-surface="{{ $p->surface ?: 'flat' }}" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
          @endif
        @endforeach
      </div>
    @empty
      <p class="small muted" style="margin:0">No photo of a side to place a print area on (guide cards).</p>
    @endforelse
    <table class="small tread-pos">
      @foreach($t->positions as $p)
        <tr><td>{{ $p->name }} <span class="muted">({{ $p->view }})</span></td>
          <td class="num">{{ $p->max_width_mm ? $p->max_width_mm.' × '.($p->max_height_mm ?: '?').' mm' : '–' }}</td>
          <td class="muted">{{ $p->surface && $p->surface !== 'flat' ? str_replace('_', ' ', $p->surface) : '' }}{{ $p->confidence === 'low' ? ' low confidence' : '' }}</td></tr>
        @if($p->methods)<tr><td colspan="3" class="muted" style="padding-top:0">{{ implode(', ', $p->methods) }}</td></tr>@endif
      @endforeach
    </table>
    @if($cmp)
      <p class="small c-blue" style="margin:0"><b>{{ $compare }}</b> (blue dashes){{ $cmp->message ? ': '.$cmp->message : '' }}</p>
      <table class="small tread-pos">
        @foreach($alt as $c)
          <tr><td>{{ $c['name'] }} <span class="muted">({{ $c['view'] }})</span></td>
            <td class="num">{{ ($c['max_width_mm'] ?? null) ? $c['max_width_mm'].' × '.($c['max_height_mm'] ?? '?').' mm' : '–' }}</td>
            <td class="muted">{{ ($c['surface'] ?? 'flat') !== 'flat' ? str_replace('_', ' ', (string) $c['surface']) : '' }}{{ ($c['confidence'] ?? null) === 'low' ? ' low confidence' : '' }}{{ empty($c['image_url']) ? ' no box' : '' }}</td></tr>
          @if(! empty($c['methods']))<tr><td colspan="3" class="muted">{{ implode(', ', $c['methods']) }}</td></tr>@endif
        @endforeach
      </table>
    @endif
  </div>
@endforeach
</div>
<div style="margin-top:12px">{{ $templates->links() }}</div>
<script src="{{ asset('js/template-reads.js') }}?v={{ filemtime(public_path('js/template-reads.js')) }}" defer></script>
@endsection
