@extends('layouts.app')
@section('title', 'Sign-in & security')
@section('content')
<h1>Sign-in &amp; security</h1>
@if(session('status'))<p class="card small" style="padding:8px 12px">{{ session('status') }}</p>@endif

<section class="card" style="display:flex;flex-direction:column;gap:8px">
  <h2 style="margin:0">Passkey on your phone</h2>
  <p class="small muted" style="margin:0">Sign in on your phone with Face ID (or the phone's passcode): no password, no code. The passkey stays on the phone; the SaaS keeps only a public key. On a computer, sign in with your password and code as usual.</p>
  @forelse($passkeys as $k)
    <div class="row between small"><span>{{ $k->name ?: 'Passkey' }} · added {{ $k->created_at->format('j M Y') }}@if($k->last_used_at) · last used {{ $k->last_used_at->diffForHumans() }}@endif</span>
      <form method="post" action="{{ route('passkeys.destroy', $k) }}">@csrf @method('DELETE')<button class="btn small link">Remove</button></form></div>
  @empty
    <p class="small" style="margin:0">No passkey yet.</p>
  @endforelse
  @if($mobile)
    <div data-passkey-wrap hidden><button class="btn primary" type="button" data-passkey="register" data-options="{{ route('passkeys.options') }}" data-url="{{ route('passkeys.store') }}" data-msg="#pk-msg">Add a passkey on this phone</button></div>
    <p id="pk-msg" class="small" style="color:#b42318;margin:0" hidden></p>
    <script src="{{ asset('js/passkey.js') }}?v={{ filemtime(public_path('js/passkey.js')) }}" defer></script>
  @else
    <p class="small muted" style="margin:0">Open this page on your phone to add a passkey there.</p>
  @endif
</section>

<section class="card" style="display:flex;flex-direction:column;gap:8px;margin-top:12px">
  <h2 style="margin:0">Trusted devices</h2>
  <p class="small muted" style="margin:0">Browsers where you ticked "Trust this device for 30 days": they ask for your password but not the code until then.</p>
  @forelse($devices as $d)
    <div class="row between small"><span>{{ $d->name }} · trusted until {{ $d->expires_at->format('j M Y') }}@if($d->last_used_at) · last used {{ $d->last_used_at->diffForHumans() }}@endif</span>
      <form method="post" action="{{ route('devices.destroy', $d) }}">@csrf @method('DELETE')<button class="btn small link">Stop trusting</button></form></div>
  @empty
    <p class="small" style="margin:0">None.</p>
  @endforelse
</section>
@endsection
