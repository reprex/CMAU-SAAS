@if(! $result['ok'])
  <p class="small muted">{{ $result['message'] }}</p>
@else
  <p class="small muted" style="margin:0 0 6px">Live from the supplier at {{ now()->timezone(config('app.timezone'))->format('g:ia') }}. Hover a figure for its SKU.</p>
  <div style="overflow-x:auto">
  <table class="list small">
    <thead><tr><th>Colour</th>@foreach($sizes as $z)<th class="num">{{ $z }}</th>@endforeach<th class="num">Total</th></tr></thead>
    <tbody>
    @foreach($grid as $colour => $bySize)
      <tr>
        <td>{{ $colour }}</td>
        @foreach($sizes as $z)
          @php($r = $bySize[$z] ?? null)
          <td class="num" @if($r) title="{{ $r['sku'] }}" @endif>@if($r){{ $r['qty'] === null ? '?' : number_format($r['qty']) }}<br><span class="mono muted" style="font-size:11px">{{ $r['sku'] }}</span>@else – @endif</td>
        @endforeach
        <td class="num"><b>{{ number_format(collect($bySize)->sum('qty')) }}</b></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
@endif
