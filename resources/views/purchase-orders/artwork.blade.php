@extends('layouts.guest')
@section('title', 'Artwork '.$po->number)
@section('content')
<h1 style="margin:4px 0 6px">Artwork for {{ $po->number }}</h1>
<p class="small muted" style="margin:0 0 10px">From CrowdMerch. Please blind ship: show the sender as Replicat / CrowdMerch and do not put any supplier identification or docs with the shipment.</p>
@forelse($positions->values() as $n => $p)
  <div style="border-top:1px solid var(--line-soft);padding:8px 0">
    <b>{{ $p->position }}</b> · {{ $p->decoration_name ?: $p->methodName() }}@if($p->colours) · {{ $p->colours }} colours @endif @if($p->width_mm) · {{ $p->width_mm }} mm wide @endif
    <br>@if($p->logo_path)<a href="{{ route('po-artwork.file', [$po->artwork_token, $n]) }}">Download {{ $p->logo_name ?: 'artwork' }}</a>@else <span class="muted">No file: we'll send it separately.</span>@endif
  </div>
@empty
  <p>No artwork on this order.</p>
@endforelse
@endsection
