@extends('layouts.app')
@section('title', 'Stock receipts')
@section('content')
<div class="page-head"><h1>Stock receipts</h1>
    @can('manage-stock')<div class="actions"><a class="btn" data-modal href="{{ route('receipts.create') }}"><i class="fas fa-plus"></i> Purchase receipt</a><a class="btn secondary" data-modal href="{{ route('receipts.create', ['type' => 'opening']) }}"><i class="fas fa-door-open"></i> Opening balances</a></div>@endcan</div>
<x-stats :cards="[
    ['Receipts', $stats['total'], 'fa-inbox', 'navy'],
    ['Purchases', $stats['purchase'], 'fa-cart-arrow-down'],
    ['Opening balances', $stats['opening'], 'fa-door-open', 'gold'],
    ['Received this month ('.currency().')', ugx($stats['month_value']), 'fa-sack-dollar'],
]" />
<div class="card table-wrap"><table>
    <thead><tr><th>Receipt</th><th>Type</th><th>Store</th><th>Date</th><th>Supplier</th><th>Reference</th><th class="num">Lines</th><th class="num">Value ({{ currency() }})</th><th>By</th></tr></thead>
    <tbody>@forelse($receipts as $r)
        <tr><td><a href="{{ route('receipts.show', $r) }}">{{ $r->receipt_no }}</a></td><td>{{ $r->type }}</td><td>{{ $r->store->code }}</td><td>{{ $r->received_on->format('d M Y') }}</td><td>{{ $r->supplier_name }}</td><td>{{ $r->reference }}</td><td class="num">{{ $r->lines_count }}</td><td class="num">{{ ugx($r->total_value) }}</td><td>{{ $r->receiver->name }}</td></tr>
    @empty <tr><td colspan="9" class="empty">No receipts yet.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $receipts->links() }}</div>
@endsection
