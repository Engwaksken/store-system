@props(['cards' => []])
{{-- Row of statistics cards. Each card: [label, value, icon, tone ('', navy, gold, bad), optional url]. --}}
@php($cards = array_values(array_filter($cards)))
@if(count($cards))
<div @class(['grid', 'stats-row', 'grid-'.min(4, max(2, count($cards)))])>
    @foreach($cards as $c)
        @php([$label, $value, $icon, $tone, $url] = array_pad($c, 5, null))
        @if($url)<a href="{{ $url }}" @class(['stat', $tone])>@else<div @class(['stat', $tone])>@endif
            <span class="stat-icon"><i class="fas {{ $icon }}"></i></span>
            <div class="stat-body"><div class="label">{{ $label }}</div><div class="value">{{ $value }}</div></div>
        @if($url)</a>@else</div>@endif
    @endforeach
</div>
@endif
