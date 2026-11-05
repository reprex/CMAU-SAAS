@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<h1>Sign in</h1>
@if(\App\Auth\DeviceTrust::isMobile(request()))
<div data-passkey-wrap hidden style="margin-bottom:16px">
  <button class="btn primary" type="button" data-passkey="signin" data-options="{{ route('passkey.login.options') }}" data-url="{{ route('passkey.login') }}" data-msg="#pk-msg">Sign in with Face ID</button>
  <p id="pk-msg" class="small" style="color:#b42318;margin:0" hidden></p>
  <p class="small muted" style="margin:0">No passkey on this phone yet? Sign in below, then add one under Sign-in &amp; security.</p>
</div>
<script src="{{ asset('js/passkey.js') }}?v={{ filemtime(public_path('js/passkey.js')) }}" defer></script>
@endif
<form method="post" action="{{ url('/login') }}" style="display:flex;flex-direction:column;gap:12px">
  @csrf
  <label class="f">Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
  <label class="f">Password<input type="password" name="password" required autocomplete="current-password"></label>
  <label class="row small"><input type="checkbox" name="remember" value="1"> Keep me signed in on this computer</label>
  <button class="btn primary" type="submit">Sign in</button>
</form>
@endsection
