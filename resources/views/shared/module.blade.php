@extends('layouts.app')
@section('title', $info['title'])
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:760px">
  <span class="eyebrow">{{ $info['eyebrow'] }}</span>
  <h1>{{ $info['title'] }}</h1>
  <p style="margin:0">{{ $info['what'] }}</p>
  <p class="muted" style="margin:0">{{ $info['from'] }}</p>
</div>
@endsection
