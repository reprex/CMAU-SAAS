@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div><span class="eyebrow">Admin</span><h1>Users &amp; audit</h1></div>
<div class="cols">
  <div class="main">
    <div class="card" style="padding:6px 12px">
      <table class="list">
        <thead><tr><th>Name</th><th>Role</th><th>Two-factor</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $u)
          <tr>
            <td><b>{{ $u->name }}</b><div class="small muted">{{ $u->email }}</div></td>
            <td colspan="1">
              <form method="post" action="{{ route('admin.users.update', $u) }}" class="row" style="gap:6px">@csrf @method('PUT')
                <select name="role" aria-label="Role for {{ $u->name }}" style="width:auto">@foreach(\App\Models\User::ROLES as $r)<option value="{{ $r }}" @selected($u->role === $r)>{{ $r }}</option>@endforeach</select>
                <label class="small row" style="gap:4px"><input type="checkbox" name="active" value="1" @checked($u->active)> active</label>
                <button class="btn small" type="submit">Save</button>
              </form>
            </td>
            <td>@if($u->hasTwoFactor())<span class="pill ok">on</span>@else<span class="pill warn">not set up</span>@endif</td>
            <td class="small">{{ $u->last_login_at?->timezone(config('app.timezone'))->format('j M Y, g:ia') ?? 'never' }}</td>
            <td>@if($u->hasTwoFactor())<form method="post" action="{{ route('admin.users.reset-2fa', $u) }}" data-confirm="Make {{ $u->name }} set up two-factor again?">@csrf<button class="btn link small" type="submit">Reset two-factor</button></form>@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>Recent changes</h2>
      <table class="list">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>On</th></tr></thead>
        <tbody>
        @foreach($audit as $a)
          <tr><td class="small">{{ $a->created_at?->timezone(config('app.timezone'))->format('j M, g:ia') }}</td><td class="small">{{ $a->user?->name ?? 'system' }}</td><td class="small mono">{{ $a->action }}</td><td class="small">{{ $a->subject_type }} {{ $a->subject_id }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  <form method="post" action="{{ route('admin.users.store') }}" class="card side">
    @csrf
    <h2>Add a user</h2>
    <label class="f">Name<input name="name" required></label>
    <label class="f">Email<input type="email" name="email" required></label>
    <label class="f">Role<select name="role"><option value="sales">sales</option><option value="readonly">readonly</option><option value="admin">admin</option></select></label>
    <label class="f">Temporary password (12+)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
    <button class="btn primary" type="submit">Add user</button>
    <p class="small muted" style="margin:0">They set up two-factor sign-in the first time they log in.</p>
  </form>
</div>
@endsection
