@extends('layouts.app')
@section('title', 'Wish lists & intake')
@section('content')
<div><div class="eyebrow">Shopify draft orders</div><h1>Wish lists &amp; intake</h1></div>
<p class="muted" style="margin:0;max-width:760px">Draft orders with an email address become quotes automatically. These are the rest: anonymous wish lists (no email, mostly automated traffic) and anything that failed to import. Open one as a quote if it looks real.</p>

@if($byDay->isNotEmpty())
<div class="card">
  <h2>Anonymous wish lists per day, last 90 days</h2>
  @php($max = max(1, $byDay->max()))
  <div style="display:flex;align-items:flex-end;gap:3px;height:90px" role="img" aria-label="Anonymous wish lists per day">
    @foreach($byDay as $day => $n)<div title="{{ $day }}: {{ $n }}" style="flex:1;background:#B8440E;opacity:.8;border-radius:2px 2px 0 0;height:{{ max(2, round(88 * $n / $max)) }}px"></div>@endforeach
  </div>
  <div class="small muted">{{ number_format($byDay->sum()) }} in total; busiest day {{ number_format($byDay->max()) }}.</div>
</div>
@endif

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Draft</th><th>Created</th><th>First line</th><th>Lines</th><th>State</th><th></th></tr></thead>
    <tbody>
    @forelse($drafts as $d)
      <tr>
        <td class="mono">{{ $d->name }}</td>
        <td class="small">{{ $d->shopify_created_at?->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</td>
        <td class="small">{{ \Illuminate\Support\Str::limit($d->first_line, 70) }}</td>
        <td>{{ $d->line_count }}</td>
        <td>@if($d->error)<span class="pill bad" title="{{ $d->error }}">import failed</span>@else<span class="pill">anonymous</span>@endif</td>
        <td>@if(auth()->user()->canEdit())<form method="post" action="{{ route('drafts.convert', $d) }}">@csrf<button class="btn small" type="submit">{{ $d->error ? 'Try again' : 'Open as quote' }}</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:24px 8px">Nothing waiting.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $drafts->links('partials.pager') }}
@endsection
