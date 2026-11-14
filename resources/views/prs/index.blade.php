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
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach(\App\Models\PurchaseRequisition::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>PR</th><th>Project</th><th>Deliver to</th><th>Purpose</th><th>Requested by</th><th class="num">Est. total</th><th>Status</th></tr></thead>
    <tbody>@forelse($prs as $pr)
        <tr><td><a href="{{ route('prs.show', $pr) }}">{{ $pr->pr_no }}</a></td><td>{{ $pr->project?->code ?? 'Stock' }}</td><td>{{ $pr->store->code }}</td><td>{{ \Illuminate\Support\Str::limit($pr->purpose, 50) }}</td>
            <td>{{ $pr->requester->name }}</td><td class="num">{{ ugx($pr->estimated_total) }}</td><td><span class="badge {{ $pr->tone() }}">{{ $pr->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No requisitions.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $prs->links() }}</div>
@endsection
