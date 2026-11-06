@extends('layouts.app')
@section('title', 'Review & send')
@section('content')
@php($sent = $email->status === 'sent')
<div class="row between">
  <div><div class="eyebrow">{{ $email->subject }}</div><h1>Review &amp; send {{ $job }}</h1></div>
  <a class="btn" href="{{ $back }}">‹ Back</a>
</div>
<p class="muted" style="margin:6px 0 14px;max-width:80ch">The PDF and the email exactly as they go out. Nothing is sent until you press Send.@if($sent) <span class="pill ok">Sent {{ $email->sent_at?->timezone(config('app.timezone'))->format('j M g:ia') }}@if($email->sender) by {{ $email->sender->name }}@endif</span>@endif</p>
@if($email->error)<div class="flash" role="alert" style="background:var(--bad-bg);border-color:var(--bad);color:var(--bad)">Last attempt failed: {{ $email->error }}</div>@endif
<div class="dreview">
  @if($pdf)
  <div class="card">
    <div class="row between"><h2>PDF</h2><a class="btn small" href="{{ $pdf }}" target="_blank" rel="noopener">Open full size</a></div>
    <iframe class="dframe pdf" src="{{ $pdf }}" title="{{ ucfirst($job) }} PDF"></iframe>
  </div>
  @endif
  <div class="card">
    <h2>Email</h2>
    <form method="post" action="{{ route('emails.send', $email) }}">@csrf
      <div class="dmailhead">
        <span>From</span><span>{{ config('mail.from.address') }}</span>
        <label for="recipient">To</label><input id="recipient" name="recipient" type="email" value="{{ old('recipient', $email->recipient) }}" @disabled($sent || ! auth()->user()->canEdit()) required>
        <span>Subject</span><span>{{ $email->subject }}</span>
        @if($pdf)<span>Attached</span><span>{{ ucfirst($job) }} PDF</span>@endif
      </div>
      @error('recipient')<p class="small" style="color:var(--bad)">{{ $message }}</p>@enderror
      <iframe class="dframe mail" src="{{ route('emails.preview', $email) }}" title="Email preview" sandbox=""></iframe>
      @if(! $sent && auth()->user()->canEdit())
        <div class="row between" style="margin-top:12px"><a class="btn" href="{{ $back }}">Not now</a><button class="btn primary big">Send {{ $job }}</button></div>
      @endif
    </form>
  </div>
</div>
@endsection
