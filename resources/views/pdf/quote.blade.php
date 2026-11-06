@php($m = fn ($v) => number_format((float) $v, 2))
@php($date = ($quote->sent_at ?? now())->format('j F Y'))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Quote {{ $quote->number }}</title>@include('pdf._style')
<style>
.item td { border-bottom: 1px solid #E4E1DB; padding: 12px 0; }
.thumb { width: 132px; height: 132px; background: #F4F3F0; text-align: center; }
.thumb img { max-width: 124px; max-height: 124px; margin-top: 4px; }
.iname { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 17px; text-transform: uppercase; line-height: 1.1; margin: 2px 0 3px; }
.fig { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 20px; line-height: 1.1; }
.inc td { border-bottom: 1px solid #E4E1DB; padding: 5px 0; }
.totals { background: #D4651F; color: #FFFFFF; }
.pic { width: 100%; height: 165px; background: #F4F3F0; text-align: center; }
.pic img { max-width: 96%; max-height: 157px; margin-top: 4px; }
.pic.art img { max-height: 120px; margin-top: 22px; }
.small-pic { height: 125px; }
.small-pic img { max-height: 117px; }
.lines td { border-bottom: 1px solid #E4E1DB; padding: 5px 0; }
.thumb-s { width: 84px; height: 84px; background: #F4F3F0; text-align: center; }
.thumb-s img { max-width: 78px; max-height: 78px; margin-top: 3px; }
.center { text-align: center; }
</style></head>
<body>
<div class="footer"><table><tr><td>{{ $brand['signed_by'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</td><td class="right">{{ $brand['legal'] }}</td></tr></table></div>

@include('pdf._header', [
  'number' => 'Quote '.$quote->number.($quote->revision > 1 ? ' rev '.$quote->revision : ''),
  'dateLine' => $date.($quote->valid_until ? ' · valid until '.$quote->valid_until->format('j F Y') : ''),
  'kicker' => 'Quote prepared for',
  'client' => $quote->client_company ?: ($quote->client_name ?: ($quote->client_email ?: 'You')),
  'title' => $quote->title ?: 'Your quote',
])
@php($attention = collect([$quote->client_company && $quote->client_name ? 'Attention '.$quote->client_name : null, $quote->client_phone, $quote->client_email, $quote->deliverySuburb() ? 'delivering to '.trim($quote->delivery_city.' '.$quote->delivery_state.' '.$quote->delivery_postcode) : null])->filter())
@if($attention->isNotEmpty())<div class="muted" style="margin-top: 5px">{{ $attention->implode(' · ') }}</div>@endif
<div class="rule"></div>

@if($single)
  @php($main = $items->first()['item'] ?? null)
  {{-- Page 1: the one product the quote is for (Scott, 7 Nov 2026). --}}
  <table style="margin-top: 12px"><tr>
    @if(count($pictures['mockups']))
      @foreach(array_slice($pictures['mockups'], 0, 2) as $mk)
        <td style="width: 34%; padding-right: 10px; vertical-align: top"><div class="pic"><img src="{{ $mk['src'] }}" alt=""></div>
          <div class="small muted center" style="margin-top: 3px">Your mockup{{ count($pictures['mockups']) > 1 ? ' · '.ucfirst($mk['view']) : '' }}</div></td>
      @endforeach
    @else
      @foreach(array_slice(array_values(array_filter($pictures['artwork'], fn ($a) => $a['src'])), 0, 2) as $art)
        <td style="width: 34%; padding-right: 10px; vertical-align: top"><div class="pic art"><img src="{{ $art['src'] }}" alt=""></div>
          <div class="small muted center" style="margin-top: 3px">Your artwork{{ $art['position'] ? ' · '.$art['position'] : '' }}</div></td>
      @endforeach
    @endif
    @if($pictures['product'])
      <td style="width: 28%; vertical-align: top"><div class="pic small-pic"><img src="{{ $pictures['product'] }}" alt=""></div>
        <div class="small muted center" style="margin-top: 3px">The product, unbranded</div></td>
    @endif
    <td></td>
  </tr></table>
  <div class="small muted" style="margin-top: 6px">{{ count($pictures['mockups']) ? 'Displayed branding mockups are approximate only, we provide a full factory proof for approval prior to production.' : 'We will send a full factory proof of your branding for approval before production.' }}</div>
  @if($main)
    <div class="muted" style="margin-top: 8px">{{ collect([$main->ourCode() ? 'Code '.$main->ourCode() : null, $main->colour])->filter()->implode(' · ') }}</div>
    @foreach ($items->filter(fn ($r) => count($r['sizes'])) as $row)
      @if(! $loop->first)<div class="small" style="margin-top: 6px"><b>{{ $row['item']->name }}</b> <span class="muted">{{ $row['item']->ourCode() ? 'Code '.$row['item']->ourCode() : '' }}</span></div>@endif
      @foreach ($row['sizes'] as $colour => $sizes)
        <div style="margin-top: 4px">@if(count($row['sizes']) > 1 || ! $loop->parent->first)<span class="small muted">{{ $colour }}&nbsp;</span>@endif
        @foreach ($sizes as $sz)<span class="chip"><span class="muted">{{ $sz['size'] }}</span> <b>{{ $sz['qty'] }}</b></span>@endforeach</div>
      @endforeach
    @endforeach
    @php($names = collect($pictures['artwork'])->pluck('name')->filter()->unique())
    @if($names->isNotEmpty())<div class="small muted" style="margin-top: 4px">Artwork received: {{ $names->implode(', ') }}</div>@endif
  @endif
  {{-- One line per style, all branding and per-unit options in it; no price-break columns (Scott, 7 Nov 2026: too confusing). --}}
  @php($styleRows = $items->filter(fn ($r) => count($r['sizes']) > 0))
  @php($runRows = $items->reject(fn ($r) => count($r['sizes']) > 0))
  @php($consolidate = $quote->source === 'website' && $styleRows->isNotEmpty() && $runRows->every(fn ($r) => (float) $r['item']->quantity === (float) $styleRows->sum(fn ($x) => (float) $x['item']->quantity)))
  @if($consolidate)
    @php($runUnit = (float) $runRows->sum(fn ($r) => (float) $r['item']->unitPrice()))
    @php($brandings = $items->map(fn ($r) => $r['item']->decoration_name)->filter()->unique()->values())
    @php($units = (int) $styleRows->sum(fn ($r) => (int) $r['item']->quantity))
    <table class="lines" style="margin-top: 10px">
      <tr class="head"><td class="label muted">Item</td><td class="right label" style="width: 112px">{{ number_format($units) }} units</td><td class="right label muted" style="width: 80px">ex GST</td></tr>
      @foreach ($styleRows as $row)
        @php($i = $row['item'])
        @php($unit = round((float) $i->unitPrice() + $runUnit, 2))
        <tr><td>{{ $i->name }}, branded</td>
          <td class="right" style="white-space: nowrap">{{ number_format($i->quantity) }} × <b>${{ $m($unit) }}</b></td>
          <td class="right" style="white-space: nowrap"><b>${{ $m($unit * (float) $i->quantity) }}</b></td></tr>
      @endforeach
      @if($setupTotal > 0)<tr><td>Setup, once for the order <span class="muted">· {{ $setupNames->implode('; ') }}</span></td><td class="right muted" style="white-space: nowrap">once</td><td class="right" style="white-space: nowrap"><b>${{ $m($setupTotal) }}</b></td></tr>@endif
    </table>
    <div class="small muted" style="margin-top: 4px">Branding: {{ $brandings->implode('; ') }}.</div>
  @else
  <table class="lines" style="margin-top: 10px">
    @foreach ($items as $row)
      @php($i = $row['item'])
      <tr><td>{{ $i->product_unit_price > 0 && $i->hasDecoration() ? $i->name.', with '.$i->decoration_name : ($i->product_unit_price > 0 ? $i->name : ($i->decoration_name ?: $i->name)) }}</td>
        <td class="right muted" style="width: 150px; white-space: nowrap">{{ number_format($i->quantity) }} × ${{ $m($i->unitPrice()) }}</td>
        <td class="right" style="width: 90px; white-space: nowrap"><b>${{ $m($i->goodsTotal()) }}</b></td></tr>
    @endforeach
    @if($setupTotal > 0)<tr><td>Setup, once for the order <span class="muted">· {{ $setupNames->implode('; ') }}</span></td><td class="right muted" style="white-space: nowrap">once</td><td class="right" style="white-space: nowrap"><b>${{ $m($setupTotal) }}</b></td></tr>@endif
  </table>
  @endif
@else
<table>
  @foreach ($items as $n => $row)
    @php($i = $row['item'])
    <tr class="item">
      <td style="width: 132px"><div class="thumb">@if($row['image'])<img src="{{ $row['image'] }}" alt="">@endif</div></td>
      <td style="padding-left: 18px; padding-right: 12px">
        <div class="label orange">Item {{ str_pad($n + 1, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="iname">{{ $i->name }}</div>
        <div class="muted">{{ collect([$i->ourCode() ? 'Code '.$i->ourCode() : null, $i->colour])->filter()->implode(' · ') }}</div>
        @if($i->hasDecoration())<div style="margin-top: 3px">{{ $i->decoration_name }}</div>@endif
        @foreach ($row['sizes'] as $colour => $sizes)
          <div style="margin-top: 5px">@if(count($row['sizes']) > 1 || ($colour !== '' && mb_strtolower($colour) !== mb_strtolower((string) $i->colour)))<span class="small muted">{{ $colour }}&nbsp;</span>@endif
          @foreach ($sizes as $s)<span class="chip"><span class="muted">{{ $s['size'] }}</span> <b>{{ $s['qty'] }}</b></span>@endforeach</div>
        @endforeach
        @if($i->notes)<div class="soft small" style="margin-top: 3px">{{ $i->notes }}</div>@endif
      </td>
      <td class="right" style="width: 140px">
        @if($separate && $i->hasDecoration())
          <div class="muted">Product {{ number_format($i->quantity) }} × {{ $m($i->product_unit_price) }}</div>
          <div class="muted">Branding {{ number_format($i->quantity) }} × {{ $m($i->decoration_unit_price) }}</div>
        @else
          <div class="muted">{{ number_format($i->quantity) }} × ${{ $m($i->unitPrice()) }}</div>
        @endif
        <div class="fig">${{ $m($i->goodsTotal()) }}</div>
        <div class="small" style="font-weight: 700">ex GST</div>
        @if(! $separate && $i->hasDecoration())<div class="muted small">product {{ $m($i->product_unit_price) }}<br>branding {{ $m($i->decoration_unit_price) }} each</div>@endif
      </td>
    </tr>
  @endforeach
  @foreach ($customs as $k => $row)
    @php($x = $row['extra'])
    <tr class="item">
      <td style="width: 132px"><div class="thumb">@if($row['image'])<img src="{{ $row['image'] }}" alt="">@endif</div></td>
      <td style="padding-left: 18px; padding-right: 12px">
        <div class="label orange">Item {{ str_pad($items->count() + $k + 1, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="iname">{{ $x->description }}</div>
      </td>
      <td class="right" style="width: 140px">
        <div class="muted">{{ rtrim(rtrim(number_format((float) $x->quantity, 2), '0'), '.') }} × ${{ $m($x->unit_price) }}</div>
        <div class="fig">${{ $m($x->total()) }}</div>
        <div class="small" style="font-weight: 700">ex GST</div>
      </td>
    </tr>
  @endforeach
</table>
@endif

<table style="margin-top: 12px; page-break-inside: avoid">
  <tr>
    <td style="padding-right: 30px">
      @if($timing)<div class="label orange">Timing</div><div class="soft" style="margin: 3px 0 12px">{{ $timing }}</div>@endif
      @foreach ($info as $line)<div class="soft" style="margin: 0 0 8px">{{ $line->description }}</div>@endforeach
      <div class="label orange">How to go ahead</div>
      @if($quote->source === 'website')
        {{-- Reply, or check out online with this quote's cart rebuilt; payment comes before the proof (Scott, 7 Nov 2026). --}}
        @php($checkout = $quote->checkoutUrl())
        <ol class="soft" style="margin: 3px 0 14px; padding-left: 16px">
          <li>Simply reply to this email{{ $checkout ? ',' : '.' }} @if($checkout)or complete the <a href="{{ $checkout }}" style="color: #D4651F; font-weight: 600">online checkout</a>.@endif</li>
          <li>Pay the invoice.</li>
          <li>Approve the digital proof of your branding and we start production.</li>
        </ol>
      @else
      <ol class="soft" style="margin: 3px 0 14px; padding-left: 16px">
        <li>Reply to accept, or tell us any changes.</li>
        <li>Approve the digital proof of your branding.</li>
        <li>Pay the invoice and we start production.</li>
      </ol>
      @endif
      <table style="width: auto"><tr>
        @if($signature)<td style="padding-right: 12px"><img src="{{ $signature }}" alt="" style="height: 40px"></td>@endif
        <td style="padding-top: 12px"><b>{{ $brand['signed_by'] }}</b><br><span class="muted">{{ $brand['signed_title'] }}</span></td>
      </tr></table>
    </td>
    <td style="width: 320px">
      @if($included->isNotEmpty())
        <table><tr><td class="label muted">Also included</td><td class="right small" style="font-weight: 700">ex GST</td></tr></table>
        <table class="inc" style="margin: 3px 0 12px">
          @foreach ($included as $line)
            <tr><td style="padding-right: 10px">{{ $line['label'] }}</td><td class="right" style="white-space: nowrap">{{ $line['tbc'] ? 'TBC' : $m($line['amount']) }}</td></tr>
          @endforeach
        </table>
      @endif
      <table class="totals"><tr><td style="padding: 12px 16px">
        <table>
          <tr><td style="color: #FFFFFF">Subtotal ex GST</td><td class="right" style="color: #FFFFFF">{{ $m($quote->subtotal) }}</td></tr>
          <tr><td style="color: #FFFFFF; padding-bottom: 8px">{{ $quote->gst_free_export ? 'GST (not charged on exports)' : 'GST' }}</td><td class="right" style="color: #FFFFFF; padding-bottom: 8px">{{ $m($quote->gst) }}</td></tr>
        </table>
        <div style="border-top: 1px solid #EBB591; padding-top: 8px; text-align: right">
          <div class="label" style="color: #FFFFFF">{{ $quote->gst_free_export ? 'Total, no GST' : 'Total inc GST' }}</div>
          <div class="os" style="font-weight: 600; font-size: 30px; line-height: 1; color: #FFFFFF">${{ $m($quote->total) }}</div>
        </div>
      </td></tr></table>
      @if($anyTbc)<div class="muted small" style="margin-top: 5px">Delivery marked TBC is not in this total yet; we will confirm it.</div>@endif
      @if($quote->freight_source === 'none')<div class="muted small" style="margin-top: 5px">Delivery is not included. Ask us if you'd like it added.</div>@endif
      @if($quote->gst_free_export)<div class="muted small" style="margin-top: 5px">Outside Australia: no Australian GST (GST-free export); local duties and taxes are payable by you to the carrier before delivery.</div>@endif
    </td>
  </tr>
</table>
@if($single && ($similar->isNotEmpty() || $saved->isNotEmpty()))
  <div style="page-break-before: always"></div>
  @if(collect($similar)->merge($saved)->contains(fn ($c) => $c['at']))<div class="small muted">Prices to compare: each product at the same total quantity as your quote, with one branding position in its standard branding and that setup, before delivery. Your quote's branding may differ; ask us to price it exactly.</div>@endif
  @foreach([['You might also consider', $similar], ['Products you saved', $saved]] as [$heading, $cards])
    @if($cards->isNotEmpty())
      <div class="label orange" style="margin-top: 14px">{{ $heading }}</div>
      <table style="margin-top: 6px">
        @foreach($cards->chunk(2) as $pair)
          <tr>
            @foreach($pair as $c)
              <td style="width: 50%; padding: 6px 10px 6px 0; vertical-align: top">
                <table><tr>
                  <td style="width: 84px"><div class="thumb-s">@if($c['image'])<img src="{{ $c['image'] }}" alt="">@endif</div></td>
                  <td style="padding-left: 10px; vertical-align: top">
                    <div class="iname" style="font-size: 14px">{{ $c['name'] }}</div>
                    <div class="muted small">{{ $c['code'] ? 'Code '.$c['code'] : '' }}</div>
                    @if($c['at'])
                      <div class="small muted" style="margin-top: 2px">{{ $c['at']['branding'] ? 'Assumes 1 branding position: '.$c['at']['branding'] : 'Unbranded (no standard branding listed)' }}</div>
                      <div class="small">Qty {{ number_format($c['at']['units']) }} × ${{ $m($c['at']['unit']) }}{{ $c['at']['setup'] > 0 ? ' + $'.$m($c['at']['setup']).' setup' : '' }}</div>
                      <div class="small"><b>${{ $m($c['at']['ex']) }}</b> ex GST · <b>${{ $m($c['at']['inc']) }}</b> inc GST</div>
                    @elseif($c['from'])<div class="small" style="margin-top: 2px">From ${{ $m($c['from']) }} each, branded, ex GST</div>@endif
                    <div class="small"><a href="{{ $c['url'] }}" style="color: #D4651F">{{ preg_replace('#^https://#', '', $c['url']) }}</a></div>
                  </td>
                </tr></table>
              </td>
            @endforeach
            @if($pair->count() === 1)<td></td>@endif
          </tr>
        @endforeach
      </table>
    @endif
  @endforeach
  <div class="small muted" style="margin-top: 10px">Ask us to quote any of these at your quantity and branding, or click the links above to calculate pricing on screen.</div>
@endif
</body></html>
