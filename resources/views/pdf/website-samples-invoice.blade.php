@php($m = fn ($v) => number_format((float) $v, 2))
@php($o = $order)
{{-- One invoice for the client's samples cart, by factory. Never shows supplier names or supplier codes. --}}
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $o->number }}</title>@include('pdf._style')
<style>
.fac td { border-bottom: 1px solid #E4E1DB; padding: 6px 0; }
.fsub td { padding: 6px 0 14px; font-weight: 700; }
.inc td { border-bottom: 1px solid #E4E1DB; padding: 5px 0; }
.stage td { border-bottom: 1px solid #E4E1DB; padding: 3px 0; }
</style></head>
<body>
<div class="footer"><table><tr><td>{{ $brand['signed_by'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</td><td class="right">{{ $brand['legal'] }}</td></tr></table></div>

@include('pdf._header', [
  'number' => 'Tax invoice '.$o->number,
  'dateLine' => $o->created_at->timezone(config('app.timezone'))->format('j F Y').' · '.(config('website.payment_terms.options.'.$o->payment_terms) ?? $o->payment_terms),
  'kicker' => 'Invoice for',
  'client' => $o->organisation ?: $o->contact_name,
  'title' => 'Your samples',
])
<div class="muted" style="margin-top: 5px">{{ collect([$o->organisation ? 'Attention '.$o->contact_name : null, $o->phone, $o->email])->filter()->implode(' · ') }}</div>
<div class="muted">Delivering to {{ $o->addressLine() }}</div>
<div class="rule"></div>
<div class="soft" style="margin: 10px 0 4px">{{ config('website.samples.note') }}</div>

@foreach($factories as $f)
  <div class="label orange" style="margin-top: 12px">{{ $f['label'] }}</div>
  <table class="fac">
    @foreach($f['lines'] as $l)
      <tr><td>{{ $l->product_name }} <span class="muted">· Code {{ $o->ourCodeFor($l) }}@if($l->colour) · {{ $l->colour }}@endif</span></td><td class="right" style="width: 120px">{{ $m($l->total_ex_gst) }}</td></tr>
    @endforeach
    <tr><td>Freight from factory {{ $f['factory'] }}</td><td class="right">{{ $m($f['freight']) }}</td></tr>
  </table>
  <table class="fsub"><tr><td>Factory {{ $f['factory'] }} subtotal ex GST</td><td class="right" style="width: 120px">{{ $m($f['subtotal']) }}</td></tr></table>
@endforeach

<table style="margin-top: 18px; page-break-inside: avoid">
  <tr>
    <td style="padding-right: 30px">
      <div class="label orange">Delivery</div>
      <div class="soft" style="margin: 3px 0 8px">Estimated <b>{{ $o->eta->format('D j M Y') }}</b>@if(count($factories) > 1). Each factory sends its samples separately; this is the latest.@endif</div>
      @if(! empty($o->eta_breakdown['stages']))
        <table class="stage small" style="margin-bottom: 10px">
          @foreach($o->eta_breakdown['stages'] as $st)<tr><td>{{ $st['name'] }}</td><td class="right muted">{{ $st['days'] }} business day{{ $st['days'] === 1 ? '' : 's' }} · by {{ \Carbon\CarbonImmutable::parse($st['ends'])->format('D j M') }}</td></tr>@endforeach
        </table>
      @endif
      <div class="label orange">What happens next</div>
      <ol class="soft" style="margin: 3px 0 10px; padding-left: 16px">
        <li>Pay this invoice by card or bank transfer.</li>
        <li>The factories send your samples to you.</li>
      </ol>
    </td>
    <td style="width: 300px">
      <table class="totals" style="background: #D4651F"><tr><td style="padding: 14px 16px">
        <table>
          <tr><td style="color: #FFFFFF">Samples</td><td class="right" style="color: #FFFFFF">{{ $m($o->goods) }}</td></tr>
          <tr><td style="color: #FFFFFF">Freight</td><td class="right" style="color: #FFFFFF">{{ $m($o->freight) }}</td></tr>
          <tr><td style="color: #FFFFFF">Subtotal ex GST</td><td class="right" style="color: #FFFFFF">{{ $m($o->subtotal) }}</td></tr>
          <tr><td style="color: #FFFFFF; padding-bottom: 8px">GST 10%</td><td class="right" style="color: #FFFFFF; padding-bottom: 8px">{{ $m($o->gst) }}</td></tr>
        </table>
        <div style="border-top: 1px solid #EBB591; padding-top: 8px; text-align: right">
          <div class="label" style="color: #FFFFFF">Total inc GST</div>
          <div class="os" style="font-weight: 600; font-size: 32px; line-height: 1; color: #FFFFFF">${{ $m($o->total) }}</div>
        </div>
      </td></tr></table>
      <div class="muted small" style="margin-top: 5px">AUD. Prices recalculated by CrowdMerch when the order was placed.</div>
    </td>
  </tr>
</table>
</body></html>
