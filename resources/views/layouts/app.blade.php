<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Quotes') · CMAU SaaS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body>
<div class="shell">
  <nav class="nav" aria-label="Main">
    <div class="brand"><b>CrowdMerch</b><span>Operations</span></div>
    <a href="{{ route('quotes.index') }}" class="{{ request()->routeIs('quotes.*') ? 'on' : '' }}">Quotes</a>
    <a href="{{ route('drafts.index') }}" class="{{ request()->routeIs('drafts.*') ? 'on' : '' }}">Wish lists &amp; intake</a>
    <a href="{{ route('range.index') }}" class="{{ request()->routeIs('range.*') ? 'on' : '' }}">Range</a>
    @if(auth()->user()->isAdmin())
      <span class="group">ADMIN ONLY</span>
      <a href="{{ route('admin.sync.index') }}" class="{{ request()->routeIs('admin.sync.*') ? 'on' : '' }}">Promodata sync</a>
      <a href="{{ route('admin.dectypes.index') }}" class="{{ request()->routeIs('admin.dectypes.*') ? 'on' : '' }}">Decoration types</a>
      <a href="{{ route('admin.price-check.index') }}" class="{{ request()->routeIs('admin.price-check.*') ? 'on' : '' }}">Price check</a>
      <a href="{{ route('admin.suppliers.index') }}" class="{{ request()->routeIs('admin.suppliers.*') ? 'on' : '' }}">Suppliers</a>
      <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'on' : '' }}">Users &amp; audit</a>
    @endif
    <div class="me">
      {{ auth()->user()->name }} · {{ auth()->user()->role }}<br>
      <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button type="submit">Sign out</button></form>
    </div>
  </nav>
  <main>
    @if (session('status'))<div class="flash" role="status">{{ session('status') }}@if(session('status_link')) <a href="{{ session('status_link')['url'] }}">{{ session('status_link')['text'] }}</a>@endif</div>@endif
    @if ($errors->any())
      <div class="errors" role="alert"><b>Please check:</b><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
  </main>
</div>
</body>
</html>
