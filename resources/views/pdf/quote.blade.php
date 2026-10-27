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

<table>
  @foreach ($items as $n => $row)
    @php($i = $row['item'])
    <tr class="item">
      <td style="width: 132px"><div class="thumb">@if($row['image'])<img src="{{ $row['image'] }}" alt="">@endif</div></td>
      <td style="padding-left: 18px; padding-right: 12px">
        <div class="label orange">Item {{ str_pad($n + 1, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="iname">{{ $i->name }}</div>
        <div class="muted">{{ collect([$i->product_code ? 'Code '.$i->product_code : null, $i->colour])->filter()->implode(' · ') }}</div>
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

<table style="margin-top: 18px; page-break-inside: avoid">
  <tr>
    <td style="padding-right: 30px">
      @if($timing)<div class="label orange">Timing</div><div class="soft" style="margin: 3px 0 12px">{{ $timing }}</div>@endif
      @foreach ($info as $line)<div class="soft" style="margin: 0 0 8px">{{ $line->description }}</div>@endforeach
      <div class="label orange">How to go ahead</div>
      <ol class="soft" style="margin: 3px 0 14px; padding-left: 16px">
        <li>Reply to accept, or tell us any changes.</li>
        <li>Approve the digital proof of your branding.</li>
        <li>Pay the invoice and we start production.</li>
      </ol>
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
      <table class="totals"><tr><td style="padding: 16px 18px">
        <table>
          <tr><td style="color: #FFFFFF">Subtotal ex GST</td><td class="right" style="color: #FFFFFF">{{ $m($quote->subtotal) }}</td></tr>
          <tr><td style="color: #FFFFFF; padding-bottom: 8px">GST</td><td class="right" style="color: #FFFFFF; padding-bottom: 8px">{{ $m($quote->gst) }}</td></tr>
        </table>
        <div style="border-top: 1px solid #EBB591; padding-top: 8px; text-align: right">
          <div class="label" style="color: #FFFFFF">Total inc GST</div>
          <div class="os" style="font-weight: 600; font-size: 36px; line-height: 1; color: #FFFFFF">${{ $m($quote->total) }}</div>
        </div>
      </td></tr></table>
      @if($anyTbc)<div class="muted small" style="margin-top: 5px">Delivery marked TBC is not in this total yet; we will confirm it.</div>@endif
    </td>
  </tr>
</table>
</body></html>
