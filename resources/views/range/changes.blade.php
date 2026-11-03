@extends('layouts.app')
@section('title', 'Changes to vet')
@section('content')
@php($isAdmin = auth()->user()->isAdmin())
<div class="row between" style="align-items:flex-end">
  <div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
    <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · Changes to vet</span>
    <h1>Changes to vet</h1>
    <p class="muted" style="margin:0">New products from suppliers set to suggest them (Admin › Add a whole supplier), fetched, priced and checked against similar products on the range. <b>Nothing goes on the range or to Shopify until approved.</b> Discontinued products are listed with any quote from the last {{ \App\Catalogue\RangeChanges::QUOTE_MONTHS }} months not yet ordered, so you can offer those clients a substitute.</p>
  </div>
</div>

<nav class="tabs" aria-label="Views">
  @foreach(\App\Http\Controllers\RangeChangesController::VIEWS as $key => $label)
    <a href="{{ route('range.changes', ['view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }}@isset($counts[$key]) <span class="muted small">{{ number_format($counts[$key]) }}</span>@endisset</a>
  @endforeach
</nav>
@if($counts['preparing'])
  <p class="small muted" style="margin:0">{{ number_format($counts['preparing']) }} more being fetched and checked (about 10 every 5 minutes).</p>
@endif

@if(in_array($view, ['ready', 'check'], true))
<form method="post" action="{{ route('range.changes.decide') }}">@csrf
  @if($isAdmin)
  <div class="row small" style="gap:8px">
    @if($view === 'ready')
      <button class="btn primary" name="action" value="approve_ready" @disabled(! $counts['ready']) data-confirm="Approve all {{ $counts['ready'] }} ready products onto the range? They are then sent to Shopify.">Approve all {{ number_format($counts['ready']) }} ready</button>
    @endif
    <button class="btn" name="action" value="approve">Approve ticked</button>
    <button class="btn" name="action" value="decline" data-confirm="Decline the ticked products? They won't be suggested again.">Decline ticked</button>
    <span class="muted">{{ $view === 'ready' ? 'Every check passed: priced the same way as similar products on the range, with branding positions, pictures and a category.' : 'One or more checks need a look: the amber chips say why. Open a product to fix it, then check it again.' }}</span>
  </div>
  @endif
  <div class="card" style="padding:6px 12px">
    <table class="list">
      <thead><tr>
        <th style="width:28px">@if($isAdmin)<input type="checkbox" data-check-all="ids[]" aria-label="Tick all">@endif</th>
        <th style="width:56px"></th><th>Product</th><th class="num">Price each</th><th class="num">Usual</th><th>Checks</th><th></th>
      </tr></thead>
      <tbody>
      @forelse($rows as $r)
        @php($p = $r->product)
        @php($c = $r->checks ?? [])
        <tr>
          <td>@if($isAdmin)<input type="checkbox" name="ids[]" value="{{ $r->id }}" aria-label="Tick {{ $p?->name }}">@endif</td>
          <td>@if($p?->hero_image_url)<img src="{{ $p->hero_image_url }}" alt="" loading="lazy" style="width:48px;height:48px;object-fit:contain;border-radius:8px;background:#fff">@endif</td>
          <td><a href="{{ route('range.show', $r->product_id) }}"><b>{{ $p?->name ?? 'Product '.$r->product_id }}</b></a>
            <div class="small muted">{{ collect([$p?->supplier?->name, $p?->code, $p?->promodata_type_name])->filter()->implode(' · ') }}</div></td>
          <td class="num">{{ isset($c['price']) && $c['price'] ? '$'.number_format($c['price'], 2) : '–' }}@if($p?->webPrice?->collection_moq)<div class="small muted">MOQ {{ $p->webPrice->collection_moq }}</div>@endif</td>
          <td class="num">{{ isset($c['usual']) && $c['usual'] ? '$'.number_format($c['usual'], 2) : '–' }}</td>
          <td class="small">
            @if($r->error)<span class="pill warn" title="{{ $r->error }}">Couldn't check: {{ \Illuminate\Support\Str::limit($r->error, 60) }}</span>@endif
            @foreach($c['checks'] ?? [] as $k)
              <span class="pill {{ $k['ok'] ? 'ok' : 'warn' }}" title="{{ $k['detail'] }}">{{ $k['label'] }}</span>
            @endforeach
            @if($view === 'check')
              <div class="muted" style="margin-top:4px">{{ collect($c['checks'] ?? [])->reject(fn ($k) => $k['ok'])->pluck('detail')->implode(' ') }}</div>
            @endif
          </td>
          <td><button class="btn small" form="recheck-{{ $r->id }}">Check again</button></td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted" style="padding:20px 8px">{{ $view === 'ready' ? 'No new products ready to approve.' : 'Nothing needs a look.' }}</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</form>
@foreach($rows as $r)<form method="post" action="{{ route('range.changes.recheck', $r) }}" id="recheck-{{ $r->id }}" hidden>@csrf</form>@endforeach

@elseif($view === 'discontinued')
<form method="post" action="{{ route('range.changes.decide') }}">@csrf
  @if($isAdmin)
  <div class="row small" style="gap:8px">
    <button class="btn" name="action" value="done">Mark ticked as dealt with</button>
    <span class="muted">Products on the range are archived on Shopify automatically, so nobody can order them. Products with open quotes come first.</span>
  </div>
  @endif
  @forelse($rows as $r)
    @php($p = $r->product)
    @php($items = $quotes->get($r->product_id, collect()))
    <div class="card" style="{{ $items->isNotEmpty() ? 'border-left:4px solid var(--warn, #c77700)' : '' }}">
      <div class="row between" style="align-items:flex-start;gap:12px">
        <label class="row" style="gap:10px;align-items:flex-start">
          @if($isAdmin)<input type="checkbox" name="ids[]" value="{{ $r->id }}" aria-label="Tick {{ $p?->name }}">@endif
          @if($p?->hero_image_url)<img src="{{ $p->hero_image_url }}" alt="" loading="lazy" style="width:48px;height:48px;object-fit:contain;border-radius:8px;background:#fff">@endif
          <span><b>{{ $p?->name ?? 'Product '.$r->product_id }}</b>
            <span class="small muted" style="display:block">{{ collect([$p?->supplier?->name, $p?->code, 'APPAID '.$r->product_id, $p?->discontinued_at ? 'discontinued '.$p->discontinued_at->format('j M Y') : null])->filter()->implode(' · ') }}</span>
            <span class="small">@if($r->checks['was_live'] ?? false)<span class="pill">Was on the range</span>@endif
              @if($items->isNotEmpty())<span class="pill warn">{{ $items->pluck('quote_id')->unique()->count() }} open {{ \Illuminate\Support\Str::plural('quote', $items->pluck('quote_id')->unique()->count()) }}</span>@endif</span></span>
        </label>
        <a class="btn small" href="{{ route('range.substitutes', $r->product_id) }}">Find substitutes</a>
      </div>
      @if($items->isNotEmpty())
        <table class="list small" style="margin-top:8px">
          <thead><tr><th>Quote</th><th>Client</th><th>Status</th><th class="num">Qty</th><th>Date</th></tr></thead>
          <tbody>
          @foreach($items as $i)
            <tr>
              <td><a href="{{ route('quotes.show', $i->quote_id) }}"><b>{{ $i->quote?->number }}</b></a></td>
              <td>{{ collect([$i->quote?->client_company, $i->quote?->client_name])->filter()->implode(' · ') ?: '–' }}@if($i->quote?->client_email)<div class="muted">{{ $i->quote->client_email }}</div>@endif</td>
              <td>{{ \App\Models\Quote::STATUSES[$i->quote?->status] ?? $i->quote?->status }}</td>
              <td class="num">{{ number_format($i->quantity) }}</td>
              <td>{{ ($i->quote?->sent_at ?? $i->quote?->created_at)?->format('j M') }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
      @php($suggested = collect($r->checks['substitutes'] ?? [])->map(fn ($id) => $subs->get($id))->filter())
      @if($suggested->isNotEmpty())
        <p class="small" style="margin:8px 0 0"><span class="muted">Closest substitutes:</span>
          @foreach($suggested as $s)
            <a href="{{ route('range.show', $s) }}">{{ $s->name }}</a> <span class="muted">({{ $s->code }}{{ $s->tier === 'live' ? ', on the range' : '' }}{{ $s->webPrice?->collection_variant_price ? ', $'.number_format($s->webPrice->collection_variant_price, 2) : '' }})</span>@if(! $loop->last) · @endif
          @endforeach
        </p>
      @endif
    </div>
  @empty
    <div class="card"><p class="muted" style="margin:0">No discontinued products to follow up.</p></div>
  @endforelse
</form>

@else
<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Product</th><th>Change</th><th>Decision</th><th>When</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td><a href="{{ route('range.show', $r->product_id) }}">{{ $r->product?->name ?? 'Product '.$r->product_id }}</a></td>
        <td>{{ $r->kind === 'new' ? 'New product' : 'Discontinued' }}</td>
        <td>{{ $r->kind === 'new' ? ($r->status === 'approved' ? 'Approved' : 'Declined') : 'Dealt with' }}@if($r->error) <span class="small muted">({{ $r->error }})</span>@endif</td>
        <td class="small">{{ $r->decided_at?->format('j M Y H:i') ?? $r->updated_at?->format('j M Y H:i') }}</td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted" style="padding:20px 8px">Nothing decided yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endif
<div>{{ $rows->links() }}</div>
@endsection
