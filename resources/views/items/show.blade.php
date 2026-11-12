@extends('layouts.app')
@section('title', $item->name)
@section('content')
<div class="page-head"><div><h1>{{ $item->name }}</h1><p>{{ $item->sku }} · {{ $item->category->name }}</p></div>
    @can('manage-catalog')<a class="btn secondary" href="{{ route('items.edit', $item) }}">Edit item</a>@endcan</div>
<div class="grid grid-3" style="margin-bottom:1.25rem">
    <div @class(['stat', 'bad' => $item->isLowStock()])><div class="label">On hand</div><div class="value">{{ qty($item->onHand()) }} {{ $item->unit->abbreviation }}</div></div>
    <div class="stat gold"><div class="label">Minimum level</div><div class="value">{{ qty($item->min_level) }}</div></div>
    @can('view-stock-value')<div class="stat navy"><div class="label">Average cost (UGX)</div><div class="value">{{ ugx($item->avg_cost) }}</div></div>@endcan
</div>
@if($movements)
<div class="card"><h2>Stock ledger</h2><div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Type</th><th class="num">Change</th><th class="num">Balance</th><th class="num">Unit cost</th><th>Project</th><th>Source</th><th>By</th></tr></thead>
    <tbody>@forelse($movements as $m)
        <tr><td class="nowrap">{{ $m->created_at->format('Y-m-d H:i') }}</td><td>{{ $m->movement_type }}</td><td class="num">{{ $m->qty_delta > 0 ? '+' : '' }}{{ qty($m->qty_delta) }}</td><td class="num">{{ qty($m->balance_after) }}</td>
            <td class="num">{{ ugx($m->unit_cost) }}</td><td>{{ $m->project?->code }}</td>
            <td>@if($m->source_type === 'material_issue')<a href="{{ route('issues.show', $m->source_id) }}">Voucher</a>@elseif($m->source_type === 'stock_receipt')<a href="{{ route('receipts.show', $m->source_id) }}">Receipt</a>@else Adjustment @endif</td>
            <td>{{ $m->actor->name }}</td></tr>
    @empty <tr><td colspan="8" class="empty">No movements yet.</td></tr> @endforelse</tbody>
</table></div><div class="pagination">{{ $movements->links() }}</div></div>
@endif
@endsection
