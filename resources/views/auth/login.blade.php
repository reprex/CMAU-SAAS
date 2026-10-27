@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<h1>Sign in</h1>
<form method="post" action="{{ url('/login') }}" style="display:flex;flex-direction:column;gap:12px">
  @csrf
  <label class="f">Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
  <label class="f">Password<input type="password" name="password" required autocomplete="current-password"></label>
  <label class="row small"><input type="checkbox" name="remember" value="1"> Keep me signed in on this computer</label>
  <button class="btn primary" type="submit">Sign in</button>
</form>
@endsection
