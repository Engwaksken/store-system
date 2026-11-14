@props(['title', 'icon' => null, 'count' => null, 'name' => null])
@if(trim($slot) !== '')
<section {{ $attributes->class(['tab-panel']) }} data-tab="{{ $name ?? \Illuminate\Support\Str::slug($title) }}" data-tab-title="{{ $title }}" data-tab-icon="{{ $icon }}" @if($count !== null && $count !== '') data-tab-count="{{ $count }}" @endif>
    <h2 class="tab-heading">@if($icon)<i class="fas {{ $icon }}"></i> @endif{{ $title }}</h2>
    {{ $slot }}
</section>
@endif
