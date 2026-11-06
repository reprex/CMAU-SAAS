@php($m = fn ($v) => $v === null ? '–' : number_format((float) $v, 2))
@php($d = $po->deliver_to)
@php($s = $po->supplier)
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $po->number }}</title>@include('pdf._style')
<style>
.lines td { border-bottom: 1px solid #E4E1DB; padding: 3px 0; }
.lines .head td { padding-top: 0; }
.box { border: 1.5px solid #D4651F; background: #FCF1EA; padding: 8px 10px; margin: 8px 0; }
.kv td { padding: 2px 0; vertical-align: top; }
</style></head>
<body>
<div class="footer"><table><tr><td>{{ $brand['signed_by'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</td><td class="right">{{ $brand['legal'] }}</td></tr></table></div>

@include('pdf._header', [
  'number' => 'Purchase order '.$po->number,
  'dateLine' => $po->created_at->timezone(config('app.timezone'))->format('j F Y'),
  'kicker' => 'Purchase order to',
  'client' => $s?->name ?? 'Supplier',
  'title' => 'Purchase order',
])
<div class="muted" style="margin-top: 5px">{{ collect([$s?->orders_contact ? 'Attention '.$s->orders_contact : null, $s?->orders_email, $s?->our_account_number ? 'Our account '.$s->our_account_number : null])->filter()->implode(' · ') }}</div>
<div class="rule"></div>

{{-- Simple on purpose (Scott, 7 Nov 2026): what to make, at what cost, where to, by when, blind. --}}

<table style="margin-top: 6px"><tr>
  <td style="width: 50%; vertical-align: top; padding-right: 16px">
    <div class="label orange">Blind ship to</div>
    <div style="margin-top: 3px"><b>{{ $d['organisation'] ?: $d['name'] }}</b>@if($d['organisation'])<br>Attention {{ $d['name'] }}@endif<br>
      {{ trim(($d['unit'] ? $d['unit'].'/' : '').$d['street']) }}<br>{{ trim($d['suburb'].' '.($d['state'] ?? '').' '.$d['postcode']) }}@if(($d['country'] ?? 'AU') !== 'AU')<br>{{ \App\Freight\Countries::name($d['country']) }}@endif<br>Phone {{ $d['phone'] }}</div>
    <div style="margin-top: 6px">Show sender as <b>Replicat / CrowdMerch, {{ $brand['phone'] }}</b>.</div>
    <div class="small" style="margin-top: 2px">Please do not put any supplier identification or docs with the shipment.</div>
  </td>
  <td style="vertical-align: top">
    <div class="label orange">Deadline</div>
    <table class="kv" style="margin-top: 3px">
      @if($po->despatch_by)<tr><td style="width: 110px">Despatch by</td><td><b>{{ $po->despatch_by->format('D j M Y') }}</b></td></tr>@endif
      @if($po->need_by)<tr><td>Client needs it by</td><td><b>{{ $po->need_by->format('D j M Y') }}</b></td></tr>@endif
      @if($po->event)<tr><td>For their event</td><td>{{ $po->event }}</td></tr>@endif
    </table>
    <div class="small muted" style="margin-top: 4px">Please let us know before proofing if this date is a problem.</div>
  </td>
</tr></table>

<div class="label orange" style="margin-top: 12px">Goods</div>
<table class="lines" style="margin-top: 4px">
  <tr class="head"><td class="label muted">Product</td><td class="label muted">Colour</td><td class="label muted">Size</td><td class="label muted">Your SKU</td><td class="right label muted">Qty</td><td class="right label muted">Unit cost</td><td class="right label muted">Total</td></tr>
  @foreach($po->lines as $l)
    <tr><td>{{ $l['name'] }} <span class="muted">{{ $l['code'] }}</span></td><td>{{ $l['colour'] }}</td><td>{{ $l['size'] }}</td><td>{{ $l['supplier_sku'] ?: '–' }}</td>
      <td class="right">{{ number_format($l['qty']) }}</td><td class="right">{{ $m($l['unit_cost']) }}</td><td class="right">{{ $m($l['total']) }}</td></tr>
  @endforeach
</table>

<div class="label orange" style="margin-top: 12px">Branding</div>
<table class="lines" style="margin-top: 4px">
  <tr class="head"><td class="label muted">Position</td><td class="label muted">Decoration</td><td class="right label muted">Units</td><td class="right label muted">Unit cost</td><td class="right label muted">Setup</td><td class="right label muted">Total</td></tr>
  @foreach($po->branding as $b)
    <tr><td>{{ $b['position'] }}</td><td>{{ $b['decoration'] }}{{ $b['colours'] ? ', '.$b['colours'].' colour'.($b['colours'] > 1 ? 's' : '') : '' }}{{ $b['width_mm'] ? ', '.$b['width_mm'].' mm wide' : '' }}@if($b['logo'])<div class="small muted">Artwork: {{ $b['logo'] }}</div>@endif</td>
      <td class="right">{{ number_format($b['units']) }}</td><td class="right">{{ $m($b['unit_cost']) }}</td><td class="right">{{ $m($b['setup_cost']) }}</td><td class="right">{{ $b['total'] === null ? '–' : $m((float) $b['total'] + (float) $b['setup_cost']) }}</td></tr>
  @endforeach
  @foreach($po->extras ?? [] as $x)
    <tr><td>Option</td><td>{{ $x['name'] }}{{ $x['n'] > 1 ? ' × '.$x['n'] : '' }}</td><td></td><td class="right">{{ $m($x['unit_cost']) }}</td><td class="right">{{ $m($x['setup_cost']) }}</td><td class="right">{{ $m($x['total']) }}</td></tr>
  @endforeach
</table>
<div class="small" style="margin-top: 6px">Client artwork: <a href="{{ $po->artworkUrl() }}" style="color: #D4651F">{{ $po->artworkUrl() }}</a></div>

<table style="margin-top: 12px"><tr>
  <td style="vertical-align: top; padding-right: 20px">
    @if($po->notes)<div class="label orange">Notes</div><div class="soft" style="margin-top: 3px; white-space: pre-line">{{ $po->notes }}</div>@endif
    <div class="label orange" style="margin-top: 8px">Please reply with</div>
    <ol class="soft" style="margin: 3px 0 0; padding-left: 16px">
      <li>Your confirmation and despatch date.</li>
      <li>The freight cost to the address above, unless it's in your pricing.</li>
      <li>The tracking number when it ships.</li>
    </ol>
  </td>
  <td style="width: 260px; vertical-align: top">
    <table class="kv">
      <tr><td>Goods</td><td class="right">{{ $m($po->goods_cost) }}</td></tr>
      <tr><td>Branding</td><td class="right">{{ $m((float) $po->branding_cost + (float) collect($po->extras ?? [])->sum(fn ($x) => (float) ($x['total'] ?? 0))) }}</td></tr>
      <tr><td>Setup</td><td class="right">{{ $m($po->setup_cost) }}</td></tr>
      <tr><td>Freight</td><td class="right">{{ $po->freight_cost === null ? 'to be advised' : $m($po->freight_cost) }}</td></tr>
      <tr><td style="border-top: 1px solid #E4E1DB; padding-top: 4px"><b>Total ex GST</b></td><td class="right" style="border-top: 1px solid #E4E1DB; padding-top: 4px"><b>${{ $m($po->total_cost) }}</b></td></tr>
    </table>
    <div class="small muted" style="margin-top: 4px">Please let us know if any prices have changed.</div>
  </td>
</tr></table>
</body></html>
