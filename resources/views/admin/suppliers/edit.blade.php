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
      <label class="f">Payment terms<select name="payment_terms">@foreach(config('website.payment_terms.options') as $k => $label)<option value="{{ $k }}" @selected(old('payment_terms', $supplier->payment_terms ?? 'full') === $k)>{{ $label }}</option>@endforeach</select></label>
    </div>
    <label class="row" style="gap:10px;align-items:flex-start"><input type="checkbox" name="offers_samples" value="1" @checked(old('offers_samples', $supplier->offers_samples)) style="margin-top:4px"><span><b>Offers samples on the website</b><br><span class="small muted">"Order sample" on its product pages, at the unbranded price at the minimum order. Unprinted or a print overrun: the factory decides.@if($supplier->sample_available) TOD marks this supplier as having samples.@endif</span></span></label>
    <div class="fgrid">
      <label class="f">Sample freight per consignment ex GST<input class="n" name="sample_freight" value="{{ old('sample_freight', $supplier->sample_freight) }}" inputmode="decimal" placeholder="not set"></label>
      <label class="f">Sample lead time (business days)<input class="n" name="sample_lead_days" value="{{ old('sample_lead_days', $supplier->sample_lead_days) }}" inputmode="numeric" placeholder="stock lead time"></label>
    </div>
    <p class="small muted" style="margin:-4px 0 0">Sample freight is charged once for all of this supplier's samples in a client's samples cart. Without it, its samples can't be ordered online. Clients never see the supplier's name: its samples show as "From factory 1", "From factory 2"…</p>
    <p class="small muted" style="margin:-4px 0 0">The delivery estimate skips weekends and this state's public holidays, and adds the stock lead time (empty = {{ config('website.lead_times.stock_default') }} days). Decoration lead times are in config/website.php.</p>
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
