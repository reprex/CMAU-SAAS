@php($m = fn ($v) => number_format((float) $v, 2))
@php($o = $order)
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $o->number }}</title>@include('pdf._style')
<style>
.item td { border-bottom: 1px solid #E4E1DB; padding: 10px 0; }
.thumb { width: 110px; height: 110px; background: #F4F3F0; text-align: center; }
.thumb img { max-width: 102px; max-height: 102px; margin-top: 4px; }
.iname { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 16px; text-transform: uppercase; line-height: 1.1; margin: 2px 0 3px; }
.fig { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 18px; line-height: 1.1; }
.inc td { border-bottom: 1px solid #E4E1DB; padding: 5px 0; }
.totals { background: #D4651F; color: #FFFFFF; }
.alert { border: 1.5px solid #D4651F; background: #FCF1EA; padding: 8px 10px; margin: 0 0 6px; }
.stage td { border-bottom: 1px solid #E4E1DB; padding: 2px 0; }
.lines td { border-bottom: 1px solid #E4E1DB; padding: 4px 0; }
.lines .head td { padding-top: 0; }
.iname2 { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 13px; text-transform: uppercase; margin: 4px 0 2px; }
.lthumb { height: 42px; background: #F4F3F0; text-align: center; }
.lthumb img { max-width: 40px; max-height: 38px; margin-top: 2px; }
.tiny { font-size: 8px; }
.center { text-align: center; }
.thumb { width: 150px; height: 150px; }
.thumb img { max-width: 142px; max-height: 142px; }
</style></head>
<body>
<div class="footer"><table><tr><td>{{ $brand['signed_by'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</td><td class="right">{{ $brand['legal'] }}</td></tr></table></div>

@include('pdf._header', [
  'number' => ($o->export ? 'Invoice ' : 'Tax invoice ').$o->number,
  'dateLine' => $o->created_at->timezone(config('app.timezone'))->format('j F Y').' · '.(config('website.payment_terms.options.'.$o->payment_terms) ?? $o->payment_terms),
  'kicker' => 'Invoice for',
  'client' => $o->organisation ?: $o->contact_name,
  'title' => 'Your order',
])
<div class="muted" style="margin-top: 5px">{{ collect([$o->organisation ? 'Attention '.$o->contact_name : null, $o->phone, $o->email])->filter()->implode(' · ') }}</div>
<div class="rule"></div>

@if(! empty($o->alerts))
  <div style="margin-top: 8px">
    <div class="label orange">Before you pay</div>
    @foreach($o->alerts as $alert)<div class="alert">{{ $alert['message'] }}</div>@endforeach
  </div>
@endif

{{-- One product (with any related styles in the same run), everything from the checkout, on one page (Scott, 7 Nov 2026). --}}
<table style="margin-top: 10px">
  <tr>
    <td style="width: 150px; vertical-align: top">
      <div class="thumb">@if($image)<img src="{{ $image }}" alt="">@endif</div>
      @if(count($logos))
        <table style="margin-top: 6px"><tr>
          @foreach(array_slice($logos, 0, 3) as $lg)<td style="width: 33%; padding-right: 4px"><div class="lthumb"><img src="{{ $lg['src'] }}" alt=""></div><div class="tiny muted center">{{ $lg['position'] }}</div></td>@endforeach
        </tr></table>
      @endif
    </td>
    <td style="padding-left: 16px; vertical-align: top">
      @foreach ($styles as $pid => $lines)
        @php($first = $lines->first())
        <div class="{{ $loop->first ? 'iname' : 'iname2' }}">{{ $first->product_name }}</div>
        <div class="muted small">Code {{ $o->ourCodeFor($first) }} · {{ number_format($lines->sum('qty')) }} units</div>
        @foreach($lines->groupBy('colour') as $colour => $sizes)
          <div style="margin-top: 3px"><span class="small muted">{{ $colour }}&nbsp;</span>@foreach($sizes as $s)<span class="chip"><span class="muted">{{ $s->size }}</span> <b>{{ $s->qty }}</b></span>@endforeach</div>
        @endforeach
        <div style="height: 6px"></div>
      @endforeach
      <div class="label muted" style="margin-top: 2px">Branding{{ $styles->count() > 1 ? ' (the same on every style)' : '' }}</div>
      @foreach($o->positions as $p)
        <div class="small">{{ $p->position }} · {{ $p->methodName() }}@if($p->colours) · {{ $p->colours }} colour{{ $p->colours === 1 ? '' : 's' }}@endif @if($p->width_mm) · {{ $p->width_mm }} mm wide @endif</div>
      @endforeach
      @if(! empty($o->options))
        <div class="label muted" style="margin-top: 6px">Options</div>
        @foreach($o->options as $opt)<div class="small">{{ $opt['group'] ?? '' }}{{ isset($opt['group']) ? ': ' : '' }}{{ $opt['name'] }}{{ ($opt['n'] ?? 1) > 1 ? ' × '.$opt['n'] : '' }}</div>@endforeach
      @endif
    </td>
  </tr>
</table>

<table class="lines" style="margin-top: 10px">
  <tr class="head"><td class="label muted">Item</td><td class="right label muted" style="width: 130px">Qty × each</td><td class="right label muted" style="width: 90px">ex GST</td></tr>
  @foreach ($styles as $pid => $lines)
    @php($first = $lines->first())
    <tr><td>{{ $first->product_name }}, branded</td><td class="right muted">{{ number_format($lines->sum('qty')) }} × ${{ $m($first->unit_ex_gst) }}</td><td class="right">{{ $m($lines->sum(fn ($l) => (float) $l->total_ex_gst)) }}</td></tr>
  @endforeach
  @foreach($o->positions as $p)
    @if((float) $p->setup_ex_gst > 0)<tr><td>Setup: {{ $p->position }} ({{ $p->methodName() }})</td><td class="right muted">once</td><td class="right">{{ $m($p->setup_ex_gst) }}</td></tr>@endif
  @endforeach
  @foreach($o->options ?? [] as $opt)
    @if((float) ($opt['once'] ?? 0) > 0)<tr><td>{{ $opt['name'] }}</td><td class="right muted">once</td><td class="right">{{ $m($opt['once']) }}</td></tr>@endif
  @endforeach
  @php($listedSetup = $o->positions->sum(fn ($p) => (float) $p->setup_ex_gst) + collect($o->options ?? [])->sum(fn ($x) => (float) ($x['once'] ?? 0)))
  @if((float) $o->setup - $listedSetup > 0.004)<tr><td>Setup (once for the order)</td><td class="right muted">once</td><td class="right">{{ $m((float) $o->setup - $listedSetup) }}</td></tr>@endif
  <tr><td>Delivery · {{ $o->freight_name }}</td><td class="right muted"></td><td class="right">{{ $m($o->freight) }}</td></tr>
</table>

<table style="margin-top: 18px; page-break-inside: avoid">
  <tr>
    <td style="padding-right: 30px">
      <div class="label orange">Delivery</div>
      <div class="soft" style="margin: 3px 0 6px">To {{ $o->addressLine() }} · {{ $o->freight_name }} · estimated <b>{{ $o->eta->format('D j M Y') }}</b>@if($o->need_by) · needed by {{ $o->need_by->format('D j M Y') }}@endif</div>
      @if($o->event_name || $o->event_date)<div class="small" style="margin: 0 0 6px">Event: {{ collect([$o->event_name, $o->event_date?->format('D j M Y'), $o->event_place])->filter()->implode(' · ') }}</div>@endif
      @if(! empty($o->eta_breakdown['stages']))
        <table class="stage tiny" style="margin-bottom: 8px">
          @foreach($o->eta_breakdown['stages'] as $st)<tr><td>{{ $st['name'] }}</td><td class="right muted">{{ $st['days'] }} business day{{ $st['days'] === 1 ? '' : 's' }} · by {{ \Carbon\CarbonImmutable::parse($st['ends'])->format('D j M') }}</td></tr>@endforeach
        </table>
      @endif
      <div class="label orange">What happens next</div>
      <ol class="soft" style="margin: 3px 0 10px; padding-left: 16px">
        <li>Pay this invoice by card or bank transfer{{ $payment ? ':' : '.' }}@if($payment)<div class="small" style="white-space: pre-line">{{ $payment }}</div>@endif</li>
        <li>{{ config('website.orders.proof_ack_text') }}</li>
        <li>We order the stock, brand it and send it to you.</li>
      </ol>
      @if($o->export && ! collect($o->alerts ?? [])->contains('code', 'export'))<div class="alert">{{ config('website.orders.duty_note') }}</div>@endif
    </td>
    <td style="width: 300px">
      <table class="totals"><tr><td style="padding: 14px 16px">
        <table>
          <tr><td style="color: #FFFFFF">Subtotal ex GST</td><td class="right" style="color: #FFFFFF">{{ $m($o->subtotal) }}</td></tr>
          <tr><td style="color: #FFFFFF; padding-bottom: 8px">{{ $o->export ? 'GST (not charged on exports)' : 'GST 10%' }}</td><td class="right" style="color: #FFFFFF; padding-bottom: 8px">{{ $m($o->gst) }}</td></tr>
        </table>
        <div style="border-top: 1px solid #EBB591; padding-top: 8px; text-align: right">
          <div class="label" style="color: #FFFFFF">{{ $o->export ? 'Total ex GST' : 'Total inc GST' }}</div>
          <div class="os" style="font-weight: 600; font-size: 32px; line-height: 1; color: #FFFFFF">${{ $m($o->total) }}</div>
        </div>
      </td></tr></table>
      <div class="muted small" style="margin-top: 5px">AUD. Prices recalculated by CrowdMerch when the order was placed.</div>
    </td>
  </tr>
</table>
</body></html>
