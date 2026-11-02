@extends('layouts.app')
@section('title', 'Curation requests')
@section('content')
<div class="row between">
  <div><div class="eyebrow">Website</div><h1>Curation requests</h1></div>
  <form method="get" action="{{ route('curation-requests.index') }}" class="row">
    <select name="status" aria-label="Status" data-autosubmit>
      <option value="">All</option>
      @foreach(\App\Models\CurationRequest::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
    </select>
    <button class="btn">Show</button>
  </form>
</div>
<p class="muted" style="margin:0;max-width:820px">Range changes asked for from the product page on the website (Staff › Curate). An admin's changes apply and republish straight away; everyone else's wait here. A note on its own is a to-do: mark it done when it's handled.</p>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Product</th><th>Change</th><th>Asked by</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($requests as $r)
      <tr>
        <td>@if($r->product)<a href="{{ route('range.show', $r->product_id) }}"><b>{{ \Illuminate\Support\Str::limit($r->product->name, 40) }}</b></a>@else<b>#{{ $r->product_id }}</b>@endif
          <div class="muted small mono">{{ $r->product_id }}</div></td>
        <td class="small" style="max-width:420px">@foreach($r->summary() as $line)<div>{{ $line }}</div>@endforeach
          @if($r->note)<div class="muted">“{{ $r->note }}”</div>@endif
          @if($r->error)<div><span class="pill bad">{{ \Illuminate\Support\Str::limit($r->error, 120) }}</span></div>@endif</td>
        <td class="small">{{ $r->user?->name ?? '–' }}<div class="muted">{{ $r->created_at->timezone(config('app.timezone'))->format('j M, g:ia') }}</div></td>
        <td><span class="pill {{ $r->status === 'pending' ? 'accent' : ($r->status === 'applied' ? 'ok' : ($r->status === 'failed' ? 'bad' : '')) }}">{{ \App\Models\CurationRequest::STATUSES[$r->status] ?? $r->status }}</span>
          @if($r->decider && $r->status !== 'pending')<div class="muted small">{{ $r->decider->name }}</div>@endif</td>
        <td class="small" style="white-space:nowrap">
          @if(auth()->user()->isAdmin() && in_array($r->status, ['pending', 'failed'], true))
            <form method="post" action="{{ route('curation-requests.decide', $r) }}" style="display:inline">@csrf
              @if($r->edits)<button class="btn primary" name="decision" value="{{ $r->status === 'failed' ? 'retry' : 'approve' }}">{{ $r->status === 'failed' ? 'Try again' : 'Approve' }}</button>@else<button class="btn primary" name="decision" value="done">Done</button>@endif
              <button class="btn" name="decision" value="reject">Reject</button>
            </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted" style="padding:20px 8px">No curation requests yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@include('partials.pager', ['paginator' => $requests])
@endsection
