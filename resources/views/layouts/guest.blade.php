<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ $companyName }}</title>
    @include('partials.head-assets')
</head>
<body>
<div class="auth">
    <div class="card">
        <div class="brand">@if($logoPath)<img src="{{ asset('storage/'.$logoPath) }}" alt="" class="auth-logo">@else<i class="fas fa-helmet-safety brand-icon" aria-hidden="true"></i>@endif<strong>{{ $companyName }}</strong><small>{{ $appSubtitle }}</small></div>
        @include('partials.flash')
        @yield('content')
    </div>
</div>
</body>
</html>
