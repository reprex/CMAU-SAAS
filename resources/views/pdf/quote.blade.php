@php($m = fn ($v) => number_format((float) $v, 2))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Quote {{ $quote->number }}</title>@include('pdf._style')
<style>
.items th { font-size: 8.5px; font-weight: 600; letter-spacing: 1px; color: #5C5C66; text-align: left; padding: 0 0 5px; border-bottom: 1.5px solid #1B1B1F; }
.items td { padding: 10px 0; border-bottom: 1px solid #E6E3DE; }
.thumb { width: 84px; height: 84px; background: #F6F5F2; border-radius: 6px; text-align: center; }
.thumb img { max-width: 80px; max-height: 80px; margin-top: 2px; }
.iname { font-size: 12.5px; font-weight: 600; line-height: 1.25; }
.charge td { padding: 7px 0; border-bottom: 1px solid #E6E3DE; }
.kind { font-size: 8.5px; font-weight: 600; letter-spacing: 1px; color: #5C5C66; }
.total-box { background: #1B1B1F; color: #fff; border-radius: 8px; padding: 10px 12px; }
.total-box .amt { font-size: 19px; font-weight: 700; color: #F7941D; }
</style></head>
<body>
<div class="footer">{{ $brand['legal'] }} · Prices ex GST unless shown, subject to sighting artwork</div>

@if ($banner)<img class="banner" src="{{ $banner }}" alt="Replicat">@endif
<div class="rule"></div>

<table><tr>
  <td style="padding-right: 20px">
    <div class="eyebrow">Quote {{ $quote->number }}@if($quote->revision > 1) · revision {{ $quote->revision }}@endif</div>
    <div class="title">{{ $quote->title ?: 'Your quote' }}</div>
    <p class="soft" style="margin: 6px 0 0">Hi {{ $quote->client_name ? explode(' ', $quote->client_name)[0] : 'there' }}, thanks for the chance to quote on your project. Everything you need to decide is on this page.</p>
  </td>
  <td style="width: 235px">
    <table class="panel" style="width: 235px"><tr><td style="padding: 9px 11px">
      <table>
        <tr><td class="muted" style="width: 62px">For</td><td class="num">{{ $quote->client_company ?: ($quote->client_name ?: $quote->client_email) }}</td></tr>
        @if($quote->client_company && $quote->client_name)<tr><td class="muted">Contact</td><td>{{ $quote->client_name }}</td></tr>@endif
        @if($quote->client_phone || $quote->client_email)<tr><td class="muted">Reach</td><td>{{ collect([$quote->client_phone, $quote->client_email])->filter()->implode(' · ') }}</td></tr>@endif
        <tr><td class="muted">Date</td><td>{{ ($quote->sent_at ?? now())->format('j F Y') }}</td></tr>
        @if($quote->valid_until)<tr><td class="muted">Valid until</td><td>{{ $quote->valid_until->format('j F Y') }}</td></tr>@endif
        @if($quote->deadline)<tr><td class="muted">Needed by</td><td>{{ $quote->deadline->format('j F Y') }}</td></tr>@endif
      </table>
    </td></tr></table>
  </td>
</tr></table>

<table class="items" style="margin-top: 16px">
  <thead><tr><th style="width: 96px"></th><th>Item</th><th class="right" style="width: 50px">Qty</th><th class="right" style="width: 66px">Each</th><th class="right" style="width: 76px">Total</th></tr></thead>
  <tbody>
  @foreach ($items as $row)
    @php($i = $row['item'])
    <tr>
      <td><div class="thumb">@if($row['image'])<img src="{{ $row['image'] }}" alt="">@endif</div></td>
      <td style="padding-right: 10px">
        <div class="iname">{{ $i->name }}</div>
        <div class="muted small">{{ collect([$i->product_code ? 'Code '.$i->product_code : null, $i->colour])->filter()->implode(' · ') }}</div>
        @if($i->hasDecoration() && ! $separate)<div class="soft" style="margin-top: 3px"><span style="color: #C8650A; font-weight: 600">Branding</span> {{ $i->decoration_name }}</div>@endif
        @foreach ($row['sizes'] as $colour => $sizes)
          <div style="margin-top: 4px">@if(count($row['sizes']) > 1 || ($colour !== '' && mb_strtolower($colour) !== mb_strtolower((string) $i->colour)))<span class="small muted">{{ $colour }}&nbsp;</span>@endif
          @foreach ($sizes as $s)<span class="chip"><span class="muted">{{ $s['size'] }}</span> <b>{{ $s['qty'] }}</b></span>@endforeach</div>
        @endforeach
        @if(! $separate && $i->hasDecoration())<div class="muted small" style="margin-top: 2px">Product {{ $m($i->product_unit_price) }} + branding {{ $m($i->decoration_unit_price) }} each</div>@endif
        @if($i->notes)<div class="soft small" style="margin-top: 2px">{{ $i->notes }}</div>@endif
      </td>
      @if($separate)
        <td class="right num">{{ $i->quantity }}</td><td class="right">{{ $m($i->product_unit_price) }}</td><td class="right num">{{ $m($i->productGoodsTotal()) }}</td>
      @else
        <td class="right num">{{ $i->quantity }}</td><td class="right">{{ $m($i->unitPrice()) }}</td><td class="right num">{{ $m($i->goodsTotal()) }}</td>
      @endif
    </tr>
    @if($separate && $i->hasDecoration())
    <tr class="charge"><td></td><td style="padding: 7px 10px 7px 0"><span class="kind">BRANDING</span>&nbsp; {{ $i->decoration_name }}</td><td class="right num" style="padding: 7px 0">{{ $i->quantity }}</td><td class="right" style="padding: 7px 0">{{ $m($i->decoration_unit_price) }}</td><td class="right num" style="padding: 7px 0">{{ $m($i->decorationGoodsTotal()) }}</td></tr>
    @endif
  @endforeach
  @foreach ($extras as $x)
    <tr>
      <td>@if($x->kind === 'custom')<div class="thumb">@if($x->image_url)<img src="{{ app(\App\Pdf\ImageEmbedder::class)->dataUri($x->image_url) }}" alt="">@endif</div>@else<span class="kind">SERVICE</span>@endif</td>
      <td style="padding-right: 10px"><div class="{{ $x->kind === 'custom' ? 'iname' : '' }}">{{ $x->description }}</div></td>
      <td class="right num">{{ rtrim(rtrim(number_format((float) $x->quantity, 2), '0'), '.') }}</td><td class="right">{{ $m($x->unit_price) }}</td><td class="right num">{{ $m($x->total()) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

<table class="charge">
  @foreach ($setups as $s)
    <tr><td style="width: 96px"><span class="kind">SETUP</span></td><td>{{ $s['label'] }}</td><td class="right num" style="width: 76px">{{ $m($s['total']) }}</td></tr>
  @endforeach
  @foreach ($freight as $f)
    <tr><td style="width: 96px"><span class="kind">DELIVERY</span></td><td>{{ $f->description }}</td><td class="right num" style="width: 76px">{{ $f->tbc ? 'TBC' : $m($f->total()) }}</td></tr>
  @endforeach
</table>

<table style="margin-top: 14px; page-break-inside: avoid"><tr>
  <td style="padding-right: 24px">
    @if($quote->lead_time)
    <table class="panel"><tr><td style="padding: 8px 10px; width: 62px" class="eyebrow">Timing</td><td style="padding: 8px 10px 8px 0">{{ $quote->lead_time }}</td></tr></table>
    @endif
    @foreach ($info as $line)<p class="soft" style="margin: 6px 0 0">{{ $line->description }}</p>@endforeach
    <div style="font-weight: 600; font-size: 12px; margin-top: 10px">How to go ahead</div>
    <ol class="soft" style="margin: 3px 0 0; padding-left: 16px">
      <li>Reply to accept, or tell us any changes.</li>
      <li>We send a digital proof of your branding to approve.</li>
      <li>Pay the invoice and we start production.</li>
    </ol>
  </td>
  <td style="width: 250px">
    <table>
      <tr><td class="muted" style="padding: 2px 0">Subtotal ex GST</td><td class="right">{{ $m($quote->subtotal) }}</td></tr>
      <tr><td class="muted" style="padding: 2px 0 8px">GST</td><td class="right">{{ $m($quote->gst) }}</td></tr>
    </table>
    <table class="total-box"><tr><td style="padding: 10px 12px; font-weight: 600; font-size: 12px">Total inc GST</td><td class="right amt" style="padding: 7px 12px">${{ $m($quote->total) }}</td></tr></table>
    @if($freight->contains('tbc', true))<p class="small muted" style="margin: 5px 0 0">Some delivery is still to be confirmed and is not in this total.</p>@endif
  </td>
</tr></table>

<table style="margin-top: 22px; page-break-inside: avoid"><tr>
  <td style="width: 120px">@if($signature)<img src="{{ $signature }}" alt="" style="height: 44px">@endif</td>
  <td style="padding-top: 14px"><div class="num">{{ $brand['signed_by'] }}</div><div class="muted">{{ $brand['signed_title'] }}</div></td>
  <td class="right muted" style="padding-top: 14px">{{ $brand['phone'] }} · {{ $brand['email'] }}</td>
</tr></table>
</body></html>
