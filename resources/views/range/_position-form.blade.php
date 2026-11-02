{{-- Positions editor for a product type or one product. $rule may be null; $auto lists the automatic positions to start from. --}}
@php
  $rows = $rule?->positions ?? collect($auto ?? [])->map(fn ($p) => ['name' => $p['name'], 'view' => $p['view'] ?? 'front', 'width_mm' => $p['max_mm'][0] ?? null, 'height_mm' => $p['max_mm'][1] ?? null])->all();
  $rows = array_pad($rows, max(count($rows) + 2, 4), ['name' => '', 'view' => 'front', 'width_mm' => null, 'height_mm' => null]);
@endphp
<form method="post" action="{{ route('range.positions.save') }}" class="card" style="padding:12px;display:flex;flex-direction:column;gap:8px">@csrf
  @isset($productId)<input type="hidden" name="product_id" value="{{ $productId }}">@else<input type="hidden" name="product_type" value="{{ $productType }}">@endisset
  <table class="list small">
    <thead><tr><th>Position</th><th>Picture</th><th class="num">Max width mm</th><th class="num">Max height mm</th></tr></thead>
    <tbody>
    @foreach($rows as $i => $r)
      <tr>
        <td><input name="positions[{{ $i }}][name]" value="{{ $r['name'] }}" list="pos-names" placeholder="e.g. Left chest" style="width:180px"></td>
        <td><select name="positions[{{ $i }}][view]">@foreach(\App\Models\PositionRule::VIEWS as $v)<option value="{{ $v }}" @selected(($r['view'] ?? 'front') === $v)>{{ ucfirst($v) }}</option>@endforeach</select></td>
        <td class="num"><input name="positions[{{ $i }}][width_mm]" value="{{ $r['width_mm'] }}" inputmode="numeric" style="width:80px"></td>
        <td class="num"><input name="positions[{{ $i }}][height_mm]" value="{{ $r['height_mm'] }}" inputmode="numeric" style="width:80px"></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <datalist id="pos-names">@foreach(['Front', 'Back', 'Left chest', 'Right chest', 'Left sleeve', 'Right sleeve', 'Back neck', 'Pocket', 'Wrap around', 'Barrel', 'Lid', 'Side 1', 'Side 2', 'Top'] as $n)<option value="{{ $n }}">@endforeach</datalist>
  <p class="small muted" style="margin:0">Blank rows are ignored. Each position keeps the decoration methods the product offers, capped at the size here. Clear every name and save to go back to the automatic positions.</p>
  @if(auth()->user()->canEdit())<button class="btn primary" style="align-self:flex-start">{{ $rule ? 'Save positions' : 'Save as curated positions' }}</button>@endif
</form>
