@extends('layouts.app')
@section('title', $item->name)
@section('content')
<div class="page-head"><div><h1>{{ $item->name }}</h1><p>{{ $item->sku }} · {{ $item->category->name }}</p></div>
    @can('manage-catalog')<a class="btn secondary" data-modal href="{{ route('items.edit', $item) }}"><i class="fas fa-pen"></i> Edit item</a>@endcan</div>
@php($stocked = $item->balances->where('on_hand', '!=', 0))
<x-stats :cards="[
    ['On hand', qty($item->onHand()).' '.$item->unit->abbreviation, 'fa-box', $item->isLowStock() ? 'bad' : ''],
    ['Minimum level', qty($item->min_level), 'fa-arrow-down-short-wide', 'gold'],
    auth()->user()->can('view-stock-value') ? ['Average cost ('.currency().')', ugx($item->avg_cost), 'fa-scale-balanced', 'navy'] : null,
    ['Stores holding stock', number_format($stocked->count()), 'fa-warehouse'],
]" />
<x-tabs id="item-show">
    <x-tab title="By store" icon="fa-warehouse" :count="$stocked->count()" name="stores">
        @if($stocked->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Store</th><th class="num">On hand</th></tr></thead>
            <tbody>@foreach($stocked->sortBy('store_id') as $b)<tr><td><a href="{{ route('stores.show', $b->store_id) }}">{{ $b->store->label() }}</a></td><td class="num">{{ qty($b->on_hand) }}</td></tr>@endforeach</tbody>
        </table></div>
        @else
        <p class="muted">No stock in any store.</p>
        @endif
    </x-tab>
    <x-tab title="Stock ledger" icon="fa-book" name="ledger">
        @if($movements)
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Store</th><th>Type</th><th class="num">Change</th><th class="num">Store balance</th><th class="num">Unit cost</th><th>Project</th><th>Source</th><th>By</th></tr></thead>
            <tbody>@forelse($movements as $m)
                <tr><td class="nowrap">{{ $m->created_at->format('Y-m-d H:i') }}</td><td>{{ $m->store->code }}</td><td>{{ str_replace('_', ' ', $m->movement_type) }}</td><td class="num">{{ $m->qty_delta > 0 ? '+' : '' }}{{ qty($m->qty_delta) }}</td><td class="num">{{ qty($m->balance_after) }}</td>
                    <td class="num">{{ ugx($m->unit_cost) }}</td><td>{{ $m->project?->code }}</td>
                    <td>@switch($m->source_type)
                        @case('material_issue')<a href="{{ route('issues.show', $m->source_id) }}">Voucher</a>@break
                        @case('stock_receipt')<a href="{{ route('receipts.show', $m->source_id) }}">Receipt</a>@break
                        @case('stock_transfer')<a href="{{ route('transfers.show', $m->source_id) }}">Transfer</a>@break
                        @case('stock_return')<a href="{{ route('returns.show', $m->source_id) }}">Return</a>@break
                        @default Adjustment
                    @endswitch</td>
                    <td>{{ $m->actor->name }}</td></tr>
            @empty <tr><td colspan="9" class="empty">No movements yet.</td></tr> @endforelse</tbody>
        </table></div><div class="pagination">{{ $movements->links() }}</div>
        @endif
    </x-tab>
</x-tabs>
@endsection
