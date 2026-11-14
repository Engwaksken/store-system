@extends('layouts.app')
@section('title', 'Expense claims')
@section('content')
<div class="page-head"><div><h1>Expense claims</h1><p>Site costs paid by staff. The project manager approves; the accountant pays, which records the expense once.</p></div>
    @can('submit-claim')<div class="actions"><a class="btn" data-modal href="{{ route('claims.create') }}"><i class="fas fa-plus"></i> New claim</a></div>@endcan</div>
<x-stats :cards="[
    ['Awaiting approval', $stats['submitted'], 'fa-hourglass-half', 'gold', route('claims.index', ['status' => 'submitted'])],
    ['Approved, unpaid', $stats['approved'], 'fa-file-circle-check', 'navy', route('claims.index', ['status' => 'approved'])],
    ['Unpaid value ('.currency().')', ugx($stats['unpaid_value']), 'fa-money-bill-wave', 'bad'],
    ['Paid', $stats['paid'], 'fa-circle-check', '', route('claims.index', ['status' => 'paid'])],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\ExpenseClaim::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
</x-list-filters>

@php
    $u = auth()->user();
    $canPayAny = $u->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Accountant);
    $canDecideAny = $u->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::OverallManager, \App\Enums\Role::ProjectManager);
@endphp
<div class="card table-wrap" data-bulk-scope>
    {{-- Bulk actions: shown when at least one row is ticked. Each claim is re-checked on the server. --}}
    <form id="claims-bulk" method="POST" action="{{ route('claims.bulk') }}" class="bulk-bar" data-bulk-bar data-bulk-form hidden>@csrf
        <span class="bulk-count"><i class="fas fa-square-check"></i> <strong data-bulk-count>0</strong> selected</span>
        <span class="actions">
            @if($canDecideAny)
                <button class="btn small" name="action" value="approve" data-confirm="Approve the selected claims? Claims you cannot approve are skipped and listed." data-confirm-ok="Yes, approve"><i class="fas fa-check"></i> Approve</button>
                <button type="button" class="btn small danger" data-modal-target="#bulk-reject"><i class="fas fa-ban"></i> Reject</button>
            @endif
            @if($canPayAny)<button type="button" class="btn small gold" data-modal-target="#bulk-pay"><i class="fas fa-money-check-dollar"></i> Mark paid</button>@endif
            <button class="btn small secondary" name="action" value="delete" data-confirm="Delete the selected claims? Only claims awaiting approval or rejected can be deleted; the rest are skipped. Deleted claims stay in the activity log." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button>
            <a class="btn small secondary" href="{{ $list->exportUrl('csv') }}" data-bulk-export><i class="fas fa-file-csv"></i> CSV</a>
            <a class="btn small secondary" href="{{ $list->exportUrl('pdf') }}" data-bulk-export><i class="fas fa-file-pdf"></i> PDF</a>
            <button type="button" class="btn small link" data-bulk-clear>Clear</button>
        </span>
    </form>
    <table class="data-table">
        <thead><tr>
            <th class="check-col"><input type="checkbox" data-check-all aria-label="Select all claims on this page"></th>
            <th>Claim</th><th>Date</th><th>Project</th><th>Claimant</th><th>Category</th><th>Description</th><th class="num">Amount ({{ currency() }})</th><th>Status</th><th class="right">Actions</th>
        </tr></thead>
        <tbody>@forelse($claims as $c)
            <tr>
                <td class="check-col"><input type="checkbox" value="{{ $c->id }}" data-row-check aria-label="Select {{ $c->claim_no }}"></td>
                <td class="nowrap"><strong>{{ $c->claim_no }}</strong>@if($c->receipt_path) <i class="fas fa-paperclip muted" title="Receipt attached"></i>@endif</td>
                <td class="nowrap">{{ $c->incurred_on->format('d M Y') }}</td>
                <td>{{ $c->project->code }}</td>
                <td>{{ $c->claimant->name }}</td>
                <td>{{ $c->category->name }}</td>
                <td class="muted">{{ \Illuminate\Support\Str::limit($c->description, 40) }}</td>
                <td class="num"><strong>{{ ugx($c->amount) }}</strong></td>
                <td><span class="badge {{ $c->tone() }}">{{ $c->statusLabel() }}</span></td>
                <td><div class="row-actions">
                    <button type="button" class="btn-icon" data-modal-target="#claim-{{ $c->id }}" title="View {{ $c->claim_no }}" aria-label="View {{ $c->claim_no }}"><i class="fas fa-eye"></i></button>
                    @can('edit-claim', $c)<a class="btn-icon" data-modal href="{{ route('claims.edit', $c) }}" title="Edit {{ $c->claim_no }}" aria-label="Edit {{ $c->claim_no }}"><i class="fas fa-pen"></i></a>@endcan
                    @can('delete-claim', $c)
                        <form method="POST" action="{{ route('claims.destroy', $c) }}" class="inline-form">@csrf @method('DELETE')
                            <button class="btn-icon danger" title="Delete {{ $c->claim_no }}" aria-label="Delete {{ $c->claim_no }}" data-confirm="Delete claim {{ $c->claim_no }} ({{ ugx($c->amount) }} {{ currency() }})? This cannot be undone; it stays in the activity log." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i></button></form>
                    @endcan
                </div>
                <template id="claim-{{ $c->id }}" data-title="Claim {{ $c->claim_no }}">
                    @include('claims.partials.details', ['claim' => $c])
                </template></td>
            </tr>
        @empty <tr><td colspan="10" class="empty">No claims{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
        @if($claims->count())
        <tfoot><tr><td></td><td colspan="6">Total on this page</td><td class="num">{{ ugx($claims->sum('amount')) }}</td><td colspan="2"></td></tr></tfoot>
        @endif
    </table>
</div>
<div class="pagination">{{ $claims->links() }}</div>

@if($canDecideAny)
<template id="bulk-reject" data-title="Reject selected claims">
    <form method="POST" action="{{ route('claims.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="reject">
        <p class="muted" style="margin-top:0">The reason is sent to each claimant. Claims you cannot decide are skipped and listed.</p>
        <div class="field"><label for="bulk_reason">Reason</label><textarea id="bulk_reason" name="reason" required maxlength="1000" placeholder="e.g. No receipt attached; resubmit with the receipt"></textarea></div>
        <button class="btn danger"><i class="fas fa-ban"></i> Reject selected</button>
    </form>
</template>
@endif
@if($canPayAny)
<template id="bulk-pay" data-title="Mark selected claims as paid">
    <form method="POST" action="{{ route('claims.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="pay">
        <p class="muted" style="margin-top:0">Each approved claim becomes one project expense. Claims that are not approved are skipped.</p>
        <div class="form-grid">
            <div class="field"><label for="bulk_mode">Paid by</label><select id="bulk_mode" name="payment_mode" required>@foreach(\App\Models\Expense::PAYMENT_MODES as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</select></div>
            <div class="field"><label for="bulk_ref">Reference (optional)</label><input id="bulk_ref" type="text" name="reference" maxlength="100" placeholder="e.g. MoMo batch 2026-11-14"></div>
        </div>
        <button class="btn"><i class="fas fa-money-check-dollar"></i> Record payments</button>
    </form>
</template>
@endif
@endsection
