@php($m = fn ($v) => number_format((float) $v, 2))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Product ideas {{ $number }}</title>@include('pdf._style')
<style>
.card { border: 1px solid #E6E3DE; border-radius: 12px; margin-bottom: 14px; page-break-inside: avoid; }
.imgbox { width: 280px; height: 280px; background: #F6F5F2; border-radius: 8px; text-align: center; }
.imgbox img { max-width: 270px; max-height: 270px; margin-top: 5px; }
.pname { font-size: 16px; font-weight: 700; line-height: 1.2; }
.brand { background: #FFF4E6; border-radius: 6px; padding: 6px 8px; margin: 8px 0; }
.opt { border: 1.5px solid #E6E3DE; border-radius: 8px; padding: 7px 9px; }
.opt.best { border-color: #F7941D; background: #FFFBF5; }
.each { font-size: 19px; font-weight: 700; line-height: 1.2; }
.cta { background: #1B1B1F; color: #fff; border-radius: 10px; }
</style></head>
<body>
<div class="footer">{{ $brand['legal'] }}</div>

@if ($banner)<img class="banner" src="{{ $banner }}" alt="Replicat">@endif
<div class="rule"></div>

<table><tr>
  <td><div class="eyebrow">Product ideas</div><div class="title">{{ $quote->title ?: 'Ideas for '.($quote->client_company ?: ($quote->client_name ?: 'you')) }}</div></td>
  <td class="right muted" style="width: 170px; padding-top: 4px"><div class="num" style="color: #1B1B1F; font-size: 12px">{{ $number }}</div>{{ now()->format('j F Y') }}<br>Prices valid {{ config('quotes.valid_days') }} days</td>
</tr></table>
<p class="soft" style="margin: 8px 0 14px">Each price is everything in: product, branding, one-off setup and delivery to you. All prices ex GST and subject to sighting your artwork.</p>

@foreach ($cards as $card)
@php($i = $card['item'])
<table class="card"><tr><td style="padding: 14px">
  <table><tr>
    <td style="width: 280px; padding-right: 18px"><div class="imgbox">@if($card['image'])<img src="{{ $card['image'] }}" alt="">@endif</div></td>
    <td>
      <div class="pname">{{ $i->name }}</div>
      <div class="muted small">{{ collect([$i->product_code ? 'Code '.$i->product_code : null, $i->colour])->filter()->implode(' · ') }}</div>
      @if($i->hasDecoration())<table class="brand"><tr><td style="padding: 6px 8px; width: 64px" class="eyebrow">Branding</td><td style="padding: 6px 8px 6px 0">{{ $i->decoration_name }}</td></tr></table>@endif
      @if($card['intro'])<div class="soft" style="margin-top: 6px">{{ $card['intro'] }}</div>@endif
      @if($card['features'])<ul class="soft" style="margin: 5px 0 0; padding-left: 14px">@foreach($card['features'] as $f)<li>{{ $f }}</li>@endforeach</ul>@endif
      <table style="margin-top: 10px"><tr>
        @foreach (array_slice($card['options'], 0, 3) as $k => $o)
          <td style="{{ $k > 0 ? 'padding-left: 8px;' : '' }} width: 33%">
            <div class="opt {{ $o['best'] ? 'best' : '' }}">
              <div><b>{{ number_format($o['qty']) }} units</b>@if($o['best']) <span style="color: #C8650A; font-size: 8.5px; font-weight: 600">&nbsp;BEST VALUE</span>@endif</div>
              <div class="each">${{ $m($o['each']) }} <span class="muted" style="font-size: 9.5px; font-weight: 400">each</span></div>
              <div class="muted small">${{ $m($o['total']) }} for {{ number_format($o['qty']) }}</div>
            </div>
          </td>
        @endforeach
      </tr></table>
      @php($o0 = $card['options'][0])
      <div class="muted small" style="margin-top: 6px">Includes setup ${{ $m($o0['setup']) }}@if($o0['freight_tbc']), delivery to be confirmed @else and delivery ${{ $m($o0['freight']) }}@endif, shared across the order.</div>
      @if(count($card['options']) > 3)<div class="muted small">More quantities: {{ collect(array_slice($card['options'], 3))->map(fn ($o) => number_format($o['qty']).' at $'.$m($o['each']).' each')->implode(', ') }}</div>@endif
    </td>
  </tr></table>
</td></tr></table>
@endforeach

<table class="cta" style="page-break-inside: avoid"><tr>
  <td style="padding: 12px 16px"><div style="font-weight: 600; font-size: 13px">Like one of these?</div><div style="color: #D6D6DC">Reply with the product and quantity and we'll send a formal quote and proof.</div></td>
  <td class="right" style="padding: 12px 16px"><div style="color: #F7941D; font-weight: 600">{{ $brand['phone'] }}</div><div>{{ $brand['email'] }}</div></td>
</tr></table>
</body></html>
