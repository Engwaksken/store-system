@extends('layouts.app')
@section('title', $store->name)
@section('content')
<div class="page-head"><div><h1>{{ $store->name }} <small class="muted">{{ $store->code }}</small></h1>
    <p>{{ \App\Models\Store::TYPES[$store->type] ?? $store->type }}@if($store->project) · {{ $store->project->code }} {{ $store->project->name }}@endif @if($store->location) · {{ $store->location }}@endif
        @unless($store->is_active) · <span class="badge muted">Inactive</span>@endunless</p></div>
    <div class="actions">
        @can('update', $store)<a class="btn secondary" data-modal href="{{ route('stores.edit', $store) }}"><i class="fas fa-pen"></i> Edit</a>@endcan
        @if($canOperate)
            <a class="btn secondary" data-modal href="{{ route('receipts.create', ['store_id' => $store->id]) }}">Receive stock</a>
            <a class="btn secondary" data-modal href="{{ route('transfers.create', ['from_store_id' => $store->id]) }}">Transfer out</a>
            <a class="btn secondary" data-modal href="{{ route('adjustments.create', ['store_id' => $store->id]) }}">Adjustment</a>
        @endif
    </div></div>
<x-stats :cards="[
    ['Items in stock', number_format($balances->count()), 'fa-boxes-stacked'],
    auth()->user()->can('view-stock-value') ? ['Stock value ('.currency().', avg cost)', ugx($value), 'fa-sack-dollar', 'navy'] : null,
    ['Storekeepers', number_format($store->users->count()), 'fa-user-gear', 'gold'],
    ['Project', $store->project?->code ?? '-', 'fa-person-digging'],
]" />
<x-tabs id="store-show">
    <x-tab title="Balances" icon="fa-boxes-stacked" :count="$balances->count()" name="balances">
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>Item</th><th class="num">On hand</th><th>Unit</th>@can('view-stock-value')<th class="num">Value ({{ currency() }})</th>@endcan</tr></thead>
            <tbody>@forelse($balances as $b)
                <tr><td>{{ $b->item->sku }}</td><td><a href="{{ route('items.show', $b->item) }}">{{ $b->item->name }}</a></td><td class="num">{{ qty($b->on_hand) }}</td><td>{{ $b->item->unit->abbreviation }}</td>
                    @can('view-stock-value')<td class="num">{{ ugx(bcmul((string) $b->on_hand, (string) $b->item->avg_cost, 2)) }}</td>@endcan</tr>
            @empty <tr><td colspan="5" class="empty">No stock in this store.</td></tr> @endforelse</tbody>
        </table></div>
    </x-tab>
    <x-tab title="Storekeepers" icon="fa-user-gear" :count="$store->users->count()" name="storekeepers">
        @forelse($store->users as $u)<div>{{ $u->name }}</div>@empty<p class="muted">No storekeepers assigned.</p>@endforelse
    </x-tab>
</x-tabs>
@endsection
