@php($m = fn ($v) => number_format((float) $v, 2))
@php($split = (float) $item->product_setup_price + (float) $item->decoration_setup_price)
@php($recombined = round($split / max(1, $item->quantity) + (float) $item->product_unit_price + (float) $item->decoration_unit_price, 2))
@php($mismatch = $item->received_unit_price !== null && abs($recombined - (float) $item->received_unit_price) > 0.011)
@php($stageLabel = ['combined' => 'As received', 'separated' => 'Product + decoration', 'sku' => 'Split to SKUs'][$item->stage] ?? $item->stage)
<article class="item" id="item-{{ $item->id }}">
  <div class="item-head">
    <div class="thumb">@if($item->image_url)<img src="{{ $item->image_url }}" alt="" loading="lazy">@else<span class="mono small muted">{{ $item->product_id ?? 'custom' }}</span>@endif</div>
    <div class="grow">
      <h3>{{ $item->name }}</h3>
      <div class="small muted">{{ collect([$item->supplier_name, $item->product_code, $item->colour, $item->product_id ? 'APPAID '.$item->product_id : null])->filter()->implode(' · ') }}@if($item->product_id && \App\Models\Product::query()->whereKey($item->product_id)->exists()) · <a href="{{ route('range.substitutes', $item->product_id) }}">find substitutes</a>@endif</div>
      <div class="row" style="margin-top:6px;gap:6px">
        <span class="pill {{ $item->stage === 'sku' ? 'ok' : '' }}">{{ $stageLabel }}</span>
        @if($item->hasDecoration())<span class="pill accent">{{ $item->decoration_name }}</span>@endif
        @if($item->stage === 'sku' && $item->skuQuantity() !== $item->quantity)<span class="pill warn">sizes add to {{ $item->skuQuantity() }}</span>@endif
        @if($item->skus->contains(fn ($s) => $s->isShort()))<span class="pill warn">not enough stock for some sizes</span>@endif
        @if($mismatch)<span class="pill warn" title="The draft order line price">Shopify line price was {{ $m($item->received_unit_price) }}; this split adds to {{ $m($recombined) }}</span>@endif
      </div>
    </div>
    <div class="num" style="font-size:16px"><b>{{ $m($item->total()) }}</b><div class="small muted">{{ number_format($item->quantity) }} units</div></div>
  </div>

  <table class="lines">
    @if($item->stage === 'combined')
      <tr><td class="l">{{ number_format($item->quantity) }} × {{ $item->hasDecoration() ? 'branded' : '' }} {{ $item->colour }} @ {{ $m($item->unitPrice()) }}</td><td class="num">{{ $m($item->goodsTotal()) }}</td></tr>
      @if($item->setupPrice() > 0)<tr><td class="l"><span class="kind">SETUP</span>one-off</td><td class="num">{{ $m($item->setupPrice()) }}</td></tr>@endif
    @else
      <tr><td class="l"><span class="kind">PRODUCT</span>{{ number_format($item->quantity) }} × unbranded @ {{ $m($item->product_unit_price) }}<span class="muted small">{{ $item->product_unit_cost !== null ? '  (cost '.$m($item->product_unit_cost).')' : '' }}</span>
        @if($item->stage === 'sku')
          <div class="small" style="margin-top:4px">@foreach($item->skus as $s)<span class="pill {{ $s->isShort() ? 'warn' : '' }}" style="margin:0 4px 4px 0" title="{{ $s->supplier_sku }}{{ $s->stock_on_hand !== null ? ' · '.$s->stock_on_hand.' in stock' : '' }}">{{ $s->colour }} {{ $s->size }} × {{ $s->quantity }}</span>@endforeach</div>
        @endif
      </td><td class="num">{{ $m($item->productGoodsTotal()) }}</td></tr>
      @if((float) $item->product_setup_price > 0)<tr><td class="l"><span class="kind">SETUP</span>product setup</td><td class="num">{{ $m($item->product_setup_price) }}</td></tr>@endif
      @if($item->hasDecoration())
        <tr><td class="l"><span class="kind">DECORATION</span>{{ number_format($item->quantity) }} × {{ $item->decoration_name }} @ {{ $m($item->decoration_unit_price) }}<span class="muted small">{{ $item->decoration_unit_cost !== null ? '  (cost '.$m($item->decoration_unit_cost).')' : '' }}</span></td><td class="num">{{ $m($item->decorationGoodsTotal()) }}</td></tr>
        @if((float) $item->decoration_setup_price > 0)<tr><td class="l"><span class="kind">SETUP</span>decoration setup</td><td class="num">{{ $m($item->decoration_setup_price) }}</td></tr>@endif
      @endif
    @endif
  </table>

  @if($edit)
  <div class="item-tools">
    @if($item->stage === 'combined')
      <form method="post" action="{{ route('items.separate', $item) }}">@csrf<button class="btn small" type="submit">Split into product + decoration</button></form>
    @elseif($item->stage === 'separated')
      <form method="post" action="{{ route('items.combine', $item) }}">@csrf<button class="btn small" type="submit">Show as one line</button></form>
    @endif
    <form method="post" action="{{ route('items.duplicate', $item) }}" class="row" style="gap:4px">@csrf
      <input class="n" name="quantity" type="number" min="1" placeholder="Qty" aria-label="Another quantity" style="width:80px;min-height:30px;padding:3px 6px">
      <button class="btn small" type="submit">Add another quantity</button>
    </form>
    @include('quotes._transfer', ['action' => route('items.transfer', $item)])
    <form method="post" action="{{ route('items.destroy', $item) }}" data-confirm="Remove {{ $item->name }}?" style="margin-left:auto">@csrf @method('DELETE')<button class="btn link small danger" type="submit">Remove</button></form>
  </div>

  @if($item->stage !== 'combined')
  <details class="sku-panel" @if($item->stage === 'separated' && $item->skus->isEmpty()) @endif>
    <summary>Size × qty split @if($item->stage === 'sku')({{ $item->skus->count() }} SKUs)@endif</summary>
    <div data-sku-panel data-list-url="{{ route('items.skus', $item) }}" data-colour="{{ $item->colour }}" data-quantity="{{ $item->quantity }}"
         data-saved='@json($item->skus->map(fn ($s) => ['colour' => $s->colour, 'size' => $s->size, 'quantity' => $s->quantity])->values())' style="display:flex;flex-direction:column;gap:10px">
      <form method="post" action="{{ route('items.skus.save', $item) }}" style="display:flex;flex-direction:column;gap:10px">
        @csrf
        <input type="hidden" name="mode" value="{{ $item->product_id && $item->product_id < 10000000 ? ($item->sku_mode ?: 'list') : 'manual' }}">
        <div class="row">
          <div class="seg" role="group" aria-label="How to enter sizes">
            <button type="button" data-mode="list">Pick from SKU list</button>
            <button type="button" data-mode="manual">Enter manually</button>
          </div>
          <label class="small row" data-list-only style="gap:4px"><input type="checkbox" data-only-colour checked> only {{ $item->colour ?: 'this colour' }}</label>
          <button type="button" class="btn link small" data-list-only data-reload>Check stock again</button>
          <button type="button" class="btn small" data-manual-only data-add-row>Add a row</button>
          <span class="pill" data-total style="margin-left:auto"></span>
        </div>
        <div class="small muted" data-status data-list-only></div>
        <div style="overflow-x:auto"><table class="sku-table"><thead><tr></tr></thead><tbody></tbody></table></div>
        <div class="row"><button class="btn primary small" type="submit">Save sizes</button><span class="small muted">The item quantity becomes the total, and the price is updated for it.</span></div>
      </form>
    </div>
  </details>
  @endif

  <details class="sku-panel">
    <summary>Edit item and prices</summary>
    <form method="post" action="{{ route('items.update', $item) }}" style="display:flex;flex-direction:column;gap:10px">
      @csrf @method('PUT')
      <div class="fgrid">
        <label class="f" style="grid-column: span 2">Name<input name="name" value="{{ $item->name }}" required></label>
        <label class="f">Colour<input name="colour" value="{{ $item->colour }}"></label>
        <label class="f">Quantity<input class="n" name="quantity" type="number" min="1" value="{{ $item->quantity }}" required></label>
      </div>
      <div class="fgrid">
        <label class="f">Product each<input class="n" name="product_unit_price" value="{{ (float) $item->product_unit_price }}"></label>
        <label class="f">Product setup<input class="n" name="product_setup_price" value="{{ (float) $item->product_setup_price }}"></label>
        <label class="f">Product cost each<input class="n" name="product_unit_cost" value="{{ $item->product_unit_cost === null ? '' : (float) $item->product_unit_cost }}"></label>
        <label class="f">Product setup cost<input class="n" name="product_setup_cost" value="{{ $item->product_setup_cost === null ? '' : (float) $item->product_setup_cost }}"></label>
      </div>
      <div class="fgrid">
        <label class="f" style="grid-column: span 2">Decoration
          @if($item->product_id)
            <select name="decoration_option_id" data-options-url="{{ route('products.options', $item->product_id) }}">
              <option value="">Unbranded / not from the catalogue</option>
              @if($item->decoration_option_id)<option value="{{ $item->decoration_option_id }}" selected>{{ $item->decoration_name }}</option>@endif
            </select>
          @else
            <input type="hidden" name="decoration_option_id" value="">
          @endif
        </label>
        <label class="f" style="grid-column: span 2">Decoration wording on the quote<input name="decoration_name" value="{{ $item->decoration_name }}"></label>
      </div>
      <div class="fgrid">
        <label class="f">Decoration each<input class="n" name="decoration_unit_price" value="{{ (float) $item->decoration_unit_price }}"></label>
        <label class="f">Decoration setup<input class="n" name="decoration_setup_price" value="{{ (float) $item->decoration_setup_price }}"></label>
        <label class="f">Decoration cost each<input class="n" name="decoration_unit_cost" value="{{ $item->decoration_unit_cost === null ? '' : (float) $item->decoration_unit_cost }}"></label>
        <label class="f">Decoration setup cost<input class="n" name="decoration_setup_cost" value="{{ $item->decoration_setup_cost === null ? '' : (float) $item->decoration_setup_cost }}"></label>
      </div>
      <label class="f">Image URL (https)<input type="url" name="image_url" value="{{ $item->image_url }}"></label>
      <label class="f">Description (comparison PDF)<textarea name="description">{{ $item->description }}</textarea></label>
      <label class="f">Note on the quote<input name="notes" value="{{ $item->notes }}"></label>
      <label class="small row" style="gap:6px"><input type="checkbox" name="reprice" value="1"> Re-price from the catalogue at this quantity (replaces the prices above)</label>
      <div><button class="btn primary small" type="submit">Save item</button></div>
    </form>
  </details>
  @endif
</article>
