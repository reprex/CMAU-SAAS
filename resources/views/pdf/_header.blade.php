{{-- Logo, document number, the client and the title. Needs $kicker, $number, $dateLine, $title, $client. --}}
<table>
  <tr>
    <td>@if($logo)<img src="{{ $logo }}" alt="CrowdMerch" style="height: 46px">@else<span class="big">CrowdMerch</span>@endif</td>
    <td class="right" style="width: 260px; padding-top: 4px"><b>{{ $number }}</b><br><span class="muted">{{ $dateLine }}</span></td>
  </tr>
</table>
<div class="kick" style="margin-top: 16px">{{ $kicker }}</div>
<div class="client">{{ $client }}</div>
<div class="big">{{ $title }}</div>
