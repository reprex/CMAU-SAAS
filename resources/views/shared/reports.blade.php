@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow">Shared</span>
  <h1>Management reports</h1>
  <p class="muted" style="margin:0">One reporting suite for Replicat, CrowdMerch and CrowdLink. Every report can show one division or all three: one legal entity, so the division is for branding, numbering and reporting. Planned:</p>
</div>
<div class="card" style="padding:6px 12px"><table class="list"><tbody>
  @foreach($reports as [$name, $what])<tr><td style="width:220px"><b>{{ $name }}</b></td><td class="small">{{ $what }}</td><td class="small muted">Planned</td></tr>@endforeach
</tbody></table></div>
@endsection
