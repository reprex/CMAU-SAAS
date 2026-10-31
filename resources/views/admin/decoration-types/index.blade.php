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
    <a href="{{ route('admin.dectypes.index', ['show' => 'todo']) }}" class="{{ $show === 'todo' ? 'on' : '' }}">To classify ({{ $todo }})</a>
    <a href="{{ route('admin.dectypes.index', ['show' => 'all']) }}" class="{{ $show === 'all' ? 'on' : '' }}">All</a>
  </div>
</div>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Description</th><th>First seen at</th><th class="num">Live products</th><th style="width:420px">Type and web wording</th></tr></thead>
    <tbody>
    @forelse($codes as $c)
      <tr>
        <td><b>{{ $c->full_description ?: $c->code }}</b><br><span class="mono small muted">{{ $c->code }}</span>@if($c->promodata_decoration)<br><span class="small muted">Promodata: {{ $c->promodata_decoration }}</span>@endif</td>
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
      <tr><td colspan="4" class="muted" style="padding:24px 8px">{{ $show === 'todo' ? 'Nothing to classify.' : 'No descriptions match.' }}</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $codes->links('partials.pager') }}
@endsection
