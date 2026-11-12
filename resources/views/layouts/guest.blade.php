<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ $companyName }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
<div class="auth">
    <div class="card">
        <div class="brand"><strong>{{ $companyName }}</strong><small>STORE &amp; PROJECTS</small></div>
        @include('partials.flash')
        @yield('content')
    </div>
</div>
</body>
</html>
