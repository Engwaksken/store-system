@extends('layouts.app')
@section('title', $item->name)
@section('content')
<div class="page-head"><div><h1>{{ $item->name }}</h1><p>{{ $item->sku }} · {{ $item->category->name }}</p></div>
    @can('manage-catalog')<a class="btn secondary" data-modal href="{{ route('items.edit', $item) }}"><i class="fas fa-pen"></i> Edit item</a>@endcan</div>
<div class="grid grid-3" style="margin-bottom:1.25rem">
    <div @class(['stat', 'bad' => $item->isLowStock()])><span class="stat-icon"><i class="fas fa-box"></i></span><div class="stat-body"><div class="label">On hand</div><div class="value">{{ qty($item->onHand()) }} {{ $item->unit->abbreviation }}</div></div></div>
    <div class="stat gold"><span class="stat-icon"><i class="fas fa-arrow-down-short-wide"></i></span><div class="stat-body"><div class="label">Minimum level</div><div class="value">{{ qty($item->min_level) }}</div></div></div>
    @can('view-stock-value')<div class="stat navy"><span class="stat-icon"><i class="fas fa-scale-balanced"></i></span><div class="stat-body"><div class="label">Average cost (UGX)</div><div class="value">{{ ugx($item->avg_cost) }}</div></div></div>@endcan
</div>
@if($item->balances->where('on_hand', '!=', 0)->isNotEmpty())
<div class="card table-wrap"><h2>By store</h2><table>
    <thead><tr><th>Store</th><th class="num">On hand</th></tr></thead>
    <tbody>@foreach($item->balances->where('on_hand', '!=', 0)->sortBy('store_id') as $b)<tr><td><a href="{{ route('stores.show', $b->store_id) }}">{{ $b->store->label() }}</a></td><td class="num">{{ qty($b->on_hand) }}</td></tr>@endforeach</tbody>
</table></div>
@endif
@if($movements)
<div class="card"><h2>Stock ledger</h2><div class="table-wrap"><table>
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
</table></div><div class="pagination">{{ $movements->links() }}</div></div>
@endif
@endsection
