@extends('layouts.app')
@section('title', 'Search log')
@section('content')
@php
  $modeText = ['keyword' => 'Keyword', 'ai' => 'Claude', 'cached' => 'Claude (cached)', 'blocked' => 'Blocked'];
  $reasons = \App\Models\SearchLog::REASONS;
  $total = $byMode->sum('n');
  $tin = $byMode->sum('tin'); $tout = $byMode->sum('tout');
@endphp
<div style="display:flex;flex-direction:column;gap:6px;max-width:820px">
  <span class="eyebrow">Admin · Website</span>
  <h1>Search log</h1>
  <p class="muted" style="margin:0">Every step 1 search on the website: how it was answered and why. Bots are stopped before keyword search; Claude is only used for real visitors with brief-style requests, within the limits.</p>
</div>

<form method="get" class="row">
  <select name="days" aria-label="Period" style="width:160px" data-autosubmit>
    @foreach([1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label)<option value="{{ $d }}" @selected($days === $d)>{{ $label }}</option>@endforeach
  </select>
  <select name="mode" aria-label="Answered by" style="width:180px" data-autosubmit>
    <option value="">All searches</option>
    @foreach($modeText as $k => $label)<option value="{{ $k }}" @selected($mode === $k)>{{ $label }}</option>@endforeach
  </select>
  <button class="btn">Show</button>
</form>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Searches</th><th class="num">Visitors</th>@foreach($modeText as $k => $label)<th class="num">{{ $label }}</th>@endforeach<th class="num">No results</th><th class="num">Verified bots</th><th class="num">Claude tokens in / out</th></tr></thead>
    <tbody><tr>
      <td><b>{{ number_format($total) }}</b></td>
      <td class="num">{{ number_format($visitors) }}</td>
      @foreach($modeText as $k => $label)<td class="num">{{ number_format($byMode[$k]->n ?? 0) }}</td>@endforeach
      <td class="num">{{ number_format($empty) }}</td>
      <td class="num">{{ number_format($agents) }}</td>
      <td class="num">{{ number_format($tin) }} / {{ number_format($tout) }}</td>
    </tr></tbody>
  </table>
</div>

@if($byReason->isNotEmpty())
<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Why</th><th>Answered by</th><th class="num">Searches</th></tr></thead>
    <tbody>
    @foreach($byReason as $r)
      <tr><td>{{ $reasons[$r->reason] ?? $r->reason }}</td><td>{{ $modeText[$r->mode] ?? $r->mode }}</td><td class="num">{{ number_format($r->n) }}</td></tr>
    @endforeach
    </tbody>
  </table>
</div>
@endif

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>When</th><th>Search</th><th>Answered by</th><th>Why</th><th class="num">Results</th><th class="num">ms</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
      <tr>
        <td class="small">{{ $row->created_at->timezone(config('app.timezone'))->format('j M H:i') }}<br><span class="mono muted">{{ $row->visitor }}</span></td>
        <td>{{ $row->query }}@if($row->agent) <span class="pill">verified bot</span>@endif</td>
        <td><span class="pill {{ $row->mode === 'blocked' ? 'warn' : ($row->mode === 'keyword' ? '' : 'ok') }}">{{ $modeText[$row->mode] ?? $row->mode }}</span></td>
        <td class="small">{{ $row->reason ? ($reasons[$row->reason] ?? $row->reason) : '' }}</td>
        <td class="num">{{ count($row->result_ids ?? []) }}</td>
        <td class="num">{{ $row->ms }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:20px 8px">No searches in this period yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
