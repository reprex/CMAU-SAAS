@extends('layouts.app')
@section('title', $supplier->name)
@section('content')
<div><span class="eyebrow">Admin · Suppliers · {{ $supplier->id }}</span><h1>{{ $supplier->name }}</h1></div>
<div class="cols">
  <form method="post" action="{{ route('admin.suppliers.update', $supplier) }}" class="card main" style="max-width:640px">
    @csrf @method('PUT')
    <h2>Standard freight</h2>
    <div class="fgrid">
      <label class="f">Cost to us ex GST<input class="n" name="freight_cost" value="{{ old('freight_cost', $supplier->freight_cost) }}" inputmode="decimal"></label>
      <label class="f">Sell to client ex GST<input class="n" name="freight_sell" value="{{ old('freight_sell', $supplier->freight_sell) }}" inputmode="decimal"></label>
    </div>
    <p class="small muted" style="margin:-4px 0 0">Leave the sell price empty and quotes show this supplier's freight as "to be confirmed".</p>
    <label class="f">Charged
      <select name="freight_basis">@foreach(\App\Models\Supplier::FREIGHT_BASES as $k => $label)<option value="{{ $k }}" @selected(old('freight_basis', $supplier->freight_basis) === $k)>{{ $label }}</option>@endforeach</select>
    </label>
    <div class="fgrid">
      <label class="f">Free freight when the quote's goods from this supplier reach (ex GST)<input class="n" name="free_freight_over" value="{{ old('free_freight_over', $supplier->free_freight_over) }}" placeholder="none"></label>
      <label class="f">Effective from<input type="date" name="freight_effective_from" value="{{ old('freight_effective_from', $supplier->freight_effective_from?->toDateString()) }}"></label>
    </div>
    <label class="row" style="gap:10px;align-items:flex-start"><input type="checkbox" name="freight_decorator_leg" value="1" @checked(old('freight_decorator_leg', $supplier->freight_decorator_leg)) style="margin-top:4px"><span><b>Charge this freight when the goods go to an outside decorator first</b><br><span class="small muted">The decorator's own freight covers delivery to the client.</span></span></label>
    <label class="row" style="gap:10px;align-items:flex-start"><input type="checkbox" name="is_decorator" value="1" @checked(old('is_decorator', $supplier->is_decorator)) style="margin-top:4px"><span><b>This supplier is a decorator</b><br><span class="small muted">Items it decorates are delivered by it, at its freight.</span></span></label>
    <label class="f">Branding template address (optional)<input name="template_url_pattern" value="{{ old('template_url_pattern', $supplier->template_url_pattern) }}" placeholder="https://supplier.com.au/templates/{code}.pdf"><span class="small muted">If the supplier publishes a template per product, give its address with {code} where the product code goes. Products on the range are checked for one and it is read automatically (docs/branding-templates.md).</span></label>
    <h2 style="margin-top:8px">Website orders</h2>
    <div class="fgrid">
      <label class="f">State (public holidays)<select name="state">@foreach(\App\Models\PublicHoliday::STATES as $st)<option @selected(old('state', $supplier->state ?? 'VIC') === $st)>{{ $st }}</option>@endforeach</select></label>
      <label class="f">Stock lead time (business days)<input class="n" name="stock_lead_days" value="{{ old('stock_lead_days', $supplier->stock_lead_days) }}" inputmode="numeric" placeholder="{{ config('website.lead_times.stock_default') }}"></label>
      <label class="f">Ships from overseas: transit to the client (business days)<input class="n" name="transit_days" value="{{ old('transit_days', $supplier->transit_days) }}" inputmode="numeric" placeholder="none"></label>
      <label class="f">Ships from<select name="ships_from"><option value="">Australia</option><option value="NZ" @selected(old('ships_from', $supplier->ships_from) === 'NZ')>New Zealand</option></select></label>
      <label class="f">Payment terms<select name="payment_terms">@foreach(config('website.payment_terms.options') as $k => $label)<option value="{{ $k }}" @selected(old('payment_terms', $supplier->payment_terms ?? 'full') === $k)>{{ $label }}</option>@endforeach</select></label>
    </div>
    <div class="fgrid">
      <label class="f">Orders contact (purchase orders)<input name="orders_contact" value="{{ old('orders_contact', $supplier->orders_contact) }}" maxlength="120" placeholder="e.g. Sam Lee"></label>
      <label class="f">Orders email<input name="orders_email" type="email" value="{{ old('orders_email', $supplier->orders_email) }}" maxlength="190" placeholder="orders@supplier.com.au"></label>
      <label class="f">Our account number with them<input name="our_account_number" value="{{ old('our_account_number', $supplier->our_account_number) }}" maxlength="60"></label>
    </div>
    <div class="fgrid">
      <label class="f">Dispatches from: suburb<input name="dispatch_suburb" value="{{ old('dispatch_suburb', $supplier->dispatch_suburb) }}" maxlength="80" placeholder="e.g. DERRIMUT"></label>
      <label class="f">Dispatches from: postcode<input class="n" name="dispatch_postcode" value="{{ old('dispatch_postcode', $supplier->dispatch_postcode) }}" inputmode="numeric" maxlength="4" placeholder="e.g. 3026"></label>
      <p class="small muted" style="margin:0;align-self:end">Delivery on quotes and at checkout is priced from here (MachShip, FedEx).@if($supplier->dispatch_source) From {{ $supplier->dispatch_source === 'promodata' ? 'Promodata' : 'staff' }}.@endif Empty: priced from {{ config('freight.from.suburb') }} {{ config('freight.from.postcode') }}.</p>
    </div>
    <label class="f">Closures (one per line: <code>2026-12-18 to 2027-01-11 Christmas shutdown</code>)<textarea name="closures" rows="3" placeholder="Empty: shut the last 2 working days before Christmas, reopening the first Monday after 5 January">{{ old('closures', $closures) }}</textarea><span class="small muted">From the supplier's distributor site or notices. The delivery estimate skips these days for stock and branding (docs/lead-times.md).</span></label>
    <label class="row" style="gap:10px;align-items:flex-start"><input type="checkbox" name="offers_samples" value="1" @checked(old('offers_samples', $supplier->offers_samples)) style="margin-top:4px"><span><b>Offers samples on the website</b><br><span class="small muted">"Order sample" on its product pages, at the unbranded price at the minimum order. Unprinted or a print overrun: the factory decides.@if($supplier->sample_available) TOD marks this supplier as having samples.@endif</span></span></label>
    <div class="fgrid">
      <label class="f">Sample freight per consignment ex GST<input class="n" name="sample_freight" value="{{ old('sample_freight', $supplier->sample_freight) }}" inputmode="decimal" placeholder="not set"></label>
      <label class="f">Sample lead time (business days)<input class="n" name="sample_lead_days" value="{{ old('sample_lead_days', $supplier->sample_lead_days) }}" inputmode="numeric" placeholder="stock lead time"></label>
    </div>
    <p class="small muted" style="margin:-4px 0 0">Sample freight is charged once for all of this supplier's samples in a client's samples cart. Without it, its samples can't be ordered online. Clients never see the supplier's name: its samples show as "From factory 1", "From factory 2"…</p>
    <p class="small muted" style="margin:-4px 0 0">The delivery estimate skips weekends and this state's public holidays, and adds the stock lead time (empty = {{ config('website.lead_times.stock_default') }} days), Promodata's lead time for each decoration (else config/website.php) plus a day per extra position, the Christmas rush from the end of October, transit from overseas and the closures above.</p>
    <p class="small muted" style="margin:0">Changes apply to new quotes and any quote recalculated from now on. Quotes already sent keep the freight they were sent with.</p>
    <div><button class="btn primary" type="submit">Save supplier</button> <a class="btn link" href="{{ route('admin.suppliers.index') }}">Back to suppliers</a></div>
  </form>
  <div class="card side">
    <h2>History</h2>
    @forelse($supplier->freightRates as $r)
      <div class="small"><b>{{ $r->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</b> by {{ $r->changedBy?->name ?? 'unknown' }}<br>
        sell {{ $r->freight_sell === null ? 'not set' : number_format((float) $r->freight_sell, 2) }}, cost {{ $r->freight_cost === null ? '–' : number_format((float) $r->freight_cost, 2) }}, {{ \App\Models\Supplier::FREIGHT_BASES[$r->freight_basis] ?? $r->freight_basis }}@if($r->free_freight_over !== null), free over {{ number_format((float) $r->free_freight_over, 2) }}@endif</div>
    @empty
      <div class="small muted">No changes yet. TOD never stored supplier freight, so this starts empty.</div>
    @endforelse
  </div>
</div>
@endsection
