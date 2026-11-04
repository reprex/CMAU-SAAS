@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div style="display:flex;flex-direction:column;gap:6px;max-width:860px">
  <span class="eyebrow">Shared</span>
  <h1>Business dashboard</h1>
  <p class="muted" style="margin:0">The divisions you can open, side by side. Each division's figures appear here as its module moves into the SaaS.</p>
</div>
<div class="row" style="gap:12px;align-items:stretch;flex-wrap:wrap">
  @foreach($cards as $d => $c)
    <section class="card" style="flex:1 1 260px">
      <h2 style="margin:0 0 8px">{{ \App\Support\Divisions::name($d) }}</h2>
      @if($c['ready'])
        <table class="list small"><tbody>
          @foreach($c['rows'] as [$label, $value])<tr><td>{{ $label }}</td><td class="num"><b>{{ is_int($value) ? number_format($value) : $value }}</b></td></tr>@endforeach
        </tbody></table>
      @else
        <p class="small muted" style="margin:0">Figures appear when {{ \App\Support\Divisions::name($d) }} moves into the SaaS.</p>
      @endif
    </section>
  @endforeach
</div>
<p class="small muted"><a href="{{ route('reports.index') }}">Management reports</a></p>
@endsection
