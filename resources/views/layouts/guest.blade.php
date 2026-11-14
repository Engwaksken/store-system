<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ $companyName }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}?v=6.7.2">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
<div class="auth">
    <div class="card">
        <div class="brand"><i class="fas fa-helmet-safety brand-icon" aria-hidden="true"></i><strong>{{ $companyName }}</strong><small>STORE &amp; PROJECTS</small></div>
        @include('partials.flash')
        @yield('content')
    </div>
</div>
</body>
</html>
