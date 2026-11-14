@extends('layouts.app')
@section('title', 'Approvals')
@section('content')
<div class="page-head"><div><h1>Approvals</h1><p>Requests waiting for <strong>your</strong> decision. Stock is not reserved or deducted when you approve.</p></div></div>
<x-stats :cards="[
    ['Waiting for you', $stats['pending'], 'fa-stamp', 'gold'],
    ['At PM stage', $stats['pm'], 'fa-user-tie', 'navy'],
    ['At final stage', $stats['overall'], 'fa-user-shield'],
    ['Oldest waiting', $stats['oldest'], 'fa-clock', $stats['pending'] ? 'bad' : ''],
]" />
<div class="card table-wrap"><table>
    <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Stage</th><th class="num">Lines</th><th>Submitted</th><th></th></tr></thead>
    <tbody>@forelse($pending as $r)
        <tr><td>{{ $r->request_no }} <small class="muted">v{{ $r->version }}</small></td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td>
            <td>@include('partials.status', ['status' => $r->status])</td><td class="num">{{ $r->lines->count() }}</td><td>{{ $r->submitted_at?->diffForHumans() }}</td>
            <td class="right"><a class="btn small" href="{{ route('requests.show', $r) }}">Review</a></td></tr>
    @empty <tr><td colspan="7" class="empty">Nothing is waiting for you.</td></tr> @endforelse</tbody>
</table></div>
@endsection
