@extends('layouts.app')
@section('title', 'Transfers')
@section('content')
<div class="page-head"><div><h1>Stock transfers</h1><p>Stock leaves the source on dispatch and enters the destination only when a storekeeper there confirms receipt.</p></div>
    @can('create', App\Models\StockTransfer::class)<div class="actions"><a class="btn" data-modal href="{{ route('transfers.create') }}"><i class="fas fa-plus"></i> New transfer</a></div>@endcan</div>
<x-stats :cards="[
    ['Transfers', $stats['total'], 'fa-right-left', 'navy'],
    ['In transit', $stats['in_transit'], 'fa-truck-fast', 'gold', route('transfers.index', ['status' => 'in_transit'])],
    ['Received', $stats['received'], 'fa-circle-check', '', route('transfers.index', ['status' => 'received'])],
    ['Cancelled', $stats['cancelled'], 'fa-ban', '', route('transfers.index', ['status' => 'cancelled'])],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\StockTransfer::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>Transfer</th><th>From</th><th>To</th><th>Dispatched</th><th>By</th><th class="num">Lines</th><th>Status</th></tr></thead>
    <tbody>@forelse($transfers as $t)
        <tr><td><a href="{{ route('transfers.show', $t) }}">{{ $t->transfer_no }}</a></td><td>{{ $t->fromStore->code }}</td><td>{{ $t->toStore->code }}</td>
            <td class="nowrap">{{ $t->dispatched_at->format('d M Y H:i') }}</td><td>{{ $t->dispatcher->name }}</td><td class="num">{{ $t->lines_count }}</td>
            <td><span class="badge {{ $t->tone() }}">{{ $t->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No transfers{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $transfers->links() }}</div>
@endsection
