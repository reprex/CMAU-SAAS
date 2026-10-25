@extends('layouts.guest')
@section('title', 'Set up two-factor sign-in')
@section('content')
<h1>Set up two-factor sign-in</h1>
<p class="muted">Every account needs a code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…). Scan this, then enter the 6-digit code it shows.</p>
<img src="{{ $qr }}" alt="QR code for your authenticator app" width="200" height="200" style="align-self:center;background:#fff;padding:8px;border-radius:8px">
<details><summary>Can't scan? Enter this key instead</summary><code class="mono" style="word-break:break-all">{{ $secret }}</code></details>
<form method="post" action="{{ route('two-factor.setup') }}" style="display:flex;flex-direction:column;gap:12px">
  @csrf
  <label class="f">6-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required autofocus></label>
  <button class="btn primary" type="submit">Turn on and continue</button>
</form>
@endsection
