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
@php($u = auth()->user())
@php($canDecide = $u->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::OverallManager))
<div class="card table-wrap" data-bulk-scope>
    <x-bulk-bar :action="route('prs.bulk')">
        @if($canDecide)
            <button class="btn small" name="action" value="approve" data-confirm="Approve the selected requisitions? Ones not awaiting approval, or raised by you, are skipped and listed." data-confirm-ok="Yes, approve"><i class="fas fa-check"></i> Approve</button>
            <button type="button" class="btn small danger" data-modal-target="#prs-bulk-reject"><i class="fas fa-ban"></i> Reject</button>
        @endif
        <button class="btn small secondary" name="action" value="cancel" data-confirm="Cancel the selected requisitions? Only drafts, submitted and approved ones can be cancelled, by the requester or procurement." data-confirm-ok="Yes, cancel"><i class="fas fa-xmark"></i> Cancel</button>
    </x-bulk-bar>
    <table class="data-table">
    <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all requisitions on this page"></th><th>PR</th><th>Project</th><th>Deliver to</th><th>Purpose</th><th>Requested by</th><th class="num">Est. total</th><th>Status</th><th class="right">Actions</th></tr></thead>
    <tbody>@forelse($prs as $pr)
        <tr><td class="check-col"><input type="checkbox" value="{{ $pr->id }}" data-row-check aria-label="Select {{ $pr->pr_no }}"></td><td><a href="{{ route('prs.show', $pr) }}"><strong>{{ $pr->pr_no }}</strong></a></td><td>{{ $pr->project?->code ?? 'Stock' }}</td><td>{{ $pr->store->code }}</td><td>{{ \Illuminate\Support\Str::limit($pr->purpose, 50) }}</td>
            <td>{{ $pr->requester->name }}</td><td class="num">{{ ugx($pr->estimated_total) }}</td><td><span class="badge {{ $pr->tone() }}">{{ $pr->statusLabel() }}</span></td>
            <td><div class="row-actions"><a class="btn-icon" href="{{ route('prs.show', $pr) }}" title="Review {{ $pr->pr_no }}" aria-label="Review {{ $pr->pr_no }}"><i class="fas fa-eye"></i></a></div></td></tr>
    @empty <tr><td colspan="9" class="empty">No requisitions{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $prs->links() }}</div>
@if($canDecide)
<template id="prs-bulk-reject" data-title="Reject selected requisitions">
    <form method="POST" action="{{ route('prs.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="reject">
        <div class="field"><label for="prs_bulk_reason">Reason</label><textarea id="prs_bulk_reason" name="reason" required maxlength="1000"></textarea></div>
        <button class="btn danger"><i class="fas fa-ban"></i> Reject selected</button>
    </form>
</template>
@endif
@endsection
