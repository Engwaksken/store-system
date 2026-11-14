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
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\PurchaseOrder::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label for="f_supplier">Supplier</label><select id="f_supplier" name="supplier_id"><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
</x-list-filters>
@php($u = auth()->user())
@php($canDecide = $u->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::OverallManager))
<div class="card table-wrap" data-bulk-scope>
    <x-bulk-bar :action="route('pos.bulk')">
        @if($canDecide)
            <button class="btn small" name="action" value="approve" data-confirm="Approve the selected purchase orders? Orders not awaiting approval, raised by you, or above your approval limit are skipped and listed." data-confirm-ok="Yes, approve"><i class="fas fa-check"></i> Approve</button>
            <button type="button" class="btn small danger" data-modal-target="#pos-bulk-reason"><i class="fas fa-ban"></i> Reject</button>
        @endif
        @can('buy')<button type="button" class="btn small secondary" data-modal-target="#pos-bulk-cancel"><i class="fas fa-xmark"></i> Cancel</button>@endcan
    </x-bulk-bar>
    <table class="data-table">
    <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all purchase orders on this page"></th><th>PO</th><th>Date</th><th>Supplier</th><th>Project</th><th>Deliver to</th><th class="num">Total ({{ currency() }})</th><th>Status</th><th class="right">Actions</th></tr></thead>
    <tbody>@forelse($orders as $o)
        <tr><td class="check-col"><input type="checkbox" value="{{ $o->id }}" data-version="{{ $o->lock_version }}" data-row-check aria-label="Select {{ $o->po_no }}"></td><td><a href="{{ route('pos.show', $o) }}"><strong>{{ $o->po_no }}</strong></a></td><td class="nowrap">{{ $o->order_date->format('d M Y') }}</td><td>{{ $o->supplier->name }}</td><td>{{ $o->project?->code }}</td><td>{{ $o->store->code }}</td>
            <td class="num">{{ ugx($o->total) }}</td><td><span class="badge {{ $o->tone() }}">{{ $o->statusLabel() }}</span></td>
            <td><div class="row-actions"><a class="btn-icon" href="{{ route('pos.show', $o) }}" title="Review {{ $o->po_no }}" aria-label="Review {{ $o->po_no }}"><i class="fas fa-eye"></i></a></div></td></tr>
    @empty <tr><td colspan="9" class="empty">No purchase orders{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $orders->links() }}</div>
@if($canDecide)
<template id="pos-bulk-reason" data-title="Reject selected purchase orders">
    <form method="POST" action="{{ route('pos.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="reject">
        <div class="field"><label for="pos_bulk_reason">Reason</label><textarea id="pos_bulk_reason" name="reason" required maxlength="1000"></textarea></div>
        <button class="btn danger"><i class="fas fa-ban"></i> Reject selected</button>
    </form>
</template>
@endif
@can('buy')
<template id="pos-bulk-cancel" data-title="Cancel selected purchase orders">
    <form method="POST" action="{{ route('pos.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="cancel">
        <p class="muted" style="margin-top:0">Orders that can no longer be cancelled (e.g. goods already received) are skipped and listed.</p>
        <div class="field"><label for="pos_bulk_cancel_reason">Reason</label><textarea id="pos_bulk_cancel_reason" name="reason" required minlength="5" maxlength="1000"></textarea></div>
        <button class="btn danger"><i class="fas fa-xmark"></i> Cancel selected</button>
    </form>
</template>
@endcan
@endsection
