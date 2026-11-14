@extends('layouts.app')
@section('title', $store->name)
@section('content')
<div class="page-head"><div><h1>{{ $store->name }} <small class="muted">{{ $store->code }}</small></h1>
    <p>{{ \App\Models\Store::TYPES[$store->type] ?? $store->type }}@if($store->project) · {{ $store->project->code }} {{ $store->project->name }}@endif @if($store->location) · {{ $store->location }}@endif
        @unless($store->is_active) · <span class="badge muted">Inactive</span>@endunless</p></div>
    <div class="actions">
        @can('update', $store)<a class="btn secondary" data-modal href="{{ route('stores.edit', $store) }}"><i class="fas fa-pen"></i> Edit</a>@endcan
        @if($canOperate)
            <a class="btn secondary" href="{{ route('receipts.create', ['store_id' => $store->id]) }}">Receive stock</a>
            <a class="btn secondary" href="{{ route('transfers.create', ['from_store_id' => $store->id]) }}">Transfer out</a>
            <a class="btn secondary" href="{{ route('adjustments.create', ['store_id' => $store->id]) }}">Adjustment</a>
        @endif
    </div></div>
<div class="grid grid-3" style="margin-bottom:1.25rem">
    <div class="stat"><span class="stat-icon"><i class="fas fa-boxes-stacked"></i></span><div class="stat-body"><div class="label">Items in stock</div><div class="value">{{ $balances->count() }}</div></div></div>
    @can('view-stock-value')<div class="stat navy"><span class="stat-icon"><i class="fas fa-sack-dollar"></i></span><div class="stat-body"><div class="label">Stock value (UGX, avg cost)</div><div class="value">{{ ugx($value) }}</div></div></div>@endcan
    <div class="stat gold"><span class="stat-icon"><i class="fas fa-user-gear"></i></span><div class="stat-body"><div class="label">Storekeepers</div><div class="value" style="font-size:1rem">{{ $store->users->pluck('name')->implode(', ') ?: '-' }}</div></div></div>
</div>
<div class="card table-wrap"><h2>Balances</h2><table>
    <thead><tr><th>SKU</th><th>Item</th><th class="num">On hand</th><th>Unit</th>@can('view-stock-value')<th class="num">Value (UGX)</th>@endcan</tr></thead>
    <tbody>@forelse($balances as $b)
        <tr><td>{{ $b->item->sku }}</td><td><a href="{{ route('items.show', $b->item) }}">{{ $b->item->name }}</a></td><td class="num">{{ qty($b->on_hand) }}</td><td>{{ $b->item->unit->abbreviation }}</td>
            @can('view-stock-value')<td class="num">{{ ugx(bcmul((string) $b->on_hand, (string) $b->item->avg_cost, 2)) }}</td>@endcan</tr>
    @empty <tr><td colspan="5" class="empty">No stock in this store.</td></tr> @endforelse</tbody>
</table></div>
@endsection
