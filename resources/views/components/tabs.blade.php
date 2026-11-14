@props(['id'])
{{-- Tabbed body section. Each <x-tab> inside becomes one tab; public/js/app.js builds the tab bar and remembers the last tab.
     Without JavaScript every panel is shown in turn under its own heading. --}}
<div {{ $attributes->class(['tabs']) }} data-tabs="{{ $id }}">{{ $slot }}</div>
