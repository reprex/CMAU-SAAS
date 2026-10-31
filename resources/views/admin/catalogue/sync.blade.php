@extends('layouts.app')
@section('title', 'Promodata sync')
@section('content')
@php
  $ago = fn ($t) => $t ? $t->diffForHumans() : 'never';
  $beatOk = $heartbeat && $heartbeat->gt(now()->subMinutes(3));
@endphp
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:760px">
    <span class="eyebrow">Admin · Promodata → SaaS</span>
    <h1>Promodata sync</h1>
    <p class="muted" style="margin:0">Every supplier product is kept as a one-line summary. Products on the range, or used in the last 12 months, are kept in full and repriced whenever Promodata changes them. A "changed since" sync runs every night at 1:30; the scheduler works through {{ $pagesPerStep }} pages a minute.</p>
  </div>
  <form method="post" action="{{ route('admin.sync.check') }}">@csrf<button class="btn">Test the Promodata connection</button></form>
</div>

@if(! $tokenSet)
  <div class="errors" role="alert"><b>No Promodata token.</b> Put it in <span class="mono">saas-app/.env</span> as PROMODATA_TOKEN, then run config:cache.</div>
@endif
@if(! $beatOk)
  <div class="errors" role="alert"><b>The scheduler isn't running</b> (last run: {{ $ago($heartbeat) }}). Syncs, background jobs and the draft-order pull wait until the one-minute cron works again. Check cPanel → Cron Jobs and that <span class="mono">/usr/local/bin/ea-php84 -v</span> shows PHP 8.4.</div>
@endif

<div class="stats">
  <div class="stat"><b>{{ number_format($tiers['live'] ?? 0) }}</b><span>on the range (live)</span></div>
  <div class="stat"><b>{{ number_format($tiers['active'] ?? 0) }}</b><span>recently used, kept in full</span></div>
  <div class="stat"><b>{{ number_format($tiers['summary'] ?? 0) }}</b><span>summary only</span></div>
  <div class="stat"><b>{{ number_format($suppliers) }}</b><span>suppliers</span></div>
  <div class="stat"><b>{{ $ago($lastDone?->finished_at) }}</b><span>last completed sync</span></div>
  <div class="stat"><b>{{ $beatOk ? 'Running' : 'Stopped' }}</b><span>scheduler · last beat {{ $ago($heartbeat) }}</span></div>
</div>

@if($unclassified || $repriceErrors || $failedJobs)
<div class="card">
  <h2>Needs attention</h2>
  <ul style="margin:0;padding-left:18px">
    @if($unclassified)<li><a href="{{ route('admin.dectypes.index') }}">{{ $unclassified }} decoration description{{ $unclassified === 1 ? '' : 's' }}</a> from Promodata {{ $unclassified === 1 ? 'is' : 'are' }} not classified yet. Until they are, those options are priced as accessories and left out of DecorationJSON.</li>@endif
    @if($repriceErrors)<li><a href="{{ route('range.index', ['view' => 'problems']) }}">{{ $repriceErrors }} live product{{ $repriceErrors === 1 ? '' : 's' }}</a> could not be priced.</li>@endif
    @if($failedJobs)<li>{{ $failedJobs }} background job{{ $failedJobs === 1 ? '' : 's' }} failed (see storage/logs).</li>@endif
  </ul>
</div>
@endif

@if($open)
<div class="card">
  <div class="row between"><h2>Sync #{{ $open->id }} · {{ $open->kind }}{{ $open->supplier_id ? ' · supplier '.$open->supplier_id : '' }}</h2>
    <form method="post" action="{{ route('admin.sync.cancel', $open) }}">@csrf<button class="btn small danger">Stop</button></form></div>
  @php($pct = $open->total_pages ? min(100, round(($open->next_page - 1) / $open->total_pages * 100)) : 0)
  <div style="background:#EFE8DC;border-radius:6px;height:12px;overflow:hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div style="width:{{ $pct }}%;height:100%;background:var(--accent)"></div></div>
  <div class="small muted">Page {{ $open->next_page - 1 }} of {{ $open->total_pages ?? '?' }} · {{ number_format($open->products_seen) }} products read · {{ number_format($open->products_changed) }} new or changed
    @if($open->total_pages && $pagesPerStep) · @php($mins = max(1, (int) ceil(($open->total_pages - $open->next_page + 1) / $pagesPerStep))) about {{ $mins }} minute{{ $mins === 1 ? '' : 's' }} to go @endif
    · {{ $queued }} background job{{ $queued === 1 ? '' : 's' }} waiting</div>
  @if($open->error)<div class="small" style="color:var(--bad)">Last error (retried automatically): {{ \Illuminate\Support\Str::limit($open->error, 240) }}</div>@endif
</div>
@endif

<div class="cols">
  <div class="main">
    <div class="card" style="padding:6px 12px">
      <table class="list">
        <thead><tr><th>#</th><th>Kind</th><th>Status</th><th class="num">Pages</th><th class="num">Read</th><th class="num">Changed</th><th>Started</th><th>Finished</th></tr></thead>
        <tbody>
        @forelse($runs as $r)
          <tr>
            <td class="mono">{{ $r->id }}</td>
            <td>{{ $r->kind }}@if($r->supplier_id) <span class="muted small">supplier {{ $r->supplier_id }}</span>@endif @if($r->since)<br><span class="muted small">since {{ $r->since->format('j M H:i') }}</span>@endif</td>
            <td><span class="pill {{ ['completed' => 'ok', 'failed' => 'bad', 'running' => 'accent'][$r->status] ?? '' }}">{{ $r->status }}</span>@if($r->error && $r->status === 'failed')<br><span class="small muted">{{ \Illuminate\Support\Str::limit($r->error, 90) }}</span>@endif</td>
            <td class="num">{{ max(0, $r->next_page - 1) }}/{{ $r->total_pages ?? '?' }}</td>
            <td class="num">{{ number_format($r->products_seen) }}</td>
            <td class="num">{{ number_format($r->products_changed) }}</td>
            <td class="small">{{ $r->started_at?->format('j M H:i') ?? 'waiting' }}</td>
            <td class="small">{{ $r->finished_at?->format('j M H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="8" class="muted" style="padding:24px 8px">No syncs yet. Start a full sync to load every Promodata product as a summary.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="side">
    <form method="post" action="{{ route('admin.sync.start') }}" class="card">@csrf
      <h2>Start a sync</h2>
      <label class="check"><input type="radio" name="kind" value="changed" checked> <span><b>Changes since the last sync</b><br><span class="small muted">The usual one; also runs nightly.</span></span></label>
      <label class="f">or changes since<input type="datetime-local" name="since"></label>
      <label class="check"><input type="radio" name="kind" value="supplier"> <span><b>One supplier, all products</b></span></label>
      <select name="supplier_id" aria-label="Supplier"><option value="">Choose a supplier…</option>@foreach($supplierList as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->id }})</option>@endforeach</select>
      <label class="check"><input type="radio" name="kind" value="full"> <span><b>Full sync</b><br><span class="small muted">Every Promodata product. First load, or to repair. Takes a few hours in the background.</span></span></label>
      <button class="btn primary">Start</button>
    </form>
    <form method="post" action="{{ route('admin.sync.product') }}" class="card">@csrf
      <h2>Fetch one product now</h2>
      <p class="small muted" style="margin:0">Gets the latest data for one APPAID straight away, prices it and opens it.</p>
      <div class="row"><input name="product_id" inputmode="numeric" placeholder="APPAID, e.g. 25384" aria-label="Product id" style="width:170px" required><button class="btn">Fetch</button></div>
    </form>
  </div>
</div>
@endsection
