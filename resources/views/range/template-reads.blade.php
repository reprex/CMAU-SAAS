@extends('layouts.app')
@section('title', 'Template reads')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Branding templates</span>
  <h1>Template reads</h1>
  <p class="muted" style="margin:0">What Claude read from each supplier template, newest first ({{ number_format($total) }} read). Each print area is drawn on the photo it was placed on: <b class="c-green">green</b> the logo's corners (it follows angle and curve), <b class="c-orange">orange</b> a plain box. A position with no photo of its side shows its size only (the website shows a guide card).</p>
</div>
<form method="get" class="row" style="gap:8px;margin:12px 0">
  <select name="supplier" data-autosubmit><option value="">All suppliers</option>@foreach($suppliers as $id => $name)<option value="{{ $id }}" @selected($supplier === $id)>{{ $name }}</option>@endforeach</select>
  <label class="small"><input type="checkbox" name="only" value="boxes" @checked(request('only') === 'boxes') data-autosubmit> Only with a print area on a photo</label>
</form>
<div class="treads">
@foreach($templates as $t)
  @php($byImage = $t->positions->whereNotNull('image_url')->groupBy('image_url'))
  <div class="card tread">
    <div class="row between"><a href="{{ route('range.show', $t->product_id) }}"><b>{{ \Illuminate\Support\Str::limit($t->product?->name, 40) }}</b></a><span class="small muted">{{ $t->read_at?->diffForHumans() }}</span></div>
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
        </svg>
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
  </div>
@endforeach
</div>
<div style="margin-top:12px">{{ $templates->links() }}</div>
@endsection
