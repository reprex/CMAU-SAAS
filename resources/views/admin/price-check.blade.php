@extends('layouts.app')
@section('title', 'Price check')
@section('content')
@php
  $verdicts = \App\Models\PriceCheck::VERDICTS;
  $flagNames = \App\Pricing\PricePatterns::FLAGS;
  $statusText = ['unverified' => 'To check', 'mixed' => 'Checks disagree'] + $verdicts;
  $statusPill = ['unverified' => '', 'mixed' => 'warn', 'base_branded' => 'ok', 'base_unbranded' => 'ok', 'differs' => 'warn'];
@endphp
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:820px">
    <span class="eyebrow">Admin · Pricing</span>
    <h1>Price check</h1>
    <p class="muted" style="margin:0">Promodata's base price includes branding for some products and not others, even from one supplier. Products are grouped into patterns by how their prices are built; a screengrab of the supplier's own distributor prices for one example settles the whole pattern. Flagged products are the ones that don't fit their pattern.</p>
  </div>
  <a class="btn" href="{{ route('admin.price-check.export', array_filter(['supplier' => $supplierId])) }}">Download screengrab list</a>
</div>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Supplier</th><th class="num">Patterns</th><th class="num">Verified</th><th class="num">Products</th><th class="num">On range</th><th class="num">Covered by a check</th><th class="num">Flagged</th></tr></thead>
    <tbody>
    @forelse($summary as $s)
      <tr>
        <td><a href="{{ route('admin.price-check.index', ['view' => $view, 'supplier' => $s->supplier_id]) }}">{{ $s->supplier }}</a></td>
        <td class="num">{{ number_format($s->patterns) }}</td>
        <td class="num">{{ number_format($s->verified) }}</td>
        <td class="num">{{ number_format($s->products) }}</td>
        <td class="num">{{ number_format($s->live) }}</td>
        <td class="num">{{ $s->products ? round(100 * $s->covered / $s->products) : 0 }}%</td>
        <td class="num">@if($s->flagged)<a href="{{ route('admin.price-check.index', ['view' => 'flagged', 'supplier' => $s->supplier_id]) }}">{{ number_format($s->flagged) }}</a>@else 0 @endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted" style="padding:20px 8px">No patterns yet. They are worked out when products are priced; after the TOD import run <span class="mono">pricing:patterns --assign</span> once.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>

<nav class="tabs" aria-label="Price check views">
  @foreach(\App\Http\Controllers\Admin\PriceCheckController::VIEWS as $key => $label)
    <a href="{{ route('admin.price-check.index', array_filter(['view' => $key, 'supplier' => $supplierId])) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }}</a>
  @endforeach
</nav>

<div class="row">
  <form method="get" class="row">
    <input type="hidden" name="view" value="{{ $view }}">
    <select name="supplier" aria-label="Supplier" style="width:240px" data-autosubmit><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($supplierId === $s->id)>{{ $s->name }}</option>@endforeach</select>
    @if($view === 'patterns')
      <select name="status" aria-label="Status" style="width:180px" data-autosubmit>
        <option value="open" @selected($status === 'open')>To check</option>
        <option value="done" @selected($status === 'done')>Verified</option>
        <option value="all" @selected($status === 'all')>All</option>
      </select>
    @endif
    <button class="btn">Show</button>
  </form>
  <form method="post" action="{{ route('admin.price-check.import') }}" enctype="multipart/form-data" class="row small" style="gap:6px;margin-left:auto">@csrf
    <label class="small muted" for="checks-file">Upload results (CSV: appaid, verdict, notes, evidence)</label>
    <input id="checks-file" type="file" name="file" accept=".csv,text/csv" required>
    <button class="btn small">Upload</button>
  </form>
</div>

@if($view === 'patterns')
<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Pattern</th><th class="num">Products</th><th class="num">On range</th><th class="num" title="Lowest-quantity branded base ÷ unbranded, median">Branded ÷ unbranded</th><th>Status</th><th style="width:440px">Screengrab these, then record what it showed</th></tr></thead>
    <tbody>
    @forelse($list as $pattern)
      @php($st = $statuses[$pattern->signature]['status'] ?? 'unverified')
      <tr>
        <td><b>{{ $pattern->supplier?->name }}</b><br><span class="small">{{ $pattern->label }}</span></td>
        <td class="num">{{ number_format($pattern->product_count) }}@if($pattern->flagged_count)<br><span class="small muted">{{ $pattern->flagged_count }} flagged</span>@endif</td>
        <td class="num">{{ number_format($pattern->live_count) }}</td>
        <td class="num">{{ $pattern->median_ratio ? number_format($pattern->median_ratio, 2) : '–' }}</td>
        <td><span class="pill {{ $statusPill[$st] ?? '' }}">{{ $statusText[$st] ?? $st }}</span>@isset($statuses[$pattern->signature])<br><span class="small muted">{{ $statuses[$pattern->signature]['checks'] }} check{{ $statuses[$pattern->signature]['checks'] === 1 ? '' : 's' }}</span>@endisset</td>
        <td>
          @foreach($pattern->examples ?? [] as $id)
            @if($p = $examples->get($id))
            <div style="margin-bottom:6px">
              <a href="{{ route('range.show', $p) }}">{{ $p->name }}</a> <span class="mono small muted">{{ $p->id }} · {{ $p->code }}</span>
              @if($p->detail?->supplier_website_page) · <a class="small" href="{{ $p->detail->supplier_website_page }}" target="_blank" rel="noopener noreferrer">supplier page</a>@endif
              <br><span class="small muted">Save as <span class="mono">{{ \App\Http\Controllers\Admin\PriceCheckController::screengrabName($p) }}</span></span>
              <form method="post" action="{{ route('admin.price-check.store') }}" class="row small" style="gap:4px;flex-wrap:nowrap;margin-top:3px">@csrf
                <input type="hidden" name="product_id" value="{{ $p->id }}">
                <select name="verdict" aria-label="Result for {{ $p->id }}" style="width:190px" required><option value="">Result…</option>@foreach($verdicts as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <input name="notes" placeholder="Notes" aria-label="Notes for {{ $p->id }}" style="width:130px">
                <button class="btn small">Save</button>
              </form>
            </div>
            @endif
          @endforeach
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:20px 8px">{{ $status === 'open' ? 'Nothing left to check here.' : 'No patterns match.' }}</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@elseif($view === 'flagged')
<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Product</th><th>Supplier</th><th>Status</th><th>Why it is flagged</th><th class="num">Branded ÷ unbranded</th></tr></thead>
    <tbody>
    @forelse($products as $p)
      <tr>
        <td><a href="{{ route('range.show', $p) }}"><b>{{ $p->name }}</b></a><br><span class="mono small muted">{{ $p->id }} · {{ $p->code }}</span></td>
        <td class="small">{{ $p->supplier?->name }}</td>
        <td class="small">{{ $p->tier === 'live' ? 'On range' : 'Kept' }}</td>
        <td class="small">{{ collect($flagNames)->filter(fn ($v, $k) => ! empty($p->price_flags[$k]))->implode('; ') }}</td>
        <td class="num">{{ isset($p->price_flags['ratio']) ? number_format($p->price_flags['ratio'], 2) : '–' }}</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted" style="padding:20px 8px">No flagged products.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $products->links('partials.pager') }}
@else
<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>When</th><th>Product</th><th>Result</th><th>Notes</th><th>Screengrab</th><th>By</th></tr></thead>
    <tbody>
    @forelse($checks as $c)
      <tr>
        <td class="small">{{ $c->created_at->format('j M Y') }}</td>
        <td>@if($c->product)<a href="{{ route('range.show', $c->product) }}">{{ $c->product->name }}</a>@endif <span class="mono small muted">{{ $c->product_id }}</span></td>
        <td class="small">{{ $verdicts[$c->verdict] ?? $c->verdict }}</td>
        <td class="small">{{ $c->notes }}</td>
        <td class="mono small">{{ $c->evidence }}</td>
        <td class="small">{{ $c->checker?->name }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:20px 8px">No checks recorded yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $checks->links('partials.pager') }}
@endif
@endsection
