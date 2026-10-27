@extends('layouts.guest')
@section('title', 'First admin')
@section('content')
<h1>Create the first admin</h1>
<p class="muted">This page works once, while there are no users. You need the SETUP_TOKEN from the server's .env file.</p>
<form method="post" action="{{ route('setup') }}" style="display:flex;flex-direction:column;gap:12px">
  @csrf
  <label class="f">Setup token<input type="password" name="setup_token" required></label>
  <label class="f">Your name<input name="name" value="{{ old('name') }}" required></label>
  <label class="f">Email<input type="email" name="email" value="{{ old('email') }}" required></label>
  <label class="f">Password (12+ characters)<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
  <label class="f">Password again<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
  <button class="btn primary" type="submit">Create admin</button>
</form>
@endsection
