@extends('layouts.app')
@section('title', 'Template reads')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Branding templates</span>
  <h1>Template reads</h1>
  <p class="muted" style="margin:0">What Claude read from each supplier template, newest first ({{ number_format($total) }} read). Each print area is drawn on the photo it was placed on: <b style="color:#0a7f3f">green</b> the logo's corners (it follows angle and curve), <b style="color:#b45309">orange</b> a plain box. A position with no photo of its side shows its size only (the website shows a guide card).</p>
</div>
<form method="get" class="row" style="gap:8px;margin:12px 0">
  <select name="supplier" data-autosubmit><option value="">All suppliers</option>@foreach($suppliers as $id => $name)<option value="{{ $id }}" @selected($supplier === $id)>{{ $name }}</option>@endforeach</select>
  <label class="small"><input type="checkbox" name="only" value="boxes" @checked(request('only') === 'boxes') data-autosubmit> Only with a print area on a photo</label>
</form>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">
@foreach($templates as $t)
  @php($byImage = $t->positions->whereNotNull('image_url')->groupBy('image_url'))
  <div class="card" style="padding:10px;display:flex;flex-direction:column;gap:8px">
    <div class="row between"><a href="{{ route('range.show', $t->product_id) }}"><b>{{ \Illuminate\Support\Str::limit($t->product?->name, 40) }}</b></a><span class="small muted">{{ $t->read_at?->diffForHumans() }}</span></div>
    @forelse($byImage as $url => $ps)
      <div style="position:relative;width:100%;aspect-ratio:1/1;background:#f5f5f5;border-radius:6px;overflow:hidden">
        <img src="{{ $url }}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:100%">
          @foreach($ps as $p)
            @if(is_array($p->quad) && count($p->quad) === 4)
              <polygon points="{{ collect($p->quad)->map(fn ($c) => $c[0].','.$c[1])->implode(' ') }}" fill="rgba(10,127,63,.15)" stroke="#0a7f3f" stroke-width=".6" vector-effect="non-scaling-stroke"/>
            @elseif($p->width)
              @php($h = $p->max_width_mm && $p->max_height_mm ? $p->width * $p->max_height_mm / $p->max_width_mm : $p->width * .6)
              <rect x="{{ $p->left }}" y="{{ $p->top }}" width="{{ $p->width }}" height="{{ $h }}" fill="rgba(180,83,9,.15)" stroke="#b45309" stroke-width=".6" vector-effect="non-scaling-stroke"/>
            @endif
          @endforeach
        </svg>
      </div>
    @empty
      <p class="small muted" style="margin:0">No photo of a side to place a print area on (guide cards).</p>
    @endforelse
    <table class="small" style="width:100%">
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
