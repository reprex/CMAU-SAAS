<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · CMAU SaaS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="guest">
  <div class="card">
    <div class="eyebrow">CrowdMerch Operations</div>
    @if ($errors->any())<div class="errors" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('content')
  </div>
</div>
</body>
</html>
