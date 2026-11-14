@props(['id', 'icon', 'label', 'open' => false, 'count' => 0])
{{-- Collapsible sidebar group. The group holding the current page always starts open; others remember their state (public/js/app.js). --}}
@if(trim($slot) !== '')
<div @class(['nav-section', 'open' => $open, 'has-active' => $open]) data-nav-group="{{ $id }}">
    <button type="button" class="nav-group-toggle" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="nav-{{ $id }}">
        <span><i class="fas {{ $icon }} fa-fw"></i> {{ $label }}</span>
        <span class="nav-group-meta">@if($count && ! $open)<span class="count">{{ $count }}</span>@endif<i class="fas fa-chevron-down nav-caret"></i></span>
    </button>
    <div class="nav-items" id="nav-{{ $id }}">{{ $slot }}</div>
</div>
@endif
