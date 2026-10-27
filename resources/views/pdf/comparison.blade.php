@php($m = fn ($v) => number_format((float) $v, 2))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Product comparison {{ $number }}</title>@include('pdf._style')
<style>
.card { margin-top: 16px; page-break-inside: avoid; }
.imgbox { width: 290px; height: 290px; background: #F4F3F0; text-align: center; position: relative; }
.imgbox img { max-width: 274px; max-height: 274px; margin-top: 8px; }
.tag { position: absolute; left: 0; top: 12px; background: #1A1B18; color: #FFFFFF; padding: 3px 9px; }
.pname { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 22px; text-transform: uppercase; line-height: 1.05; }
.ladder { border-top: 2px solid #1A1B18; }
.ladder td { border-bottom: 1px solid #E4E1DB; padding: 3px 0; vertical-align: middle; }
.q { font-family: 'Oswald', sans-serif; font-weight: 500; font-size: 14px; line-height: 15px; }
.bk { font-size: 8.5px; color: #3C3B37; line-height: 11px; }
.e { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 19px; line-height: 20px; }
.es { font-size: 8px; line-height: 10px; }
.tbc { background: #FBEDE3; border-left: 3px solid #D4651F; padding: 3px 7px; margin-bottom: 4px; font-size: 9px; line-height: 12px; }
</style></head>
<body>
<div class="footer"><table><tr><td><b style="color: #1A1B18">Like one? Reply with the product and quantity.</b> {{ $brand['phone'] }} · {{ $brand['email'] }}</td><td class="right">{{ $brand['legal'] }}</td></tr></table></div>

@include('pdf._header', [
  'number' => $number,
  'dateLine' => now()->format('j F Y').' · prices valid '.config('quotes.valid_days').' days',
  'kicker' => 'Product comparison for',
  'client' => $quote->client_company ?: ($quote->client_name ?: ($quote->client_email ?: 'You')),
  'title' => $quote->title ?: 'Product ideas',
])
<div class="rule"></div>

@foreach ($cards as $n => $card)
@php($i = $card['item'])
@if($n > 0 && $n % 2 === 0)<div style="page-break-before: always"></div>@endif
<table class="card"><tr>
  <td style="width: 290px"><div class="imgbox"><div class="tag label">Idea {{ str_pad($n + 1, 2, '0', STR_PAD_LEFT) }}</div>@if($card['image'])<img src="{{ $card['image'] }}" alt="">@endif</div></td>
  <td style="padding-left: 22px">
    <div class="pname">{{ $i->name }}</div>
    <div class="muted" style="margin-top: 2px; line-height: 13px">{{ collect([$i->product_code ? 'Code '.$i->product_code : null, $i->colour])->filter()->implode(' · ') }}</div>
    @if($card['intro'])<div class="soft" style="margin-top: 4px; line-height: 14px">{{ $card['intro'] }}</div>@endif
    @if($i->hasDecoration())<div style="margin-top: 5px"><b>Branding:</b> {{ $i->decoration_name }}</div>@endif
    <div class="label muted" style="margin: 8px 0 3px">{{ $card['all_in'] ? 'Price each, all in' : 'Price each' }}</div>
    @unless($card['all_in'])
      <div class="tbc"><b>{{ $i->name }} only:</b> prices include product, branding and setup. Delivery is not included yet and will be confirmed on your quote.</div>
    @endunless
    <table class="ladder">
      @foreach ($card['options'] as $o)
        <tr>
          <td style="width: 74px"><div class="q">{{ number_format($o['qty']) }} units</div>@if($o['best'])<div class="orange" style="font-size: 7.5px; font-weight: 800; letter-spacing: 1px">BEST VALUE</div>@endif</td>
          <td class="bk">
            {{ number_format($o['qty']) }} × {{ $m($o['unit']) }} = {{ $m($o['goods']) }}<br>
            + setup {{ $m($o['setup']) }}@if($o['freight'] > 0) + delivery {{ $m($o['freight']) }}@endif @if($o['freight_tbc'])+ <b class="orange">delivery TBC</b>@endif<br>
            <b>= ${{ $m($o['total']) }} ex GST</b> <span class="muted">· ${{ $m($o['total_inc']) }} inc</span>
          </td>
          <td class="right" style="width: 92px">
            <div class="e {{ $o['best'] ? 'orange' : '' }}">${{ $m($o['each']) }}</div>
            <div class="es" style="font-weight: 700">each ex GST</div>
            <div class="muted es">${{ $m($o['each_inc']) }} inc GST</div>
          </td>
        </tr>
      @endforeach
    </table>
    @if($card['more'])<div class="muted small" style="margin-top: 4px">Also available: {{ collect($card['more'])->map(fn ($o) => number_format($o['qty']).' at $'.$m($o['each']).' each ex GST')->implode(', ') }}.</div>@endif
  </td>
</tr></table>
@endforeach
</body></html>
