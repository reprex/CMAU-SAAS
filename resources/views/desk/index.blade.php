@extends('layouts.app')
@section('title', 'Desk')
@section('content')
<div class="row between">
  <div><div class="eyebrow">CrowdMerch · {{ now()->timezone(config('app.timezone'))->format('l j F') }}</div><h1>Desk</h1></div>
  @if(auth()->user()->canEdit())<a class="btn" href="{{ route('quotes.create') }}">+ Phone or email quote</a>@endif
</div>
<p class="muted" style="margin:6px 0 0;max-width:80ch">Everything from CMAU_DEV lands here: quote requests and instant quotes, checkouts, samples, project and sourcing requests, and change requests from My Orders. Each line shows the one thing it needs next.</p>

<div class="dstages" role="list" aria-label="Jobs by stage">
  @foreach($counts as $key => $c)
    <div class="dstage {{ $c['hot'] ? 'hot' : '' }}" role="listitem"><b>{{ $c['n'] }}</b><span>{{ $c['label'] }}</span></div>
  @endforeach
</div>

<nav class="dtabs" aria-label="Filter">
  @foreach($tabs as $key => $t)
    <a href="{{ route('desk', ['tab' => $key]) }}" class="{{ $tab === $key ? 'on' : '' }}" @if($tab === $key) aria-current="page" @endif>{{ $t['label'] }} ({{ $t['n'] }})</a>
  @endforeach
</nav>

<div class="djobs">
  @forelse($jobs as $j)
    <div class="djob {{ $j['urgent'] ? 'urgent' : '' }}">
      <a class="ref mono" href="{{ $j['url'] }}">{{ $j['ref'] }}</a>
      <div class="what"><span class="src {{ $j['kind'] }}">{{ $j['source'] }}</span><a href="{{ $j['url'] }}"><b>{{ $j['client'] }}</b></a><span>{{ $j['summary'] ?: $j['title'] }}</span></div>
      <div class="due">
        @if($j['need_by'])<span class="pill {{ $j['urgent'] ? 'bad' : '' }}">Need by {{ $j['need_by']->format('D j M') }}</span>@endif
        <div class="muted small">{{ $j['when'] }}</div>
      </div>
      <div class="val">{{ $j['value'] ? '$'.number_format($j['value'], 2) : '–' }}</div>
      <div class="act">@if($j['action'])@include('desk._action', ['action' => $j['action'], 'class' => $j['needs'] ? 'primary small' : 'small'])@else<span class="muted small">{{ $j['title'] }}</span>@endif</div>
    </div>
  @empty
    <div class="card"><p class="muted" style="margin:0">Nothing here. {{ $tab === 'needs' ? 'Everything is waiting on clients or suppliers.' : '' }}</p></div>
  @endforelse
</div>
@endsection
