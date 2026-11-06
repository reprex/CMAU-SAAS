@extends('layouts.app')
@section('title', $po->number)
@section('content')
@php($m = fn ($v) => $v === null ? '–' : number_format((float) $v, 2))
@php($s = $po->supplier)
@php($d = $po->deliver_to)
@php($canEdit = auth()->user()->canEdit())
<div class="row between">
  <div><div class="eyebrow">Supplier purchase order @if($po->order)· <a href="{{ route('website-orders.show', $po->order) }}">{{ $po->order->number }}</a>@endif</div>
    <h1>{{ $po->number }} <span class="pill {{ $po->status === 'sent' ? 'ok' : 'accent' }}" style="vertical-align:middle">{{ $po->status === 'sent' ? 'Sent' : 'Not sent' }}</span></h1></div>
  <a class="btn" href="{{ route('purchase-orders.pdf', $po) }}" target="_blank" rel="noopener">PO PDF</a>
</div>

@if(! $po->costs_complete)<div class="card" style="background:var(--warn-bg);border-color:#E9D29C">A line has no cost in the catalogue (shown as –). Check the supplier's price before sending.</div>@endif

<div class="cols">
  <div class="main">
    <div class="card">
      <h2>Goods</h2>
      <table class="list"><thead><tr><th>Product</th><th>Colour</th><th>Size</th><th>Supplier SKU</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Total</th></tr></thead><tbody>
      @foreach($po->lines as $l)<tr><td>{{ $l['name'] }} <span class="mono muted">{{ $l['code'] }}</span></td><td>{{ $l['colour'] }}</td><td>{{ $l['size'] }}</td><td class="mono">{{ $l['supplier_sku'] ?: '–' }}</td><td class="num">{{ $l['qty'] }}</td><td class="num">{{ $m($l['unit_cost']) }}</td><td class="num">{{ $m($l['total']) }}</td></tr>@endforeach
      </tbody></table>
    </div>
    <div class="card">
      <h2>Branding</h2>
      <table class="list"><thead><tr><th>Position</th><th>Decoration</th><th class="num">Units</th><th class="num">Unit cost</th><th class="num">Setup</th></tr></thead><tbody>
      @foreach($po->branding as $b)<tr><td>{{ $b['position'] }}</td><td>{{ $b['decoration'] }}@if($b['colours']) · {{ $b['colours'] }} colours @endif @if($b['width_mm']) · {{ $b['width_mm'] }} mm @endif</td><td class="num">{{ $b['units'] }}</td><td class="num">{{ $m($b['unit_cost']) }}</td><td class="num">{{ $m($b['setup_cost']) }}</td></tr>@endforeach
      @foreach($po->extras ?? [] as $x)<tr><td>Option</td><td>{{ $x['name'] }}</td><td></td><td class="num">{{ $m($x['unit_cost']) }}</td><td class="num">{{ $m($x['setup_cost']) }}</td></tr>@endforeach
      </tbody></table>
      <p class="small" style="margin:6px 0 0">Artwork link for the supplier: <a href="{{ $po->artworkUrl() }}" target="_blank" rel="noopener">{{ $po->artworkUrl() }}</a> <span class="muted">(no login; works for 120 days)</span></p>
    </div>
    <div class="card">
      <h2>Costs ex GST</h2>
      <table class="list"><tbody>
        <tr><td>Goods</td><td class="num">{{ $m($po->goods_cost) }}</td></tr>
        <tr><td>Branding and options</td><td class="num">{{ $m((float) $po->branding_cost + (float) collect($po->extras ?? [])->sum(fn ($x) => (float) ($x['total'] ?? 0))) }}</td></tr>
        <tr><td>Setup</td><td class="num">{{ $m($po->setup_cost) }}</td></tr>
        <tr><td>Freight</td><td class="num">{{ $po->freight_cost === null ? 'to be advised' : $m($po->freight_cost) }}</td></tr>
        <tr><td><b>Total</b></td><td class="num"><b>{{ $m($po->total_cost) }}</b></td></tr>
        @if($po->order)<tr><td class="muted">Client pays (ex GST)</td><td class="num muted">{{ $m($po->order->subtotal) }}</td></tr>@endif
      </tbody></table>
    </div>
  </div>
  <div class="side">
    <div class="card">
      <h2>Supplier</h2>
      <p style="margin:0"><b>{{ $s?->name }}</b><br>
        <span class="small">{{ $s?->orders_contact ?: 'No orders contact' }} · {{ $s?->orders_email ?: 'No orders email' }}@if($s?->our_account_number) · account {{ $s->our_account_number }}@endif</span></p>
      @if(! $s?->orders_email)<p class="small" style="color:var(--bad);margin:4px 0 0">Add the orders email under Admin › Suppliers to send this.</p>@endif
      @if($s && auth()->user()->isAdmin())<p class="small" style="margin:4px 0 0"><a href="{{ route('admin.suppliers.edit', $s) }}">Edit supplier</a></p>@endif
    </div>
    <div class="card">
      <h2>Deliver to (blind ship)</h2>
      <p class="small" style="margin:0">{{ $d['organisation'] ?: $d['name'] }}@if($d['organisation']), attention {{ $d['name'] }}@endif<br>{{ trim(($d['unit'] ? $d['unit'].'/' : '').$d['street']) }}, {{ $d['suburb'] }} {{ $d['state'] }} {{ $d['postcode'] }} {{ ($d['country'] ?? 'AU') !== 'AU' ? $d['country'] : '' }}<br>{{ $d['phone'] }}</p>
      <p class="small" style="margin:6px 0 0">@if($po->despatch_by)Despatch by <b>{{ $po->despatch_by->format('D j M') }}</b> · @endif needed by <b>{{ $po->need_by?->format('D j M') }}</b>@if($po->event)<br>Event: {{ $po->event }}@endif</p>
    </div>
    @if($canEdit)
    <form method="post" action="{{ route('purchase-orders.update', $po) }}" class="card">@csrf @method('PUT')
      <h2>Freight and notes</h2>
      <label class="f">Freight cost from the supplier (ex GST)<input class="n" name="freight_cost" value="{{ old('freight_cost', $po->freight_cost) }}" inputmode="decimal" placeholder="to be advised"></label>
      <label class="f">Notes on the PO<textarea name="notes" rows="3">{{ old('notes', $po->notes) }}</textarea></label>
      <button class="btn small">Save</button>
    </form>
    <form method="post" action="{{ route('purchase-orders.send', $po) }}" class="card">@csrf
      <h2>Send</h2>
      <p class="small muted" style="margin:0 0 6px">Emails the PO PDF to {{ $s?->orders_email ?: 'the supplier' }} from {{ config('mail.from.address') }}; replies come to {{ config('quotes.pdf.email') }}.</p>
      <button class="btn small primary" @disabled(! $s?->orders_email)>{{ $po->status === 'sent' ? 'Send again' : 'Send to supplier' }}</button>
      @foreach($po->emails as $e)<div class="small muted" style="margin-top:4px">{{ $e->status }} {{ $e->sent_at?->timezone(config('app.timezone'))->format('j M g:ia') }} to {{ $e->recipient }}@if($e->sender) by {{ $e->sender->name }}@endif @if($e->error)<span style="color:var(--bad)">{{ $e->error }}</span>@endif</div>@endforeach
    </form>
    @endif
  </div>
</div>
@endsection
