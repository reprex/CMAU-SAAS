@extends('layouts.app')
@section('title', $r->number)
@section('content')
<div class="row between">
  <div><div class="eyebrow">Project requests · {{ $r->created_at->timezone(config('app.timezone'))->format('j M Y, g:ia') }}</div>
    <h1>{{ $r->number }} <span class="pill {{ $r->status === 'new' ? 'accent' : ($r->status === 'closed' ? 'ok' : '') }}" style="vertical-align:middle">{{ \App\Models\ProjectRequest::STATUSES[$r->status] ?? $r->status }}</span></h1></div>
  <a class="btn" href="mailto:{{ $r->email }}?subject={{ rawurlencode('Your project request '.$r->number) }}">Email {{ $r->first_name }}</a>
</div>

<div class="cols">
  <div class="main">
    <div class="card">
      <h2>What they're after</h2>
      <p style="white-space:pre-wrap;margin:0">{{ $r->brief }}</p>
      <table class="list" style="margin-top:12px">
        @if($r->kinds)<tr><th>Kind of merch</th><td>{{ implode(', ', $r->kindLabels()) }}</td></tr>@endif
        <tr><th>Quantity</th><td>{{ $r->quantity }}</td></tr>
        @if($r->budget)<tr><th>Budget</th><td>{{ $r->budget }}</td></tr>@endif
        @if($r->need_by)<tr><th>Needed by</th><td>{{ $r->need_by->format('l j F Y') }}</td></tr>@endif
        @if($r->event)<tr><th>Event</th><td>{{ $r->event }}</td></tr>@endif
      </table>
    </div>
    @if($r->files)
    <div class="card">
      <h2>Files</h2>
      <ul style="margin:0;padding-left:18px">
        @foreach($r->files as $i => $f)<li><a href="{{ route('project-requests.file', [$r, $i]) }}">{{ $f['name'] }}</a> <span class="muted small">{{ number_format($f['size'] / 1024 / 1024, 1) }} MB</span></li>@endforeach
      </ul>
    </div>
    @endif
  </div>
  <div class="side">
    <div class="card">
      <h2>Client</h2>
      <p style="margin:0"><b>{{ $r->name() }}</b>@if($r->organisation)<br>{{ $r->organisation }}@endif<br><a href="mailto:{{ $r->email }}">{{ $r->email }}</a><br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $r->phone) }}">{{ $r->phone }}</a>
        @if($r->contact_by)<br><span class="muted small">Prefers {{ $r->contact_by }}</span>@endif</p>
    </div>
    <div class="card">
      <h2>Reply by {{ $r->reply_by->format('D j M') }}</h2>
      <p class="small muted" style="margin:0">Confirmation to the client: {{ $r->confirmation_sent_at ? 'sent '.$r->confirmation_sent_at->timezone(config('app.timezone'))->format('j M, g:ia') : 'not sent' }}@if($r->email_error)<br><span class="pill warn">{{ $r->email_error }}</span>@endif</p>
      @if(auth()->user()->canEdit())
      <form method="post" action="{{ route('project-requests.update', $r) }}" style="margin-top:10px">
        @csrf @method('PUT')
        <label for="st">Status</label>
        <select id="st" name="status">@foreach(\App\Models\ProjectRequest::STATUSES as $k => $label)<option value="{{ $k }}" @selected($r->status === $k)>{{ $label }}</option>@endforeach</select>
        <label for="sn" style="margin-top:8px">Notes</label>
        <textarea id="sn" name="staff_notes" rows="5">{{ $r->staff_notes }}</textarea>
        <button class="btn primary" style="margin-top:8px">Save</button>
      </form>
      @endif
    </div>
  </div>
</div>
@endsection
