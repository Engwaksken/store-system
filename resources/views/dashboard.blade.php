@extends('layouts.app')
@section('content')
<div class="page-head">
    <div><h1>Dashboard</h1><p>{{ now()->format('l, j F Y') }}</p></div>
    <div class="actions">
        @can('create-request')<a class="btn" href="{{ route('requests.create') }}">+ Material request</a>@endcan
        @can('record-expense')<a class="btn secondary" href="{{ route('expenses.create') }}">+ Expense</a>@endcan
        @can('manage-stock')<a class="btn secondary" href="{{ route('receipts.create') }}">+ Stock receipt</a>@endcan
    </div>
</div>

<div class="grid grid-4" style="margin-bottom:1.25rem">
    <div class="stat navy"><div class="label">Active projects</div><div class="value">{{ $projects->count() }}</div></div>
    <div class="stat gold"><div class="label">Awaiting my decision</div><div class="value">{{ $awaitingMe->count() }}</div></div>
    <div class="stat"><div class="label">Ready to issue</div><div class="value">{{ ($statusCounts['overall_approved'] ?? 0) + ($statusCounts['partially_issued'] ?? 0) }}</div></div>
    <div class="stat bad"><div class="label">Low-stock items</div><div class="value">{{ $lowStock->count() }}</div></div>
</div>

@if($awaitingMe->isNotEmpty())
<div class="card">
    <h2>Waiting for your decision</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>@foreach($awaitingMe as $r)
            <tr><td>{{ $r->request_no }}</td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td><td>@include('partials.status', ['status' => $r->status])</td>
                <td>{{ $r->submitted_at?->diffForHumans() }}</td><td class="right"><a class="btn small" href="{{ route('requests.show', $r) }}">Review</a></td></tr>
        @endforeach</tbody>
    </table></div>
</div>
@endif

@if($readyToIssue->isNotEmpty())
<div class="card">
    <h2>Approved requests ready to issue</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Request</th><th>Project</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach($readyToIssue as $r)
            <tr><td>{{ $r->request_no }}</td><td>{{ $r->project->code }}</td><td>@include('partials.status', ['status' => $r->status])</td>
                <td class="right"><a class="btn small" href="{{ route('issues.create', $r) }}">Issue</a></td></tr>
        @endforeach</tbody>
    </table></div>
</div>
@endif

@if($costRows->isNotEmpty())
<div class="card">
    <h2>Project budget vs cost <span class="muted" style="font-weight:400;font-size:.85rem">(materials issued + cash expenses)</span></h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Project</th><th class="num">Budget</th><th class="num">Materials</th><th class="num">Expenses</th><th class="num">Remaining</th><th>Used</th></tr></thead>
        <tbody>@foreach($costRows as $c)
            <tr><td><a href="{{ route('projects.show', $c->project) }}">{{ $c->project->code }}</a> {{ $c->project->name }}</td>
                <td class="num">{{ ugx($c->budget) }}</td><td class="num">{{ ugx($c->materials) }}</td><td class="num">{{ ugx($c->expenses) }}</td>
                <td class="num">{{ ugx($c->remaining) }}</td>
                <td>@if($c->used_pct !== null)<div @class(['bar', 'over' => $c->used_pct > 100])><span style="width:{{ min(100, $c->used_pct) }}%"></span></div><small>{{ $c->used_pct }}%</small>@endif</td></tr>
        @endforeach</tbody>
    </table></div>
</div>
@endif

<div class="grid grid-2">
    <div class="card">
        <h2>Low stock</h2>
        @forelse($lowStock->take(10) as $i)
            <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--line)"><a href="{{ route('items.show', $i) }}">{{ $i->name }}</a><span>{{ qty($i->onHand()) }} / min {{ qty($i->min_level) }} {{ $i->unit->abbreviation }}</span></div>
        @empty <p class="muted">No items below minimum level.</p> @endforelse
    </div>
    <div class="card">
        <h2>My recent requests</h2>
        @forelse($myRequests as $r)
            <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--line)"><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }} · {{ $r->project->code }}</a>@include('partials.status', ['status' => $r->status])</div>
        @empty <p class="muted">You have not raised any requests.</p> @endforelse
        @if($recentIssues->isNotEmpty())
            <h2 style="margin-top:1rem">Recent issues</h2>
            @foreach($recentIssues as $i)
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--line)"><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }} · {{ $i->project->code }}</a><span class="muted">{{ $i->issued_at->format('d M H:i') }}</span></div>
            @endforeach
        @endif
    </div>
</div>
@endsection
