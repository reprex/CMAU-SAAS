@extends('layouts.app')
@section('title', $quote->number)
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
@php($edit = auth()->user()->canEdit())
@php($separate = $quote->decoration_display === 'separate')

<div class="row between" style="align-items:flex-start">
  <div style="display:flex;flex-direction:column;gap:4px">
    <span class="eyebrow">Quote {{ $quote->number }} · rev {{ $quote->revision }} @if($quote->shopify_draft_name)· {{ $quote->shopify_draft_name }} @endif· {{ $quote->source }}</span>
    <h1>{{ $quote->displayName() }}</h1>
    <span class="muted small">{{ collect([$quote->client_name !== $quote->displayName() ? $quote->client_name : null, $quote->client_email, $quote->client_phone])->filter()->implode(' · ') }}</span>
  </div>
  <div class="row">
    <a class="btn" href="{{ route('quotes.pdf', $quote) }}" target="_blank" rel="noopener">Quote PDF</a>
    <a class="btn" href="{{ route('quotes.comparison', $quote) }}" target="_blank" rel="noopener">Comparison PDF</a>
    @if($edit)
    <form method="post" action="{{ route('quotes.status', $quote) }}" class="row">
      @csrf
      <select name="status" aria-label="Status" style="width:auto">
        @foreach ($statuses as $k => $label)<option value="{{ $k }}" @selected($quote->status === $k)>{{ $label }}</option>@endforeach
      </select>
      <button class="btn dark" type="submit">Set status</button>
    </form>
    @endif
  </div>
</div>

@if($quote->urgency || $quote->client_notes)
  <div class="card" style="background:#FFF8F1">
    @if($quote->urgency)<div><span class="pill accent">Urgency</span> {{ $quote->urgency }}</div>@endif
    @if($quote->client_notes)<div style="white-space:pre-line">{{ $quote->client_notes }}</div>@endif
  </div>
@endif

<div class="cols">
  <div class="main">
    <div class="row between">
      <h2>Items</h2>
      @if($edit)
      <form method="post" action="{{ route('quotes.update', $quote) }}" class="seg" aria-label="How decoration shows on the quote">
        @csrf @method('PUT')
        <button type="submit" name="decoration_display" value="combined" class="{{ $separate ? '' : 'on' }}">Decoration in the line</button>
        <button type="submit" name="decoration_display" value="separate" class="{{ $separate ? 'on' : '' }}">Decoration as its own line</button>
      </form>
      @endif
    </div>

    @forelse ($quote->items as $item)
      @include('quotes._item', ['item' => $item])
    @empty
      <div class="card muted">No items yet. Add one from the range below.</div>
    @endforelse

    <div class="card">
      <h2>Other lines</h2>
      <table class="list">
        <thead><tr><th>Line</th><th class="num">Qty</th><th class="num">Each</th><th class="num">Cost each</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        @forelse ($quote->extras as $x)
          <tr>
            <td>
              <span class="pill {{ $x->kind === 'freight' ? 'accent' : '' }}">{{ \App\Models\QuoteExtra::KINDS[$x->kind] }}</span>
              {{ $x->description }}
              @if($x->kind === 'freight')
                <div class="small muted">{{ $x->supplier?->name ?? 'Supplier' }}@if($x->auto) · automatic @endif
                  @if($x->tbc) <span class="pill warn">freight not set for this supplier</span>@endif
                  @if($x->overridden) <span class="pill">price changed by staff</span>@endif</div>
              @endif
            </td>
            <td class="num">{{ $x->kind === 'info' ? '' : rtrim(rtrim(number_format((float) $x->quantity, 2), '0'), '.') }}</td>
            <td class="num">{{ $x->kind === 'info' ? '' : $m($x->unit_price) }}</td>
            <td class="num muted">{{ $x->unit_cost === null ? '–' : $m($x->unit_cost) }}</td>
            <td class="num">{{ $x->kind === 'info' ? '' : $m($x->total()) }}</td>
            <td style="white-space:nowrap">
              @if($edit)
              <details>
                <summary class="small">Edit</summary>
                <form method="post" action="{{ route('extras.update', $x) }}" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                  @csrf @method('PUT')
                  @unless($x->auto)
                    <input type="hidden" name="kind" value="{{ $x->kind }}">
                    <label class="f">Description<input name="description" value="{{ $x->description }}" required></label>
                    <label class="f">Qty<input class="n" name="quantity" value="{{ (float) $x->quantity }}"></label>
                    <label class="f">Cost each<input class="n" name="unit_cost" value="{{ $x->unit_cost === null ? '' : (float) $x->unit_cost }}"></label>
                  @endunless
                  <label class="f">Price each ex GST<input class="n" name="unit_price" value="{{ (float) $x->unit_price }}"></label>
                  <button class="btn small primary" type="submit">Save</button>
                </form>
                @if($x->auto && $x->overridden)
                  <form method="post" action="{{ route('extras.reset', $x) }}">@csrf<button class="btn link small" type="submit">Use the supplier's freight again</button></form>
                @endif
                @unless($x->auto)
                  <form method="post" action="{{ route('extras.destroy', $x) }}" data-confirm="Remove this line?">@csrf @method('DELETE')<button class="btn link small danger" type="submit">Remove</button></form>
                @endunless
              </details>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="muted">No other lines.</td></tr>
        @endforelse
        </tbody>
      </table>
      @if($edit)
      <details>
        <summary>Add a custom product, service or note</summary>
        <form method="post" action="{{ route('extras.store', $quote) }}" style="display:flex;flex-direction:column;gap:10px">
          @csrf
          <div class="fgrid">
            <label class="f">Type<select name="kind">@foreach (collect(\App\Models\QuoteExtra::KINDS)->except('freight') as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
            <label class="f" style="grid-column: span 2">Description<input name="description" required placeholder="e.g. Artwork setup, or 20 working days + delivery"></label>
          </div>
          <div class="fgrid">
            <label class="f">Qty<input class="n" name="quantity" value="1"></label>
            <label class="f">Price each ex GST<input class="n" name="unit_price" value="0"></label>
            <label class="f">Cost each<input class="n" name="unit_cost"></label>
            <label class="f">Supplier id (for freight)<input name="supplier_id" inputmode="numeric"></label>
            <label class="f">Image URL (https)<input name="image_url" type="url"></label>
          </div>
          <div><button class="btn primary" type="submit">Add line</button></div>
        </form>
      </details>
      @endif
    </div>

    @if($edit)
    <div class="card" data-product-search data-search-url="{{ route('products.search') }}" data-options-url="{{ route('products.options', ['product' => '__ID__']) }}">
      <h2>Add from the range</h2>
      <input type="search" placeholder="Search by name, supplier code or product id" aria-label="Search the range">
      <div class="results"></div>
      <form method="post" action="{{ route('items.store', $quote) }}" hidden style="display:flex;flex-direction:column;gap:10px">
        @csrf
        <input type="hidden" name="product_id">
        <div><b data-chosen></b></div>
        <div class="fgrid">
          <label class="f">Quantity<input class="n" name="quantity" type="number" min="1" value="50" required></label>
          <label class="f">Colour<select name="colour"></select></label>
          <label class="f" style="grid-column: span 2">Decoration<select name="decoration_option_id"></select></label>
        </div>
        <div><button class="btn primary" type="submit">Add to quote</button></div>
      </form>
    </div>
    @endif
  </div>

  <div class="side">
    <div class="card totals">
      <h2>Totals</h2>
      <div><span>Subtotal ex GST</span><span class="mono">{{ $m($quote->subtotal) }}</span></div>
      <div><span>GST</span><span class="mono">{{ $m($quote->gst) }}</span></div>
      <div class="grand"><span>Total inc GST</span><span class="mono">${{ $m($quote->total) }}</span></div>
      <div class="small muted"><span>Cost to us</span><span class="mono">{{ $quote->cost_total === null ? 'not all known' : $m($quote->cost_total) }}</span></div>
      @if($quote->cost_total !== null && (float) $quote->subtotal > 0)
        <div class="small muted"><span>Margin</span><span class="mono">{{ $m($quote->subtotal - $quote->cost_total) }} ({{ number_format(100 * ($quote->subtotal - $quote->cost_total) / $quote->subtotal, 1) }}%)</span></div>
      @endif
      @if($edit)
      <div class="row">
        <form method="post" action="{{ route('quotes.recalculate', $quote) }}">@csrf<button class="btn small" type="submit">Recalculate freight</button></form>
        <form method="post" action="{{ route('quotes.revise', $quote) }}">@csrf<button class="btn small" type="submit">New revision</button></form>
      </div>
      @endif
    </div>

    <div class="card">
      <h2>Client and delivery</h2>
      <div class="small">
        {{ collect([$quote->delivery_name, $quote->delivery_address1, $quote->delivery_address2, trim($quote->delivery_city.' '.$quote->delivery_state.' '.$quote->delivery_postcode)])->filter()->implode(', ') ?: 'No delivery address yet: freight lines say "Delivery" until one is added.' }}
      </div>
      <div class="small muted">Valid until {{ $quote->valid_until?->format('j M Y') ?? '–' }} · deadline {{ $quote->deadline?->format('j M Y') ?? '–' }}</div>
      @if($quote->shopify_invoice_url)<a class="small" href="{{ $quote->shopify_invoice_url }}" target="_blank" rel="noopener">Shopify invoice page</a>@endif
      @if($quote->internal_notes)<div class="small" style="white-space:pre-line;background:#F8F3EA;border-radius:8px;padding:8px 10px">{{ $quote->internal_notes }}</div>@endif
      @if($edit)
      <details>
        <summary>Edit details</summary>
        <form method="post" action="{{ route('quotes.update', $quote) }}" style="display:flex;flex-direction:column;gap:10px">
          @csrf @method('PUT')
          @include('quotes._header-fields', ['quote' => $quote])
          <div><button class="btn primary" type="submit">Save details</button></div>
        </form>
      </details>
      @endif
    </div>

    @if($edit)
    <form method="post" action="{{ route('quotes.destroy', $quote) }}" data-confirm="Delete {{ $quote->number }}?">@csrf @method('DELETE')<button class="btn link danger small" type="submit">Delete this quote</button></form>
    @endif
  </div>
</div>
@endsection
