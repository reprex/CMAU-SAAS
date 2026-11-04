<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Quotes') · {{ \App\Support\Divisions::name(\App\Support\Divisions::viewing()) }} · Replicat SaaS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body>
<div class="shell">
  @php($me = auth()->user())
  @php($viewing = \App\Support\Divisions::viewing())
  @php($mine = $me->divisionKeys())
  <nav class="nav" aria-label="Main">
    <div class="brand"><b>{{ \App\Support\Divisions::name($viewing) }}</b><span>{{ config("divisions.list.{$viewing}.tagline") }}</span></div>
    @if(count($mine) > 1 || ! in_array($viewing, $mine, true))
      <div class="divs" role="group" aria-label="Division">
        @foreach(\App\Support\Divisions::all() as $key => $d)
          @if(in_array($key, $mine, true))<a href="{{ route('division.switch', $key) }}" class="{{ $viewing === $key ? 'on' : '' }}" aria-current="{{ $viewing === $key ? 'true' : 'false' }}">{{ $d['name'] }}</a>@endif
        @endforeach
      </div>
    @endif
    @if($viewing === 'crowdmerch' && $me->canView('crowdmerch'))
      <a href="{{ route('quotes.index') }}" class="{{ request()->routeIs('quotes.*') ? 'on' : '' }}">Quotes</a>
      <a href="{{ route('website-orders.index') }}" class="{{ request()->routeIs('website-orders.*') ? 'on' : '' }}">Website orders</a>
      <a href="{{ route('project-requests.index') }}" class="{{ request()->routeIs('project-requests.*') ? 'on' : '' }}">Project requests</a>
      <a href="{{ route('curation-requests.index') }}" class="{{ request()->routeIs('curation-requests.*') ? 'on' : '' }}">Curation requests</a>
      <a href="{{ route('drafts.index') }}" class="{{ request()->routeIs('drafts.*') ? 'on' : '' }}">Wish lists &amp; intake</a>
      <a href="{{ route('range.index') }}" class="{{ request()->routeIs('range.*') && ! request()->routeIs('range.logo-areas*') && ! request()->routeIs('range.positions*') && ! request()->routeIs('range.changes*') ? 'on' : '' }}">Range</a>
      @php($vetCount = rescue(fn () => app(\App\Catalogue\VetQueue::class)->count(), 0, false))
      <a href="{{ route('vet.index') }}" class="{{ request()->routeIs('vet.*') || request()->routeIs('range.changes*') || request()->routeIs('range.positions*') || request()->routeIs('range.logo-areas*') ? 'on' : '' }}">Vet @if($vetCount)<span class="pill warn small">{{ number_format($vetCount) }}</span>@endif</a>
      @if($me->isAdmin('crowdmerch'))
        <span class="group">CROWDMERCH ADMIN</span>
        <a href="{{ route('admin.sync.index') }}" class="{{ request()->routeIs('admin.sync.*') ? 'on' : '' }}">Promodata sync</a>
        <a href="{{ route('admin.shopify-publishing.index') }}" class="{{ request()->routeIs('admin.shopify-publishing.*') ? 'on' : '' }}">Shopify publishing</a>
        <a href="{{ route('admin.search-log.index') }}" class="{{ request()->routeIs('admin.search-log.*') ? 'on' : '' }}">Search log</a>
        <a href="{{ route('admin.suppliers.index') }}" class="{{ request()->routeIs('admin.suppliers.*') ? 'on' : '' }}">Suppliers</a>
      @endif
    @elseif($viewing === 'replicat')
      <a href="{{ route('replicat.home') }}" class="{{ request()->routeIs('replicat.*') ? 'on' : '' }}">Replicat home</a>
    @elseif($viewing === 'crowdlink')
      <a href="{{ route('crowdlink.home') }}" class="{{ request()->routeIs('crowdlink.*') ? 'on' : '' }}">CrowdLink home</a>
    @endif
    @if($me->canView('shared'))
      <span class="group">SHARED</span>
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'on' : '' }}">Dashboard</a>
      <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'on' : '' }}">Reports</a>
      <a href="{{ route('contacts.index') }}" class="{{ request()->routeIs('contacts.*') ? 'on' : '' }}">Contacts</a>
      <a href="{{ route('freight.index') }}" class="{{ request()->routeIs('freight.*') ? 'on' : '' }}">Freight</a>
      @if($me->isAdmin('shared'))<a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'on' : '' }}">Users &amp; access</a>@endif
    @endif
    <div class="me">
      {{ $me->name }} · {{ $me->levelLabel() }}<br>
      <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button type="submit">Sign out</button></form>
    </div>
  </nav>
  <main>
    @if(auth()->user()->isAdmin('crowdmerch') && ! request()->routeIs('admin.shopify-publishing.*') && ($pubAlert = rescue(fn () => app(\App\Shopify\PublishHealth::class)->banner(), null, false)))
      <div class="flash" role="alert" style="background:#FBEDEE;border-color:#b3202e;color:#7a1520"><b>Shopify upload:</b> {{ $pubAlert['headline'] }} <a href="{{ route('admin.shopify-publishing.index') }}">See Shopify publishing</a></div>
    @endif
    @if (session('status'))<div class="flash" role="status">{{ session('status') }}@if(session('status_link')) <a href="{{ session('status_link')['url'] }}">{{ session('status_link')['text'] }}</a>@endif</div>@endif
    @if ($errors->any())
      <div class="errors" role="alert"><b>Please check:</b><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
  </main>
</div>
</body>
</html>
