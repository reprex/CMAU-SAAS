@extends('layouts.app')
@section('title', $product->name)
@section('content')
@php
  $canEdit = auth()->user()->canEdit();
  $ov = fn ($k, $d = null) => old($k, $product->overrideValue($k, $d));
  $wp = $product->webPrice;
  $typeNames = \App\Http\Controllers\Admin\DecorationTypeController::TYPES;
  $hidden = (array) $product->overrideValue('hidden_colours', []);
  $full = $product->hydrated_at !== null && $product->keepsFullDetail();
  $toPublish = $product->tier === 'live' && $product->range_changed_at && (! $product->published_at || $product->range_changed_at->gt($product->published_at));
@endphp

<div class="row between" style="align-items:flex-start">
  <div class="row" style="align-items:flex-start;gap:16px">
    <div class="thumb" style="width:110px;height:110px">@if($product->overrideValue('hero_image_url', $product->hero_image_url))<img src="{{ $product->overrideValue('hero_image_url', $product->hero_image_url) }}" alt="">@endif</div>
    <div style="display:flex;flex-direction:column;gap:6px">
      <span class="eyebrow"><a href="{{ route('range.index') }}">Range</a> · {{ $product->supplier?->name }} · APPAID {{ $product->id }}</span>
      <h1>{{ $product->overrideValue('title') ?: $product->name }}</h1>
      <div class="row small" style="gap:8px">
        <span class="mono">[{{ $product->supplier_id }}-{{ $product->code }}]</span>
        <span class="pill {{ ['live' => 'ok', 'active' => 'accent'][$product->tier] ?? '' }}">{{ ['live' => 'On the range', 'active' => 'Kept in full, not on range', 'summary' => 'Summary only'][$product->tier] ?? $product->tier }}</span>
        @if($product->discontinued)<span class="pill bad">discontinued by supplier</span>@endif
        @if($toPublish)<span class="pill warn">changed, to publish</span>@endif
        @if($product->overrideValue('title'))<span class="muted">Promodata name: {{ $product->name }}</span>@endif
      </div>
      <div class="small muted">{{ $product->promodata_type_name }}@if($product->last_changed_at) · supplier data changed {{ $product->last_changed_at->format('j M Y') }}@endif @if($product->hydrated_at) · fetched {{ $product->hydrated_at->diffForHumans() }}@endif
        @if($product->detail?->supplier_website_page) · <a href="{{ $product->detail->supplier_website_page }}" target="_blank" rel="noopener">supplier page</a>@endif</div>
    </div>
  </div>
  <div class="row" style="gap:8px">
  <a class="btn" href="{{ route('range.substitutes', $product) }}">Find substitutes</a>
  @if($canEdit)
  <form method="post" action="{{ route('range.action', $product) }}" class="row" style="gap:8px">@csrf
    @if($product->tier === 'live')
      <button class="btn" name="action" value="remove" data-confirm="Take this product off the web range? Its details and overrides are kept.">Remove from range</button>
    @else
      <button class="btn primary" name="action" value="add" @disabled($product->discontinued)>Add to range</button>
    @endif
    @if($product->source === 'promodata')<button class="btn" name="action" value="refresh">Refresh from Promodata</button>@endif
    @if($full)<button class="btn" name="action" value="reprice">Reprice</button>@endif
  </form>
  @endif
  </div>
</div>

@if($product->reprice_error)<div class="errors" role="alert"><b>Pricing failed:</b> {{ $product->reprice_error }}</div>@endif
@if($wp && $wp->warnings)<div class="card" style="background:var(--warn-bg);border-color:#E9D29C"><b>Pricing warnings</b><ul style="margin:0;padding-left:18px">@foreach($wp->warnings as $w)<li>{{ is_array($w) ? implode(' ', $w) : $w }}</li>@endforeach</ul></div>@endif

@if(! $full)
  <div class="card">
    <h2>Only a summary is stored</h2>
    <p class="muted" style="margin:0">Lowest cost {{ $product->min_unit_cost !== null ? number_format((float) $product->min_unit_cost, 2) : '–' }}@if($product->min_qty) at {{ $product->min_qty }}@endif · {{ $product->colour_count }} colours · {{ $product->supplier_category }} / {{ $product->supplier_subcategory }}.
      Adding it to the range (or "Refresh from Promodata") fetches the full product: colours, images, decoration prices.</p>
  </div>
@else
<div class="cols">
  <div class="main">
    <div class="card">
      <h2>Web price</h2>
      @if($wp)
      <div class="stats">
        <div class="stat"><b>{{ $wp->collection_variant_price !== null ? number_format($wp->collection_variant_price, 2) : '–' }}</b><span>collection price each, at {{ $wp->collection_moq ?? '–' }}</span></div>
        <div class="stat"><b>{{ $wp->actual_moq ?? '–' }}</b><span>minimum order (undecorated {{ $wp->undecorated_moq ?? '–' }}, decoration {{ $wp->decoration_moq ?? '–' }})</span></div>
        <div class="stat"><b>{{ $wp->order_value_100 !== null ? number_format($wp->order_value_100, 2) : '–' }}</b><span>order value at 100</span></div>
        <div class="stat"><b>{{ $wp->decoration_source ?? '–' }}</b><span>default decoration chosen by{{ $wp->decoration_product_id != $product->id ? ' (from product '.$wp->decoration_product_id.')' : '' }}</span></div>
      </div>
      <div class="small muted">Default decoration: <span class="mono">{{ $wp->default_decoration_option_id ?? 'none' }}</span> · {{ $wp->decoration_option_count }} decoration and {{ $wp->unbranded_option_count }} unbranded options · priced {{ $wp->computed_at?->diffForHumans() }}</div>
      @endif
    </div>

    <form method="post" action="{{ route('range.options', $product) }}" class="card" style="padding:12px">@csrf @method('PUT')
      <div class="row between"><h2>Price options</h2>@if($canEdit)<button class="btn small">Save option settings</button>@endif</div>
      <p class="small muted" style="margin:0">Cost breaks come from Promodata; sell prices use the supplier's markups. "Type" is set once per description under <a href="{{ route('admin.dectypes.index') }}">Decoration types</a>; change it here only for this product. "Group" orders DecorationJSON (1 first, 2 normal).</p>
      <table class="list">
        <thead><tr><th>Option</th><th>Type</th><th class="num">Setup</th><th>Cost breaks</th><th>Sell breaks</th><th class="num">Group</th></tr></thead>
        <tbody>
        @foreach($product->priceOptions as $o)
          @php($setting = $settings->get($o->option_id))
          @php($classified = $types->get($o->code)?->type_code)
          <tr @if($o->placeholder) class="muted" @endif>
            <td><b>{{ $o->display_description ?: $o->description }}</b>@if($wp && $wp->default_decoration_option_id === $o->option_id) <span class="pill ok">default</span>@endif @if($o->placeholder)<span class="pill">placeholder</span>@endif<br><span class="mono small muted">{{ $o->option_id }}</span></td>
            <td>
              @if($canEdit && ! $o->placeholder)
                <select name="type[{{ $o->option_id }}]" aria-label="Type for {{ $o->option_id }}" style="min-width:180px">
                  <option value="">Default: {{ $classified ? $classified.' '.$typeNames[$classified] : 'not classified' }}</option>
                  @foreach($typeNames as $code => $name)<option value="{{ $code }}" @selected($setting?->type_code_override === $code)>{{ $code }} {{ $name }} only here</option>@endforeach
                </select>
              @else
                {{ $o->type_code ? $o->type_code.' '.($typeNames[$o->type_code] ?? '') : 'Not classified' }}
              @endif
              @if($classified === null && ! $o->placeholder)<br><a class="small" href="{{ route('admin.dectypes.index', ['q' => $o->code]) }}">classify "{{ $o->code }}"</a>@endif
            </td>
            <td class="num">{{ number_format($o->setup_cost, 2) }}<br><span class="muted">{{ number_format($o->setup_price, 2) }}</span></td>
            <td class="mono small breaks">{{ $o->cost_string }}</td>
            <td class="mono small breaks">{{ $o->price_string }}</td>
            <td class="num">@if($canEdit && $o->type_code === 10)<input name="sort[{{ $o->option_id }}]" value="{{ $setting?->json_sort }}" inputmode="numeric" style="width:54px" aria-label="Group for {{ $o->option_id }}">@else{{ $setting?->json_sort }}@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </form>

    @if($wp?->decoration_json)
    <details class="card"><summary>DecorationJSON (what the product page reads)</summary>
      <pre class="mono small" style="white-space:pre-wrap;margin:0;max-height:420px;overflow:auto">{{ json_encode(json_decode($wp->decoration_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
    @endif

    @if($product->detail?->description)
    <details class="card"><summary>Promodata description</summary><div class="small">{!! nl2br(e(strip_tags($product->detail->description))) !!}</div></details>
    @endif
  </div>

  <div class="side">
    <form method="post" action="{{ route('range.update', $product) }}" class="card">@csrf @method('PUT')
      <h2>Curation</h2>
      <label class="f">Web title (blank = Promodata name)<input name="title" value="{{ $ov('title') }}" maxlength="255" placeholder="{{ $product->name }}"></label>
      <label class="f">Web description (blank = Promodata)<textarea name="description" rows="4">{{ $ov('description') }}</textarea></label>
      <div class="fgrid">
        <label class="f">Subcategory<input name="subcategory" value="{{ $ov('subcategory') }}" placeholder="{{ $product->promodata_type_name }}"></label>
        <label class="f">Default decoration
          <select name="decoration_option_id"><option value="">Automatic (cheapest / TOD default)</option>
            @foreach($decorationChoices as $d)<option value="{{ $d->option_id }}" @selected($ov('decoration_option_id') === $d->option_id)>{{ $d->display_description ?: $d->description }}</option>@endforeach
          </select></label>
        <label class="f">Minimum order (blank = from prices)<input name="actual_moq" value="{{ $ov('actual_moq') }}" inputmode="numeric" class="n"></label>
        <label class="f">Collection price quantity<input name="collection_moq" value="{{ $ov('collection_moq') }}" inputmode="numeric" class="n"></label>
        <label class="f">Use decorations of product<input name="decoration_product_id" value="{{ $ov('decoration_product_id') }}" inputmode="numeric" class="n" placeholder="APPAID"></label>
      </div>
      <input type="hidden" name="moq_one" value="0"><label class="check small"><input type="checkbox" name="moq_one" value="1" @checked($ov('moq_one'))> Sell from 1 (first break quantity set to 1, as TOD's MOQ-1 list)</label>

      @if($imageTags->isNotEmpty())
      @php($roleNames = \App\Catalogue\ImageTagger::ROLES)
      <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="font-weight:600">Images ({{ $imageTags->count() }}): hero style shows on collection pages; hero and other style show when the product page opens; colour and other colour show once that colour is picked</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;max-height:420px;overflow:auto">
          @foreach($imageTags as $i => $t)
            <div class="small" style="display:flex;flex-direction:column;gap:4px;padding:6px;border:1px solid var(--line, #E5E3DE);border-radius:8px">
              <a href="{{ $t['url'] }}" target="_blank" rel="noopener" class="thumb" style="width:100%;height:110px"><img src="{{ $t['url'] }}" alt="" loading="lazy" style="object-fit:contain"></a>
              <input type="hidden" name="images[{{ $i }}][url]" value="{{ $t['url'] }}">
              <select name="images[{{ $i }}][role]" aria-label="Image {{ $i + 1 }} tag" class="image-role" @disabled(! $canEdit)>
                @foreach($roleNames as $k => $label)<option value="{{ $k }}" @selected($t['role'] === $k)>{{ $label }}</option>@endforeach
              </select>
              <select name="images[{{ $i }}][colour]" aria-label="Image {{ $i + 1 }} colour" @disabled(! $canEdit)>
                <option value="">No colour</option>
                @foreach($product->colours->unique('name') as $c)<option value="{{ $c->name }}" @selected($t['colour'] === $c->name)>{{ $c->name }}</option>@endforeach
              </select>
              <span class="muted">{{ ['staff' => 'Set by staff', 'ai' => 'Checked by Claude', 'auto' => 'Automatic'][$t['by']] ?? $t['by'] }}@if($t['by'] === 'staff' && $t['auto_role'] !== $t['role']) (auto: {{ $roleNames[$t['auto_role']] }})@endif</span>
              @if($t['seen'] && $t['seen']['tag'])<span class="muted">Claude: {{ str_replace('_', ' ', (string) $t['seen']['shows']) }}{{ $t['seen']['colour'] ? ', '.$t['seen']['colour'] : '' }}@if($t['seen']['confidence'] !== null) ({{ round($t['seen']['confidence'] * 100) }}%)@endif</span>@endif
              @if($t['review'])<span class="pill warn" style="white-space:normal">{{ $t['review'] }}</span>@endif
            </div>
          @endforeach
        </div>
        <p class="small muted" style="margin:4px 0 0">Saving a tag clears its warning. One hero only: picking a new hero turns the old one into other style. Colour and other colour images need a colour.</p>
      </fieldset>
      @endif

      @if($product->colours->isNotEmpty())
      <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="font-weight:600">Colours — untick to hide on the web ({{ $product->colours->count() }})</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:4px;max-height:220px;overflow:auto">
          @foreach($product->colours->unique('name') as $c)
            <label class="row small" style="gap:6px;flex-wrap:nowrap"><input type="checkbox" class="colour-show" data-colour="{{ $c->name }}" @checked(! in_array($c->name, $hidden, true))> {{ $c->name }}</label>
          @endforeach
        </div>
        <div id="hidden-colours">@foreach($hidden as $h)<input type="hidden" name="hidden_colours[]" value="{{ $h }}">@endforeach</div>
      </fieldset>
      @endif

      <label class="f">Staff notes<textarea name="notes" rows="2">{{ $ov('notes') }}</textarea></label>
      @if($canEdit)<button class="btn primary">Save and reprice</button>@endif
    </form>

    @if($product->can_check_stock)
    <div class="card">
      <div class="row between"><h2>Live stock</h2><button type="button" class="btn small" data-live-stock="{{ route('range.stock.live', $product) }}">Check now</button></div>
      <div id="live-stock" class="small muted">Straight from the supplier through Promodata, every colour and size with the supplier's SKU.</div>
    </div>
    @endif

    <div class="card">
      <h2>History</h2>
      @forelse($history as $h)
        <div class="small"><b>{{ str_replace(['range.', '_'], ['', ' '], $h->action) }}</b> · {{ $h->user?->name ?? 'system' }} · {{ $h->created_at->format('j M Y H:i') }}</div>
      @empty
        <div class="small muted">No changes recorded yet.</div>
      @endforelse
    </div>
  </div>
</div>
@endif
@endsection
