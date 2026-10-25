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
    <p class="small muted" style="margin:0">Changes apply to new quotes and any quote recalculated from now on. Quotes already sent keep the freight they were sent with.</p>
    <div><button class="btn primary" type="submit">Save freight</button> <a class="btn link" href="{{ route('admin.suppliers.index') }}">Back to suppliers</a></div>
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
