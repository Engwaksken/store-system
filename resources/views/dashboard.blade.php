@extends('layouts.app')
@section('content')
<div class="page-head">
    <div><h1>Dashboard</h1><p>{{ now()->format('l, j F Y') }}</p></div>
    <div class="actions">
        @can('create-request')<a class="btn" href="{{ route('requests.create') }}"><i class="fas fa-plus"></i> Material request</a>@endcan
        @can('record-expense')<a class="btn secondary" data-modal href="{{ route('expenses.create') }}"><i class="fas fa-plus"></i> Expense</a>@endcan
        @can('manage-stock')<a class="btn secondary" href="{{ route('receipts.create') }}"><i class="fas fa-plus"></i> Stock receipt</a>@endcan
    </div>
</div>

<div class="grid grid-4" style="margin-bottom:1.25rem">
    <div class="stat navy"><span class="stat-icon"><i class="fas fa-diagram-project"></i></span><div class="stat-body"><div class="label">Active projects</div><div class="value">{{ $projects->count() }}</div></div></div>
    <div class="stat gold"><span class="stat-icon"><i class="fas fa-hourglass-half"></i></span><div class="stat-body"><div class="label">Awaiting my decision</div><div class="value">{{ $awaitingMe->count() }}</div></div></div>
    <div class="stat"><span class="stat-icon"><i class="fas fa-dolly"></i></span><div class="stat-body"><div class="label">Ready to issue</div><div class="value">{{ ($statusCounts['overall_approved'] ?? 0) + ($statusCounts['partially_issued'] ?? 0) }}</div></div></div>
    <div class="stat bad"><span class="stat-icon"><i class="fas fa-triangle-exclamation"></i></span><div class="stat-body"><div class="label">Low-stock items</div><div class="value">{{ $lowStock->count() }}</div></div></div>
</div>

@if($queues->isNotEmpty())
<div class="card"><h2>Also waiting for you</h2>
    <div class="grid grid-4">@foreach($queues as [$label, $url, $n])
        <a href="{{ $url }}" class="stat gold" style="text-decoration:none"><span class="stat-icon"><i class="fas fa-list-check"></i></span><div class="stat-body"><div class="label">{{ $label }}</div><div class="value">{{ $n }}</div></div></a>
    @endforeach</div>
</div>
@endif

@if($finance || $storeValues->isNotEmpty())
<div class="grid grid-2">
    @if($finance)
    <div class="card"><h2>Purchasing &amp; payables</h2><dl class="meta">
        <dt>Open purchase orders (not yet received)</dt><dd><strong>{{ ugx($finance->openPo) }}</strong> UGX</dd>
        <dt>Unpaid supplier invoices</dt><dd><a href="{{ route('invoices.index', ['payment_status' => 'unpaid']) }}">{{ $finance->unpaidCount }}</a> · {{ ugx($finance->unpaid) }} UGX</dd>
        <dt>Overdue</dt><dd>@if($finance->overdueCount)<span class="badge rejected">{{ $finance->overdueCount }}</span> {{ ugx($finance->overdue) }} UGX @else none @endif</dd>
    </dl></div>
    @endif
    @if($storeValues->isNotEmpty())
    <div class="card"><h2>Stock value by store <small class="muted">(average cost)</small></h2><table>
        <tbody>@foreach($storeValues as $s)<tr><td><a href="{{ route('stores.show', $s->id) }}">{{ $s->code }}</a> {{ $s->name }}</td><td class="num">{{ ugx($s->value) }}</td></tr>@endforeach</tbody>
        <tfoot><tr><td>Total</td><td class="num">{{ ugx($storeValues->sum('value')) }}</td></tr></tfoot></table></div>
    @endif
</div>
@endif

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
    <h2>Project budget vs cost <span class="muted" style="font-weight:400;font-size:.85rem">(actual = materials issued + cash expenses; committed = open POs + approved claims)</span></h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Project</th><th class="num">Budget</th><th class="num">Materials</th><th class="num">Expenses</th><th class="num">Committed</th><th class="num">Uncommitted</th><th>Actual + committed</th></tr></thead>
        <tbody>@foreach($costRows as $c)
            <tr><td><a href="{{ route('projects.show', $c->project) }}">{{ $c->project->code }}</a> {{ $c->project->name }}</td>
                <td class="num">{{ ugx($c->budget) }}</td><td class="num">{{ ugx($c->materials) }}</td><td class="num">{{ ugx($c->expenses) }}</td>
                <td class="num">{{ ugx($c->committed) }}</td><td class="num">{{ ugx($c->uncommitted) }}</td>
                <td>@if($c->exposure_pct !== null)<div @class(['bar', 'over' => $c->flag === 'over'])><span style="width:{{ min(100, $c->exposure_pct) }}%"></span></div><small>{{ $c->exposure_pct }}%</small>@endif
                    @if($c->flag === 'over')<span class="badge rejected">Over budget</span>@elseif($c->flag === 'warn')<span class="badge pending">Near budget</span>@endif</td></tr>
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
