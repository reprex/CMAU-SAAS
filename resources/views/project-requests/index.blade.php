@extends('layouts.app')
@section('title', 'Project requests')
@section('content')
<div class="row between">
  <div><div class="eyebrow">Website</div><h1>Project requests</h1></div>
  <form method="get" action="{{ route('project-requests.index') }}" class="row" role="search">
    <input type="search" name="q" value="{{ $q }}" placeholder="PR number, name, email or words" aria-label="Search project requests" style="width:260px">
    <select name="status" aria-label="Status" data-autosubmit>
      <option value="">All</option>
      @foreach(\App\Models\ProjectRequest::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
    </select>
    <button class="btn">Show</button>
  </form>
</div>
<p class="muted" style="margin:0;max-width:820px">The website's "Design something" survey. The client gets a confirmation with their request and the reply-by date ({{ config('website.projects.reply_working_days') }} working day, VIC public holidays skipped)@if(! config('website.projects.send_confirmation')) — confirmations are switched off @endif. New requests are listed first, soonest reply-by at the top.</p>

<div class="card" style="padding:6px 12px">
  <table class="list">
    <thead><tr><th>Request</th><th>Client</th><th>What they're after</th><th>Quantity</th><th>Reply by</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($requests as $r)
      @php($late = $r->status === 'new' && $r->reply_by->endOfDay()->isPast())
      <tr>
        <td><a href="{{ route('project-requests.show', $r) }}"><b>{{ $r->number }}</b></a><div class="muted small">{{ $r->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</div></td>
        <td>{{ $r->organisation ?: $r->name() }}<div class="muted small">{{ $r->email }}</div></td>
        <td class="small" style="max-width:380px">{{ \Illuminate\Support\Str::limit($r->brief, 140) }}@if($r->files)<div class="muted">{{ count($r->files) }} file{{ count($r->files) === 1 ? '' : 's' }}</div>@endif</td>
        <td class="small">{{ $r->quantity }}</td>
        <td class="small"><b>{{ $r->reply_by->format('D j M') }}</b>@if($late) <span class="pill bad">overdue</span>@endif</td>
        <td><span class="pill {{ $r->status === 'new' ? 'accent' : ($r->status === 'closed' ? 'ok' : '') }}">{{ \App\Models\ProjectRequest::STATUSES[$r->status] ?? $r->status }}</span>
          @if($r->email_error)<div><span class="pill warn">email failed</span></div>@endif</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted" style="padding:20px 8px">No project requests yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@include('partials.pager', ['paginator' => $requests])
@endsection
