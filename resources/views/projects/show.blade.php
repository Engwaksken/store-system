@extends('layouts.app')
@section('title', $project->code)
@section('content')
<div class="page-head"><div><h1>{{ $project->code }} · {{ $project->name }}</h1><p>{{ $project->client }} @if($project->location) · {{ $project->location }} @endif</p></div>
    <div class="actions">
        @can('create-request')<a class="btn" href="{{ route('requests.create', ['project_id' => $project->id]) }}">+ Request materials</a>@endcan
        @can('view-project-finance', $project)<a class="btn secondary" href="{{ route('projects.controls', $project) }}">Budget, BOQ &amp; milestones</a>@endcan
        @can('manage-projects')<a class="btn secondary" href="{{ route('projects.edit', $project) }}">Edit</a>@endcan
    </div></div>

@if($cost)
<div class="grid grid-4" style="margin-bottom:1.25rem">
    <div class="stat navy"><div class="label">Budget</div><div class="value">{{ ugx($cost->budget) }}</div></div>
    <div class="stat"><div class="label">Materials (net of returns)</div><div class="value">{{ ugx($cost->materials) }}</div></div>
    <div class="stat gold"><div class="label">Cash expenses</div><div class="value">{{ ugx($cost->expenses) }}</div></div>
    <div @class(['stat', 'bad' => bccomp($cost->remaining, '0', 2) < 0])><div class="label">Remaining</div><div class="value">{{ ugx($cost->remaining) }}</div></div>
</div>
@if(bccomp($cost->committed, '0', 2) > 0 || $cost->flag !== 'ok')
<div @class(['alert', 'warn' => $cost->flag !== 'over', 'bad' => $cost->flag === 'over'])>Committed (open POs + approved claims): <strong>{{ ugx($cost->committed) }}</strong> UGX ·
    actual + committed = {{ ugx($cost->exposure) }} UGX{{ $cost->exposure_pct !== null ? ' ('.$cost->exposure_pct.'% of budget)' : '' }}.
    @if($cost->flag === 'over') <strong>Over budget.</strong> @elseif($cost->flag === 'warn') Approaching the budget. @endif</div>
@endif
<p class="hint" style="margin-top:-.75rem">Materials are valued at average cost when issued; returns to store are credited at the same cost. Store purchases count only when issued to this project, so nothing is counted twice.</p>
@endif

<div class="grid grid-2">
    <div class="card"><h2>Details</h2><dl class="meta">
        <dt>Status</dt><dd><span class="badge muted">{{ $project->status }}</span></dd>
        <dt>Manager</dt><dd>{{ $project->manager?->name ?? '— not assigned —' }}</dd>
        <dt>Dates</dt><dd>{{ $project->start_date?->format('d M Y') ?? '?' }} → {{ $project->end_date?->format('d M Y') ?? '?' }}</dd>
        <dt>Description</dt><dd>{{ $project->description ?: '—' }}</dd>
    </dl></div>
    <div class="card"><h2>Team ({{ $project->members->count() }})</h2>
        @forelse($project->members as $m)<div>{{ $m->name }} <small class="muted">{{ $m->role->label() }}</small></div>@empty<p class="muted">No staff assigned.</p>@endforelse
    </div>
</div>

<div class="card"><h2>Material requests</h2><div class="table-wrap"><table>
    <thead><tr><th>Request</th><th>Requester</th><th>Purpose</th><th>Status</th><th>Created</th></tr></thead>
    <tbody>@forelse($requests as $r)<tr><td><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }}</a></td><td>{{ $r->requester->name }}</td><td>{{ \Illuminate\Support\Str::limit($r->purpose, 60) }}</td><td>@include('partials.status', ['status' => $r->status])</td><td>{{ $r->created_at->format('d M Y') }}</td></tr>
    @empty<tr><td colspan="5" class="empty">No requests visible to you.</td></tr>@endforelse</tbody>
</table></div></div>

@if($issues->isNotEmpty())
<div class="card"><h2>Issue vouchers</h2><div class="table-wrap"><table>
    <thead><tr><th>Voucher</th><th>Date</th><th>Recipient</th>@if($cost)<th class="num">Value (UGX)</th>@endif</tr></thead>
    <tbody>@foreach($issues as $i)<tr><td><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }}</a></td><td>{{ $i->issued_at->format('d M Y H:i') }}</td><td>{{ $i->recipient_name }}</td>@if($cost)<td class="num">{{ ugx($i->total_value) }}</td>@endif</tr>@endforeach</tbody>
</table></div></div>
@endif

@if($cost)
<div class="card"><h2>Recent expenses</h2><div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount (UGX)</th></tr></thead>
    <tbody>@forelse($expenses as $e)<tr><td>{{ $e->incurred_on->format('d M Y') }}</td><td>{{ $e->category->name }}</td><td>{{ $e->description }}</td><td class="num">{{ ugx($e->amount) }}</td></tr>
    @empty<tr><td colspan="4" class="empty">No expenses recorded.</td></tr>@endforelse</tbody>
</table></div></div>
@endif
@endsection
