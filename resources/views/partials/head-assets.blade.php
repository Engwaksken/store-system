{{-- Shared <head> assets for the app and sign-in layouts. --}}
@if($faviconPath ?? null)<link rel="icon" href="{{ asset('storage/'.$faviconPath) }}">@else<link rel="icon" href="{{ asset('favicon.ico') }}">@endif
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}?v=6.7.2">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v=5">
@if($fontCssUrl ?? null)<link rel="stylesheet" href="{{ $fontCssUrl }}">@endif
@if($themeCss ?? '')<style id="theme">{!! $themeCss !!}</style>@endif
<script src="{{ asset('js/app.js') }}?v=5" defer></script>
