@extends('layouts.app')
@section('title', 'Purchase orders')
@section('content')
<div class="page-head"><div><h1>Purchase orders</h1></div>
    @can('buy')<div class="actions"><a class="btn" data-modal href="{{ route('pos.create') }}"><i class="fas fa-plus"></i> New purchase order</a></div>@endcan</div>
<x-stats :cards="[
    ['Purchase orders', $stats['total'], 'fa-file-invoice', 'navy'],
    ['Awaiting approval', $stats['pending'], 'fa-hourglass-half', 'gold', route('pos.index', ['status' => 'pending_approval'])],
    ['Open (not fully received)', $stats['open'], 'fa-truck'],
    ['Open value ('.currency().')', ugx($stats['open_value']), 'fa-sack-dollar'],
]" />
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach(\App\Models\PurchaseOrder::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label for="supplier_id">Supplier</label><select id="supplier_id" name="supplier_id"><option value="">All</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>PO</th><th>Date</th><th>Supplier</th><th>Project</th><th>Deliver to</th><th class="num">Total ({{ currency() }})</th><th>Status</th></tr></thead>
    <tbody>@forelse($orders as $o)
        <tr><td><a href="{{ route('pos.show', $o) }}">{{ $o->po_no }}</a></td><td class="nowrap">{{ $o->order_date->format('d M Y') }}</td><td>{{ $o->supplier->name }}</td><td>{{ $o->project?->code }}</td><td>{{ $o->store->code }}</td>
            <td class="num">{{ ugx($o->total) }}</td><td><span class="badge {{ $o->tone() }}">{{ $o->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No purchase orders.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $orders->links() }}</div>
@endsection
