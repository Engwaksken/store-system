@extends('layouts.app')
@section('title', 'Material requests')
@section('content')
<div class="page-head"><h1>Material requests</h1>@can('create-request')<a class="btn" data-modal href="{{ route('requests.create') }}"><i class="fas fa-plus"></i> New request</a>@endcan</div>
<x-stats :cards="[
    ['Requests', number_format($stats['total']), 'fa-list-check', 'navy'],
    ['Awaiting approval', $stats['pending'], 'fa-hourglass-half', 'gold'],
    ['Ready to issue', $stats['ready'], 'fa-dolly'],
    ['Fully issued', $stats['done'], 'fa-circle-check'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Enums\RequestStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
</x-list-filters>
@php($canDecide = auth()->user()->can('approve-any'))
<div class="card table-wrap" data-bulk-scope>
    <x-bulk-bar :action="route('requests.bulk')">
        @if($canDecide)
            <button class="btn small" name="action" value="approve" data-confirm="Approve the selected requests at the step you decide, with the full quantities shown? Requests not waiting for you are skipped and listed." data-confirm-ok="Yes, approve"><i class="fas fa-check"></i> Approve</button>
            <button type="button" class="btn small danger" data-modal-target="#requests-bulk-reject"><i class="fas fa-ban"></i> Reject</button>
        @endif
        <button class="btn small secondary" name="action" value="cancel" data-confirm="Cancel the selected requests? Only drafts and requests still with the PM can be cancelled, by the requester or admin." data-confirm-ok="Yes, cancel"><i class="fas fa-xmark"></i> Cancel</button>
        <button class="btn small secondary" name="action" value="delete" data-confirm="Delete the selected drafts? Only drafts with no approval history are deleted; the rest are skipped (cancel them instead)." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button>
    </x-bulk-bar>
    <table class="data-table">
        <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all requests on this page"></th><th>Request</th><th>Project</th><th>Requester</th><th>Purpose</th><th>Raised</th><th>Needed</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>@forelse($requests as $r)
            <tr>
                <td class="check-col"><input type="checkbox" value="{{ $r->id }}" data-version="{{ $r->version }}" data-row-check aria-label="Select {{ $r->request_no }}"></td>
                <td class="nowrap"><a href="{{ route('requests.show', $r) }}"><strong>{{ $r->request_no }}</strong></a>@if($r->version > 1) <small class="muted">v{{ $r->version }}</small>@endif</td>
                <td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td>
                <td>{{ \Illuminate\Support\Str::limit($r->purpose, 50) }}</td><td class="nowrap">{{ $r->created_at->format('d M Y') }}</td><td>{{ $r->needed_on?->format('d M') }}</td>
                <td>@include('partials.status', ['status' => $r->status])</td>
                <td><div class="row-actions"><a class="btn-icon" href="{{ route('requests.show', $r) }}" title="Review {{ $r->request_no }}" aria-label="Review {{ $r->request_no }}"><i class="fas fa-eye"></i></a></div></td>
            </tr>
        @empty <tr><td colspan="9" class="empty">No requests{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
    </table>
</div>
<div class="pagination">{{ $requests->links() }}</div>
@if($canDecide)@include('requests.partials.bulk-reject', ['id' => 'requests-bulk-reject'])@endif
@endsection
