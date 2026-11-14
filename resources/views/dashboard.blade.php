@extends('layouts.app')
@section('content')
<div class="page-head">
    <div><h1>Dashboard</h1><p>{{ now()->format('l, j F Y') }}</p></div>
    <div class="actions">
        @can('create-request')<a class="btn" data-modal href="{{ route('requests.create') }}"><i class="fas fa-plus"></i> Material request</a>@endcan
        @can('record-expense')<a class="btn secondary" data-modal href="{{ route('expenses.create') }}"><i class="fas fa-plus"></i> Expense</a>@endcan
        @can('manage-stock')<a class="btn secondary" data-modal href="{{ route('receipts.create') }}"><i class="fas fa-plus"></i> Stock receipt</a>@endcan
    </div>
</div>

<x-stats :cards="[
    ['Active projects', $projects->count(), 'fa-diagram-project', 'navy', route('projects.index', ['status' => 'active'])],
    ['Awaiting my decision', $awaitingMe->count(), 'fa-hourglass-half', 'gold'],
    ['Ready to issue', ($statusCounts['overall_approved'] ?? 0) + ($statusCounts['partially_issued'] ?? 0), 'fa-dolly', '', route('issues.index')],
    ['Low-stock items', $lowStock->count(), 'fa-triangle-exclamation', 'bad', route('items.index', ['low' => 1])],
]" />

@if($queues->isNotEmpty())
<x-stats :cards="$queues->map(fn ($q) => [$q[0], $q[2], 'fa-list-check', 'gold', $q[1]])->all()" />
@endif

{{-- Work that needs action --}}
<x-tabs id="dashboard-work">
    <x-tab title="Waiting for your decision" icon="fa-stamp" name="decisions" :count="$awaitingMe->count()">
        @if($awaitingMe->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>@foreach($awaitingMe as $r)
                <tr><td>{{ $r->request_no }}</td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td><td>@include('partials.status', ['status' => $r->status])</td>
                    <td>{{ $r->submitted_at?->diffForHumans() }}</td><td class="right"><a class="btn small" href="{{ route('requests.show', $r) }}"><i class="fas fa-eye"></i> Review</a></td></tr>
            @endforeach</tbody>
        </table></div>
        @else <p class="empty"><i class="fas fa-circle-check text-ok"></i> Nothing is waiting for your decision.</p> @endif
    </x-tab>
    @if($readyToIssue->isNotEmpty() || Gate::allows('issue-stock'))
    <x-tab title="Ready to issue" icon="fa-dolly" name="issue" :count="$readyToIssue->count()">
        @if($readyToIssue->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Request</th><th>Project</th><th>Status</th><th></th></tr></thead>
            <tbody>@foreach($readyToIssue as $r)
                <tr><td>{{ $r->request_no }}</td><td>{{ $r->project->code }}</td><td>@include('partials.status', ['status' => $r->status])</td>
                    <td class="right"><a class="btn small" data-modal href="{{ route('issues.create', $r) }}"><i class="fas fa-dolly"></i> Issue</a></td></tr>
            @endforeach</tbody>
        </table></div>
        @else <p class="empty">No approved requests are waiting to be issued.</p> @endif
    </x-tab>
    @endif
    <x-tab title="Low stock" icon="fa-triangle-exclamation" name="low-stock" :count="$lowStock->count()">
        @if($lowStock->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">Minimum</th><th>Unit</th></tr></thead>
            <tbody>@foreach($lowStock->take(15) as $i)
                <tr class="low"><td><a href="{{ route('items.show', $i) }}">{{ $i->name }}</a></td><td class="num"><strong>{{ qty($i->onHand()) }}</strong></td><td class="num">{{ qty($i->min_level) }}</td><td>{{ $i->unit->abbreviation }}</td></tr>
            @endforeach</tbody>
        </table></div>
        @if($lowStock->count() > 15)<p><a href="{{ route('items.index', ['low' => 1]) }}">See all {{ $lowStock->count() }} low-stock items <i class="fas fa-arrow-right"></i></a></p>@endif
        @else <p class="empty"><i class="fas fa-circle-check text-ok"></i> No items below minimum level.</p> @endif
    </x-tab>
    <x-tab title="My recent requests" icon="fa-list-check" name="my-requests" :count="$myRequests->count()">
        @if($myRequests->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Request</th><th>Project</th><th>Status</th></tr></thead>
            <tbody>@foreach($myRequests as $r)
                <tr><td><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }}</a></td><td>{{ $r->project->code }}</td><td>@include('partials.status', ['status' => $r->status])</td></tr>
            @endforeach</tbody>
        </table></div>
        @else <p class="empty">You have not raised any requests.</p> @endif
    </x-tab>
    @if($recentIssues->isNotEmpty())
    <x-tab title="Recent issues" icon="fa-file-lines" name="recent-issues" :count="$recentIssues->count()">
        <div class="table-wrap"><table>
            <thead><tr><th>Voucher</th><th>Project</th><th>Issued</th></tr></thead>
            <tbody>@foreach($recentIssues as $i)
                <tr><td><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }}</a></td><td>{{ $i->project->code }}</td><td class="muted">{{ $i->issued_at->format('d M Y H:i') }}</td></tr>
            @endforeach</tbody>
        </table></div>
    </x-tab>
    @endif
</x-tabs>

{{-- Money and stock overview (finance roles) --}}
@if($costRows->isNotEmpty() || $finance || $storeValues->isNotEmpty())
<x-tabs id="dashboard-finance">
    @if($costRows->isNotEmpty())
    <x-tab title="Budget vs cost" icon="fa-scale-balanced" name="budget">
        <p class="hint" style="margin-top:0">Actual = materials issued + cash expenses; committed = open POs + approved claims.</p>
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
    </x-tab>
    @endif
    @if($finance)
    <x-tab title="Purchasing & payables" icon="fa-file-invoice-dollar" name="payables">
        <dl class="meta">
            <dt>Open purchase orders (not yet received)</dt><dd><strong>{{ ugx($finance->openPo) }}</strong> {{ currency() }}</dd>
            <dt>Unpaid supplier invoices</dt><dd><a href="{{ route('invoices.index', ['payment_status' => 'unpaid']) }}">{{ $finance->unpaidCount }}</a> · {{ ugx($finance->unpaid) }} {{ currency() }}</dd>
            <dt>Overdue</dt><dd>@if($finance->overdueCount)<span class="badge rejected">{{ $finance->overdueCount }}</span> {{ ugx($finance->overdue) }} {{ currency() }} @else none @endif</dd>
        </dl>
    </x-tab>
    @endif
    @if($storeValues->isNotEmpty())
    <x-tab title="Stock value by store" icon="fa-warehouse" name="stock-value">
        <div class="table-wrap"><table style="max-width:640px">
            <thead><tr><th>Store</th><th class="num">Value ({{ currency() }}, average cost)</th></tr></thead>
            <tbody>@foreach($storeValues as $s)<tr><td><a href="{{ route('stores.show', $s->id) }}">{{ $s->code }}</a> {{ $s->name }}</td><td class="num">{{ ugx($s->value) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td>Total</td><td class="num">{{ ugx($storeValues->sum('value')) }}</td></tr></tfoot></table></div>
    </x-tab>
    @endif
</x-tabs>
@endif
@endsection
