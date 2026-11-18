{{-- Shared <head> assets for the app and sign-in layouts. --}}
@if($faviconPath ?? null)<link rel="icon" href="{{ asset('storage/'.$faviconPath) }}">@else<link rel="icon" href="{{ asset('favicon.ico') }}">@endif
@php
    $pwaV = rescue(fn () => \App\Support\PwaIcon::version(), '1', false);
    $pwaColor = rescue(fn () => \App\Support\Theme::values()['theme_primary'], '#12243E', false);
@endphp
{{-- Installable web app (PWA): Android/Chrome read the manifest; iPhone/iPad read the apple-* tags. --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ $pwaColor }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit($companyName ?? config('app.name'), 12, '') }}">
<meta name="application-name" content="{{ $companyName ?? config('app.name') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ route('pwa.apple', ['v' => $pwaV]) }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}?v=6.7.2">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v=7">
@if($fontCssUrl ?? null)<link rel="stylesheet" href="{{ $fontCssUrl }}">@endif
@if($themeCss ?? '')<style id="theme">{!! $themeCss !!}</style>@endif
<script src="{{ asset('js/app.js') }}?v=7" defer></script>
