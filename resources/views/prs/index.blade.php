@extends('layouts.app')
@section('title', 'Purchase requisitions')
@section('content')
<div class="page-head"><div><h1>Purchase requisitions</h1><p>Requests to buy. Approved by the Owner/Admin or Overall Manager, then quoted and ordered by Procurement.</p></div>
    @can('create-pr')<div class="actions"><a class="btn" data-modal href="{{ route('prs.create') }}"><i class="fas fa-plus"></i> New requisition</a></div>@endcan</div>
<x-stats :cards="[
    ['Requisitions', $stats['total'], 'fa-file-signature', 'navy'],
    ['Awaiting approval', $stats['submitted'], 'fa-hourglass-half', 'gold', route('prs.index', ['status' => 'submitted'])],
    ['Approved', $stats['approved'], 'fa-file-circle-check', '', route('prs.index', ['status' => 'approved'])],
    ['Ordered', $stats['ordered'], 'fa-cart-shopping', '', route('prs.index', ['status' => 'ordered'])],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\PurchaseRequisition::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>PR</th><th>Project</th><th>Deliver to</th><th>Purpose</th><th>Requested by</th><th class="num">Est. total</th><th>Status</th></tr></thead>
    <tbody>@forelse($prs as $pr)
        <tr><td><a href="{{ route('prs.show', $pr) }}">{{ $pr->pr_no }}</a></td><td>{{ $pr->project?->code ?? 'Stock' }}</td><td>{{ $pr->store->code }}</td><td>{{ \Illuminate\Support\Str::limit($pr->purpose, 50) }}</td>
            <td>{{ $pr->requester->name }}</td><td class="num">{{ ugx($pr->estimated_total) }}</td><td><span class="badge {{ $pr->tone() }}">{{ $pr->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No requisitions{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $prs->links() }}</div>
@endsection
