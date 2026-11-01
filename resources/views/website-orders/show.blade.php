@extends('layouts.app')
@section('title', $order->number)
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
@php($o = $order)
@php($canEdit = auth()->user()->canEdit())
<div class="row between">
  <div><div class="eyebrow">Website orders · {{ $o->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</div>
    <h1>{{ $o->number }}@if($o->isSamples()) <span class="pill" style="vertical-align:middle">Samples</span>@endif <span class="pill {{ $o->stage === 6 ? 'ok' : 'accent' }}" style="vertical-align:middle">{{ $o->stageName() }}</span></h1></div>
  <a class="btn" href="{{ route('website-orders.invoice', $o) }}" target="_blank" rel="noopener">Invoice PDF</a>
</div>

@if(! empty($o->alerts))
  <div class="card" style="background:var(--warn-bg);border-color:#E9D29C"><b>Pre-payment alerts on the invoice</b>
    <ul style="margin:0;padding-left:18px">@foreach($o->alerts as $a)<li>{{ $a['message'] }}</li>@endforeach</ul></div>
@endif

<div class="cols">
  <div class="main">
    @if($o->isSamples())
    <div class="card">
      <h2>Samples by factory</h2>
      <p class="small muted" style="margin:0">The client sees "From factory 1", "From factory 2"… never the supplier. Each sample may be unprinted or a print overrun: the factory decides.</p>
      @foreach($o->factoryGroups() as $f)
        <h3 style="margin:10px 0 4px">{{ $f['label'] }} · {{ $supplierNames[$f['supplier_id']] ?? 'Supplier '.$f['supplier_id'] }}</h3>
        <table class="list">
          <thead><tr><th>Product</th><th>Colour</th><th>Our SKU</th><th>Supplier code</th><th class="num">Price ex GST</th></tr></thead>
          <tbody>
          @foreach($f['lines'] as $l)
            <tr><td>{{ $l->product_name }} <span class="mono muted">{{ $o->ourCodeFor($l) }}</span></td><td>{{ $l->colour ?: '–' }}</td><td class="mono">{{ $l->sku ?? '–' }}</td><td class="mono">{{ $l->product_code ?? '–' }}</td><td class="num">{{ $m($l->total_ex_gst) }}</td></tr>
          @endforeach
          <tr><td colspan="4">Sample freight (once for this factory)</td><td class="num">{{ $m($f['freight']) }}</td></tr>
          <tr><td colspan="4"><b>{{ $f['label'] }} subtotal ex GST</b></td><td class="num"><b>{{ $m($f['subtotal']) }}</b></td></tr>
          </tbody>
        </table>
      @endforeach
    </div>
    @else
    <div class="card">
      <h2>Sizes and colours</h2>
      <table class="list">
        <thead><tr><th>Product</th><th>Colour</th><th>Size</th><th>Our SKU</th><th>Supplier SKU</th><th class="num">Qty</th><th class="num">Stock at order</th><th class="num">Unit ex GST</th><th class="num">Total ex GST</th></tr></thead>
        <tbody>
        @foreach($o->lines as $l)
          <tr><td>{{ $l->product_name }} <span class="mono muted">{{ $o->ourCodeFor($l) }} · supplier {{ $l->product_code }}</span></td><td>{{ $l->colour }}</td><td>{{ $l->size }}</td><td class="mono">{{ $l->sku ?? '–' }}</td><td class="mono">{{ $l->supplier_sku ?? '–' }}</td>
            <td class="num">{{ $l->qty }}</td><td class="num">{{ $l->stock_at_order ?? '–' }}</td><td class="num">{{ $m($l->unit_ex_gst) }}</td><td class="num">{{ $m($l->total_ex_gst) }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Branding positions</h2>
      <table class="list">
        <thead><tr><th>Position</th><th>Method</th><th class="num">Colours</th><th class="num">Width</th><th class="num">Unit</th><th class="num">Setup</th><th>Logo</th></tr></thead>
        <tbody>
        @foreach($o->positions as $p)
          <tr><td>{{ $p->position }}</td><td>{{ $p->methodName() }}<div class="muted small">{{ $p->decoration_name }}</div></td><td class="num">{{ $p->colours ?? '–' }}</td>
            <td class="num">{{ $p->width_mm ? $p->width_mm.' mm' : '–' }}</td><td class="num">{{ $m($p->unit_ex_gst) }}</td><td class="num">{{ $m($p->setup_ex_gst) }}</td>
            <td>@if($p->logo_path)<a href="{{ route('website-orders.logo', [$o, $p]) }}">Download</a><div class="muted small">{{ $p->logo_name }}</div>@else – @endif</td></tr>
        @endforeach
        </tbody>
      </table>
      <p class="small muted" style="margin:0">Logos are stored privately and only download from this screen when signed in.</p>
    </div>

    @endif

    <div class="card">
      <h2>Totals</h2>
      <table class="list" style="max-width:420px">
        <tr><td>Goods</td><td class="num">{{ $m($o->goods) }}</td></tr>
        @unless($o->isSamples())<tr><td>Setup (once)</td><td class="num">{{ $m($o->setup) }}</td></tr>@endunless
        <tr><td>Delivery · {{ $o->freight_name }}</td><td class="num">{{ $m($o->freight) }}</td></tr>
        <tr><td>Subtotal ex GST</td><td class="num">{{ $m($o->subtotal) }}</td></tr>
        <tr><td>{{ $o->export ? 'GST (export, not charged)' : 'GST 10%' }}</td><td class="num">{{ $m($o->gst) }}</td></tr>
        <tr><td><b>{{ $o->export ? 'Total ex GST' : 'Total inc GST' }}</b></td><td class="num"><b>{{ $m($o->total) }}</b></td></tr>
      </table>
      <p class="small muted" style="margin:0">Payment terms: {{ config('website.payment_terms.options.'.$o->payment_terms) ?? $o->payment_terms }}.@if($o->export) {{ config('website.orders.duty_note') }}@endif</p>
      @php($fb = $o->freight_breakdown ?? [])
      @if($fb && ! $o->isSamples())<p class="small muted" style="margin:0">{{ $fb['cartons'] ?? '?' }} cartons · {{ $fb['kg'] ?? '?' }} kg · {{ $fb['m3'] ?? '?' }} m³ · charged on {{ $fb['chargeable_kg'] ?? '?' }} kg ({{ $fb['basis'] ?? '' }}) to {{ $fb['zone'] ?? '' }}. Placeholder rates.</p>@endif
    </div>

    <div class="card">
      <h2>Change requests</h2>
      @forelse($o->changeRequests as $c)
        <div style="border-top:1px solid var(--line-soft);padding:8px 0">
          <div class="small muted">{{ $c->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }} · at stage “{{ \App\Models\WebsiteOrder::STAGES[$c->stage_at_request] ?? '' }}”
            @if($c->handled_at) · <span class="pill ok">handled by {{ $c->handler?->name ?? 'staff' }}</span>@else · <span class="pill warn">open</span>@endif</div>
          <div style="white-space:pre-wrap">{{ $c->message }}</div>
          @if(! $c->handled_at && $canEdit)<form method="post" action="{{ route('website-orders.change-handled', [$o, $c]) }}" style="margin-top:4px">@csrf<button class="btn small">Mark handled</button></form>@endif
        </div>
      @empty
        <p class="small muted" style="margin:0">None. Clients can request changes from My Orders until the order is dispatched.</p>
      @endforelse
    </div>
  </div>

  <div class="side">
    <div class="card">
      <h2>Emails</h2>
      @forelse($o->emails as $e)
        <div class="small" style="border-top:1px solid var(--line-soft);padding:6px 0">
          <b>{{ $e->kind === 'invoice' ? 'Invoice to client' : 'Change request to staff' }}</b> · <span class="pill {{ ['sent' => 'ok', 'failed' => 'bad', 'draft' => 'warn'][$e->status] ?? '' }}">{{ ['draft' => 'waiting for you', 'queued' => 'sending', 'sent' => 'sent', 'failed' => 'failed'][$e->status] ?? $e->status }}</span><br>
          <span class="muted">{{ $e->recipient }} · {{ $e->subject }}</span>
          @if($e->sent_at)<br><span class="muted">Sent {{ $e->sent_at->timezone(config('app.timezone'))->format('j M g:ia') }}@if($e->sender) by {{ $e->sender->name }}@endif</span>@endif
          @if($e->error)<br><span style="color:var(--bad)">{{ $e->error }}</span>@endif
          @if(in_array($e->status, ['draft', 'failed'], true) && $canEdit)
            <form method="post" action="{{ route('website-orders.send', [$o, $e]) }}" style="margin-top:4px">@csrf<button class="btn small primary">Send{{ $e->kind === 'invoice' ? ' invoice' : '' }}</button></form>
          @endif
        </div>
      @empty
        <p class="small muted" style="margin:0">No emails yet.</p>
      @endforelse
      @if(! config('website.orders.auto_send_invoice'))<p class="small muted" style="margin:0">Client emails are not sent automatically (REPSTACK principle 6). Check the invoice PDF, then send.</p>@endif
    </div>

    <form method="post" action="{{ route('website-orders.update', $o) }}" class="card">@csrf @method('PUT')
      <h2>Progress</h2>
      <label class="f">Stage
        <select name="stage" @disabled(! $canEdit)>@foreach($o->stageOptions() as $i => $label)<option value="{{ $i }}" @selected($o->stage === $i)>{{ $loop->iteration }}. {{ $label }}</option>@endforeach</select></label>
      <div class="fgrid">
        <label class="f">Carrier<select name="carrier" @disabled(! $canEdit)><option value="">–</option>@foreach(\App\Models\WebsiteOrder::CARRIERS as $c)<option @selected($o->carrier === $c)>{{ $c }}</option>@endforeach</select></label>
        <label class="f">Consignment number<input name="consignment" value="{{ old('consignment', $o->consignment) }}" @disabled(! $canEdit)></label>
      </div>
      <label class="f">Delivered on<input type="date" name="delivered_on" value="{{ old('delivered_on', $o->delivered_on?->toDateString()) }}" @disabled(! $canEdit)></label>
      @if($o->trackingUrl())<a href="{{ $o->trackingUrl() }}" target="_blank" rel="noopener" class="small">Track {{ $o->consignment }}</a>@endif
      @if($canEdit)<div><button class="btn primary">Save</button></div>@endif
    </form>

    <div class="card">
      <h2>Client</h2>
      <div class="small"><b>{{ $o->contact_name }}</b>@if($o->organisation), {{ $o->organisation }}@endif<br>{{ $o->email }} · {{ $o->phone }}</div>
      <div class="small"><b>Deliver to</b><br>{{ $o->addressLine() }}</div>
      <div class="small">@if($o->need_by)<b>Need by</b> {{ $o->need_by->format('D j M Y') }} · @endif<b>estimated</b> {{ $o->eta->format('D j M Y') }}
        @if(isset($o->eta_breakdown['deadline']['spare_business_days'])) · {{ $o->eta_breakdown['deadline']['spare_business_days'] }} business days to spare @endif</div>
      @if($o->event_name || $o->event_date || $o->event_place)<div class="small"><b>Event</b> {{ collect([$o->event_name, $o->event_date?->format('D j M Y'), $o->event_place])->filter()->implode(' · ') }}</div>@endif
      @if(! empty($o->eta_breakdown['stages']))
        <table class="list small">@foreach($o->eta_breakdown['stages'] as $st)<tr><td>{{ $st['name'] }}</td><td class="num">{{ $st['days'] }}</td><td>{{ \Carbon\CarbonImmutable::parse($st['ends'])->format('D j M') }}</td></tr>@endforeach</table>
        @if(! empty($o->eta_breakdown['holidays_skipped']))<div class="small muted">Skipped {{ $o->eta_breakdown['state'] }} holidays: {{ collect($o->eta_breakdown['holidays_skipped'])->map(fn ($h) => $h['name'].' '.\Carbon\CarbonImmutable::parse($h['date'])->format('j M'))->implode(', ') }}</div>@endif
      @endif
      @if($o->proof_acknowledged_at)<div class="small muted">Proof terms acknowledged {{ $o->proof_acknowledged_at->timezone(config('app.timezone'))->format('j M g:ia') }}</div>@endif
    </div>

    <div class="card">
      <h2>History</h2>
      @forelse($history as $h)
        <div class="small"><b>{{ str_replace(['website_order.', '_'], ['', ' '], $h->action) }}</b> · {{ $h->user?->name ?? 'website' }} · {{ $h->created_at->timezone(config('app.timezone'))->format('j M Y H:i') }}</div>
      @empty
        <div class="small muted">Nothing yet.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
