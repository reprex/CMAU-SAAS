@extends('layouts.guest')
@section('title', 'Enter your code')
@section('content')
<h1>Enter your code</h1>
<p class="muted">Open your authenticator app and enter the current 6-digit code for CMAU SaaS.</p>
<form method="post" action="{{ route('two-factor.challenge') }}" style="display:flex;flex-direction:column;gap:12px">
  @csrf
  <label class="f">6-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required autofocus></label>
  <button class="btn primary" type="submit">Continue</button>
</form>
<form method="post" action="{{ route('logout') }}">@csrf<button class="btn link" type="submit">Sign out</button></form>
@endsection
