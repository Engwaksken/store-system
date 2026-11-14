@extends('layouts.app')
@section('title', 'Approvals')
@section('content')
<div class="page-head"><div><h1>Approvals</h1><p>Requests waiting for <strong>your</strong> decision. Stock is not reserved or deducted when you approve. Tick several to approve or reject them together with the full quantities shown; open one to change quantities.</p></div></div>
<x-stats :cards="[
    ['Waiting for you', $stats['pending'], 'fa-stamp', 'gold'],
    ['At PM stage', $stats['pm'], 'fa-user-tie', 'navy'],
    ['At final stage', $stats['overall'], 'fa-user-shield'],
    ['Oldest waiting', $stats['oldest'], 'fa-clock', $stats['pending'] ? 'bad' : ''],
]" />
<x-list-filters :list="$list" />
<div class="card table-wrap" data-bulk-scope>
    <x-bulk-bar :action="route('requests.bulk')">
        <button class="btn small" name="action" value="approve" data-confirm="Approve the selected requests with the full quantities shown?" data-confirm-ok="Yes, approve"><i class="fas fa-check"></i> Approve</button>
        <button type="button" class="btn small danger" data-modal-target="#approvals-bulk-reject"><i class="fas fa-ban"></i> Reject</button>
    </x-bulk-bar>
    <table class="data-table">
        <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all requests on this page"></th><th>Request</th><th>Project</th><th>Requester</th><th>Stage</th><th class="num">Lines</th><th>Submitted</th><th class="right">Actions</th></tr></thead>
        <tbody>@forelse($pending as $r)
            <tr>
                <td class="check-col"><input type="checkbox" value="{{ $r->id }}" data-version="{{ $r->version }}" data-row-check aria-label="Select {{ $r->request_no }}"></td>
                <td class="nowrap"><strong>{{ $r->request_no }}</strong> <small class="muted">v{{ $r->version }}</small></td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td>
                <td>@include('partials.status', ['status' => $r->status])</td><td class="num">{{ $r->lines->count() }}</td><td>{{ $r->submitted_at?->diffForHumans() }}</td>
                <td class="right"><a class="btn small" href="{{ route('requests.show', $r) }}">Review</a></td>
            </tr>
        @empty <tr><td colspan="8" class="empty">{{ $list->isFiltered() ? 'Nothing waiting for you matches these filters.' : 'Nothing is waiting for you.' }}</td></tr> @endforelse</tbody>
    </table>
</div>
<div class="pagination">{{ $pending->links() }}</div>
@include('requests.partials.bulk-reject', ['id' => 'approvals-bulk-reject'])
@endsection
