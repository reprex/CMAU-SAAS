@extends('layouts.app')
@section('title', 'Decoration types')
@section('content')
@php($types = \App\Http\Controllers\Admin\DecorationTypeController::TYPES)
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:780px">
    <span class="eyebrow">Admin · Pricing rules</span>
    <h1>Decoration types</h1>
    <p class="muted" style="margin:0">Each supplier price-line description is classified once (TOD's DecTypeCode): <b>10</b> decoration, <b>11</b> accessory, <b>12</b> undecorated, <b>23</b> hidden. The type picks the markup and whether the option appears in DecorationJSON. New descriptions from Promodata arrive unclassified; saving one reprices every product using it.</p>
  </div>
</div>

<div class="row">
  <form method="get" class="row" role="search"><input type="hidden" name="show" value="{{ $show }}"><input type="search" name="q" value="{{ $q }}" placeholder="Description" aria-label="Search descriptions" style="width:260px"></form>
  <div class="seg">
    <a href="{{ route('admin.dectypes.index', ['show' => 'live']) }}" class="{{ $show === 'live' ? 'on' : '' }}" title="Not classified and used by a product on the web range, most used first">To classify · on the range ({{ $todoLive }})</a>
    <a href="{{ route('admin.dectypes.index', ['show' => 'todo']) }}" class="{{ $show === 'todo' ? 'on' : '' }}">To classify · all ({{ $todo }})</a>
    <a href="{{ route('admin.dectypes.index', ['show' => 'all']) }}" class="{{ $show === 'all' ? 'on' : '' }}">All</a>
  </div>
</div>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Description</th><th>First seen at</th><th class="num">Live products</th><th style="width:420px">Type and web wording</th></tr></thead>
    <tbody>
    @forelse($codes as $c)
      <tr>
        <td><b>{{ $c->full_description ?: $c->code }}</b><br><span class="mono small muted">{{ $c->code }}</span>@if($c->promodata_decoration)<br><span class="small muted">Promodata: {{ $c->promodata_decoration }}</span>@endif
          @php($ex = $examples[$c->code] ?? collect())
          @if($ex->isNotEmpty())
            <div class="small" style="margin-top:8px;display:flex;flex-direction:column;gap:3px">
              @foreach($ex as $e)
                <span><a href="{{ route('range.show', $e->product_id) }}" target="_blank" rel="noopener">{{ $e->name }}</a>
                  <span class="muted">{{ collect([$e->product_code, $e->promodata_type_name, $e->tier === 'live' ? 'on the range' : null])->filter()->implode(' · ') }}</span>
                  @if($e->price_string)<span class="mono muted" title="Qty:price for this line">· {{ \Illuminate\Support\Str::limit($e->price_string, 60) }}@if((float) $e->setup_price > 0) + ${{ number_format($e->setup_price, 2) }} setup @endif</span>@endif</span>
              @endforeach
              @if(($siblings[$c->code] ?? collect())->isNotEmpty())
                <details><summary class="muted">Other price lines on {{ $ex->first()->name }}</summary>
                  <ul style="margin:4px 0 0 16px;padding:0">
                  @foreach($siblings[$c->code] as $sib)
                    <li>{{ \Illuminate\Support\Str::limit($sib->description, 90) }} <span class="muted">· {{ $sib->type_code ? $types[$sib->type_code] ?? $sib->type_code : 'not classified' }}@if($sib->price_string) · <span class="mono">{{ \Illuminate\Support\Str::limit($sib->price_string, 40) }}</span>@endif</span></li>
                  @endforeach
                  </ul>
                </details>
              @endif
            </div>
          @else
            <div class="small muted" style="margin-top:6px">No stored product uses it now.</div>
          @endif
        </td>
        <td class="small">{{ $c->first_supplier_name }}</td>
        <td class="num">{{ $usage[$c->code] ?? 0 }}</td>
        <td>
          <form method="post" action="{{ route('admin.dectypes.update', $c) }}" class="row" style="gap:6px;flex-wrap:nowrap">@csrf @method('PUT')
            <select name="type_code" aria-label="Type for {{ $c->code }}" style="width:150px"><option value="">Not classified</option>@foreach($types as $code => $name)<option value="{{ $code }}" @selected($c->type_code === $code)>{{ $code }} {{ $name }}</option>@endforeach</select>
            <input name="overwrite_description" value="{{ $c->overwrite_description }}" placeholder="Web wording (optional)" aria-label="Web wording for {{ $c->code }}">
            <button class="btn small">Save</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted" style="padding:24px 8px">{{ $show === 'live' ? 'Nothing on the range is waiting to be classified.' : ($show === 'todo' ? 'Nothing to classify.' : 'No descriptions match.') }}</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $codes->links('partials.pager') }}
@endsection
