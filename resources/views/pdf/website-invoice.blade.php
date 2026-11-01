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
.stage td { border-bottom: 1px solid #E4E1DB; padding: 3px 0; }
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
<div class="muted">Delivering to {{ $o->addressLine() }}</div>
<div class="rule"></div>

@if(! empty($o->alerts))
  <div style="margin-top: 12px">
    <div class="label orange">Before you pay</div>
    @foreach($o->alerts as $alert)<div class="alert">{{ $alert['message'] }}</div>@endforeach
  </div>
@endif

<table>
  @foreach ($styles as $pid => $lines)
    @php($first = $lines->first())
    <tr class="item">
      <td style="width: 110px"><div class="thumb">@if($loop->first && $image)<img src="{{ $image }}" alt="">@endif</div></td>
      <td style="padding-left: 16px; padding-right: 12px">
        <div class="label orange">Item {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="iname">{{ $first->product_name }}</div>
        <div class="muted">Code {{ $first->product_code }}</div>
        @foreach($lines->groupBy('colour') as $colour => $sizes)
          <div style="margin-top: 4px"><span class="small muted">{{ $colour }}&nbsp;</span>@foreach($sizes as $s)<span class="chip"><span class="muted">{{ $s->size }}</span> <b>{{ $s->qty }}</b>@if($s->supplier_sku) <span class="muted">{{ $s->supplier_sku }}</span>@endif</span>@endforeach</div>
        @endforeach
      </td>
      <td class="right" style="width: 140px">
        <div class="muted">{{ number_format($lines->sum('qty')) }} × ${{ $m($first->unit_ex_gst) }}</div>
        <div class="fig">${{ $m($lines->sum(fn ($l) => (float) $l->total_ex_gst)) }}</div>
        <div class="small" style="font-weight: 700">ex GST</div>
      </td>
    </tr>
  @endforeach
</table>

<table style="margin-top: 12px">
  <tr><td class="label muted" colspan="2">Branding (the same on every style)</td></tr>
  @foreach($o->positions as $p)
    <tr><td style="padding: 3px 0">{{ $p->position }} · {{ $p->methodName() }}@if($p->colours) · {{ $p->colours }} colour{{ $p->colours === 1 ? '' : 's' }}@endif @if($p->width_mm) · {{ $p->width_mm }} mm wide @endif</td><td class="right muted">{{ $m($p->unit_ex_gst) }} each, setup {{ $m($p->setup_ex_gst) }}</td></tr>
  @endforeach
</table>

<table style="margin-top: 18px; page-break-inside: avoid">
  <tr>
    <td style="padding-right: 30px">
      <div class="label orange">Delivery</div>
      <div class="soft" style="margin: 3px 0 8px">{{ $o->freight_name }} · estimated <b>{{ $o->eta->format('D j M Y') }}</b> · needed by {{ $o->need_by->format('D j M Y') }}@if($o->event_name) · {{ $o->event_name }}@endif</div>
      @if(! empty($o->eta_breakdown['stages']))
        <table class="stage small" style="margin-bottom: 10px">
          @foreach($o->eta_breakdown['stages'] as $st)<tr><td>{{ $st['name'] }}</td><td class="right muted">{{ $st['days'] }} business day{{ $st['days'] === 1 ? '' : 's' }} · by {{ \Carbon\CarbonImmutable::parse($st['ends'])->format('D j M') }}</td></tr>@endforeach
        </table>
      @endif
      <div class="label orange">What happens next</div>
      <ol class="soft" style="margin: 3px 0 10px; padding-left: 16px">
        <li>Pay this invoice by card or bank transfer.</li>
        <li>{{ config('website.orders.proof_ack_text') }}</li>
        <li>We order the stock, brand it and send it to you.</li>
      </ol>
      @if($o->export && ! collect($o->alerts ?? [])->contains('code', 'export'))<div class="alert">{{ config('website.orders.duty_note') }}</div>@endif
    </td>
    <td style="width: 300px">
      <table><tr><td class="label muted">Also included</td><td class="right small" style="font-weight: 700">ex GST</td></tr></table>
      <table class="inc" style="margin: 3px 0 12px">
        @if((float) $o->setup > 0)<tr><td>Setup (once for the order)</td><td class="right">{{ $m($o->setup) }}</td></tr>@endif
        <tr><td>Delivery · {{ $o->freight_name }}</td><td class="right">{{ $m($o->freight) }}</td></tr>
      </table>
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
