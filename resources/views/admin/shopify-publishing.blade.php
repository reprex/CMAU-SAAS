@extends('layouts.app')
@section('title', 'Shopify publishing')
@section('content')
@php
  $fmt = fn ($n) => number_format((int) $n);
  $colour = ['ok' => '#1f7a3a', 'warn' => '#b06d00', 'bad' => '#b3202e'][$h['status']];
  $mins = $h['minutes'];
  $peak = max(1, collect($mins)->map(fn ($m) => $m['create'] + $m['update'] + $m['archive'] + $m['failed'])->max());
  $eta = $h['etaMinutes'];
@endphp
<meta http-equiv="refresh" content="60">
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
    <span class="eyebrow">Admin · Website</span>
    <h1>Shopify publishing</h1>
    <p class="muted" style="margin:0">How the range upload to {{ $h['store'] }} is going. Refreshes every minute. Staff see a warning at the top of every screen if it stalls.</p>
  </div>
  <form method="post" action="{{ route('admin.shopify-publishing.toggle') }}" class="row" style="gap:8px">@csrf
    <span class="small">Automatic publishing <b>{{ $h['on'] ? 'ON' : 'OFF' }}</b></span>
    <input type="hidden" name="on" value="{{ $h['on'] ? 0 : 1 }}">
    <button class="btn {{ $h['on'] ? '' : 'primary' }}" data-confirm="{{ $h['on'] ? 'Stop uploading to Shopify?' : 'Start uploading changed range products to '.$h['store'].'?' }}">{{ $h['on'] ? 'Turn off' : 'Turn on' }}</button>
  </form>
</div>

<div class="card" style="border-left:6px solid {{ $colour }}">
  <p style="margin:0;font-size:18px"><b style="color:{{ $colour }}">{{ ['ok' => 'Healthy', 'warn' => 'Attention', 'bad' => 'Problem'][$h['status']] }}</b> · {{ $h['headline'] }}</p>
  <p class="small muted" style="margin:6px 0 0">Last successful upload: {{ $h['lastOk'] ? \Illuminate\Support\Carbon::parse($h['lastOk'])->diffForHumans() : 'none yet' }} · cron last ran {{ $h['heartbeat'] ? \Illuminate\Support\Carbon::parse($h['heartbeat'])->diffForHumans() : 'never' }}</p>
</div>

<div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
  @foreach([
    ['On the store', $fmt($h['onStore']), 'range products'],
    ['New to upload', $fmt($h['waiting']['new']), 'not on Shopify yet'],
    ['Changes to send', $fmt($h['waiting']['changed']), 'already on Shopify'],
    ['To archive', $fmt($h['waiting']['archive']), 'off the range or discontinued'],
    ['Rate', number_format(array_sum($h['done15']->all()) / 15, 1).'/min', 'last 15 minutes'],
    ['Finish estimate', $h['todo'] === 0 ? 'Done' : ($eta === null ? '–' : ($eta < 90 ? $eta.' min' : now()->addMinutes($eta)->format('D g:ia'))), $h['todo'] === 0 ? 'nothing waiting' : ($eta === null ? 'no recent uploads to estimate from' : 'at the current rate')],
  ] as [$label, $value, $note])
    <div class="card" style="margin:0"><span class="small muted">{{ $label }}</span><div style="font-size:26px;font-weight:800">{{ $value }}</div><span class="small muted">{{ $note }}</span></div>
  @endforeach
</div>

<div class="card">
  <h2 style="margin-top:0">Last hour, per minute</h2>
  <div style="display:flex;align-items:flex-end;gap:2px;height:120px" role="img" aria-label="Uploads per minute over the last hour">
    @foreach($mins as $t => $m)
      @php($ok = $m['create'] + $m['update'] + $m['archive'])
      <div title="{{ substr($t, 11) }}: {{ $m['create'] }} new, {{ $m['update'] }} updated, {{ $m['archive'] }} archived, {{ $m['failed'] }} failed" style="flex:1;display:flex;flex-direction:column-reverse;height:100%">
        <span style="height:{{ round($m['create'] / $peak * 100) }}%;background:#1a1b18"></span>
        <span style="height:{{ round(($m['update'] + $m['archive']) / $peak * 100) }}%;background:#7d8a96"></span>
        <span style="height:{{ round($m['failed'] / $peak * 100) }}%;background:#b3202e"></span>
      </div>
    @endforeach
  </div>
  <div class="row between small muted"><span>{{ substr(array_key_first($mins), 11) }}</span><span><span style="display:inline-block;width:10px;height:10px;background:#1a1b18"></span> new &nbsp; <span style="display:inline-block;width:10px;height:10px;background:#7d8a96"></span> updated / archived &nbsp; <span style="display:inline-block;width:10px;height:10px;background:#b3202e"></span> failed · peak {{ $peak }}/min</span><span>now</span></div>
</div>

<div class="card" style="padding:6px 12px">
  <h2>Last 24 hours, per hour</h2>
  <table class="list small">
    <thead><tr><th>Hour</th><th class="num">New</th><th class="num">Updated</th><th class="num">Archived</th><th class="num">Failed</th></tr></thead>
    <tbody>
    @foreach(array_reverse($h['hours'], true) as $t => $m)
      @continue(array_sum($m) === 0)
      <tr><td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d H', $t)->format('D j M, ga') }}</td><td class="num">{{ $fmt($m['create']) }}</td><td class="num">{{ $fmt($m['update']) }}</td><td class="num">{{ $fmt($m['archive']) }}</td><td class="num">{!! $m['failed'] ? '<b style="color:#b3202e">'.$fmt($m['failed']).'</b>' : '0' !!}</td></tr>
    @endforeach
    </tbody>
  </table>
</div>

<div class="card" style="padding:6px 12px">
  <h2>Failed in the last day, not since fixed <span class="muted small">{{ $h['failures']->count() }}</span></h2>
  <p class="small muted" style="margin:0 0 6px">Each is tried again automatically an hour after it failed.</p>
  <table class="list small">
    <thead><tr><th>Product</th><th>When</th><th>Shopify said</th></tr></thead>
    <tbody>
    @forelse($h['failures'] as $f)
      <tr><td><a href="{{ route('range.show', $f->product_id) }}">{{ $f->name ?? 'Product '.$f->product_id }}</a> <span class="muted">{{ $f->product_id }} · {{ $f->action }}</span></td>
        <td style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($f->created_at)->diffForHumans() }}</td>
        <td>{{ \Illuminate\Support\Str::limit($f->message, 220) }}</td></tr>
    @empty
      <tr><td colspan="3" class="muted" style="padding:14px 8px">No failures.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
