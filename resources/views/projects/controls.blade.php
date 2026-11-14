@extends('layouts.app')
@section('title', $project->code.' controls')
@section('content')
<div class="page-head"><div><h1>{{ $project->code }} · budget, BOQ &amp; milestones</h1><p><a href="{{ route('projects.show', $project) }}"><i class="fas fa-arrow-left"></i> {{ $project->name }}</a></p></div></div>

<x-stats :cards="[
    ['Budget', ugx($summary->budget), 'fa-wallet', 'navy'],
    ['Actual cost', ugx($summary->total), 'fa-money-bill-trend-up'],
    ['Committed', ugx($summary->committed), 'fa-file-contract', 'gold'],
    ['Uncommitted budget', ugx($summary->uncommitted), 'fa-piggy-bank', $summary->flag === 'over' ? 'bad' : ''],
]" />

<x-tabs id="project-controls">
<x-tab title="Budget by cost code" icon="fa-scale-balanced" :count="$byCode->count()" name="budget">
<p class="muted" style="margin-top:0">Budget vs committed vs actual by cost code.</p>
<div class="table-wrap">
<form method="POST" action="{{ route('projects.budgets.save', $project) }}">
    @csrf @method('PUT')
    <table>
        <thead><tr><th>Cost code</th><th class="num">Budget</th><th class="num">Materials</th><th class="num">Expenses</th><th class="num">Actual</th><th class="num">Committed</th><th class="num">Variance</th><th>Used</th></tr></thead>
        <tbody>
        @php($shown = $byCode->pluck('cost_code_id')->filter()->all())
        @foreach($byCode as $r)
            <tr @class(['low' => $r->flag !== 'ok'])><td>{{ $r->label }}</td>
                <td class="num">@if($canEdit && $r->cost_code_id)<input type="number" step="1" min="0" name="budget[{{ $r->cost_code_id }}]" value="{{ (float) $r->budget ?: '' }}" aria-label="Budget {{ $r->label }}" style="max-width:9rem">@else{{ ugx($r->budget) }}@endif</td>
                <td class="num">{{ ugx($r->materials) }}</td><td class="num">{{ ugx($r->expenses) }}</td><td class="num">{{ ugx($r->actual) }}</td><td class="num">{{ ugx($r->committed) }}</td>
                <td class="num">{{ ugx($r->variance) }}</td>
                <td>@if($r->pct !== null)<div @class(['bar', 'over' => $r->flag === 'over'])><span style="width:{{ min(100, $r->pct) }}%"></span></div><small>{{ $r->pct }}%</small>@endif
                    @if($r->flag === 'over')<span class="badge rejected">Over</span>@elseif($r->flag === 'warn')<span class="badge pending"><i class="fas fa-greater-than-equal"></i> {{ \App\Services\ProjectCostService::WARN_PCT }}%</span>@endif</td></tr>
        @endforeach
        @if($canEdit)
            @foreach($costCodes->whereNotIn('id', $shown) as $cc)
                <tr><td class="muted">{{ $cc->label() }}</td><td class="num"><input type="number" step="1" min="0" name="budget[{{ $cc->id }}]" aria-label="Budget {{ $cc->label() }}" style="max-width:9rem"></td><td colspan="6"></td></tr>
            @endforeach
        @endif
        </tbody>
        <tfoot><tr><td>Total</td><td class="num">{{ ugx($byCode->reduce(fn ($c, $r) => bcadd($c, $r->budget, 2), '0')) }}</td><td colspan="2"></td>
            <td class="num">{{ ugx($summary->total) }}</td><td class="num">{{ ugx($summary->committed) }}</td><td colspan="2"></td></tr></tfoot>
    </table>
    @if($canEdit)<p><button class="btn"><i class="fas fa-floppy-disk"></i> Save budgets</button></p>@endif
</form>
</div>
<p class="hint">Actual = materials issued (net of returns, allocated by the BOQ line on the request) + expenses. Committed = approved purchase orders not yet received + approved claims not yet paid. Variance = budget - (actual + committed). Rows turn amber from {{ \App\Services\ProjectCostService::WARN_PCT }}% and red above 100%.</p>
</x-tab>

<x-tab title="Bill of quantities" icon="fa-list-ol" :count="$boq->count()" name="boq">
@if($canEdit && $costCodes->isNotEmpty())<div class="actions" style="margin-bottom:.75rem">
    <button type="button" class="btn small" data-modal-target="#boq-create"><i class="fas fa-plus"></i> Add BOQ line</button>
    <button type="button" class="btn secondary small" data-modal-target="#boq-import"><i class="fas fa-file-import"></i> Import CSV</button></div>@endif
<div class="table-wrap">
<table>
    <thead><tr><th>Ref</th><th>Description</th><th>Cost code</th><th class="num">Qty</th><th>Unit</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">Materials used</th><th>%</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>@forelse($boq as $row)
        @php($b = $row->boq)
        <tr @class(['low' => $row->pct !== null && $row->pct > 100])><td>{{ $b->ref }}</td><td>{{ $b->description }}@if($b->item) <small class="muted">({{ $b->item->sku }})</small>@endif</td><td>{{ $b->costCode->code }}</td>
            <td class="num">{{ qty($b->qty) }}</td><td>{{ $b->unit }}</td><td class="num">{{ ugx($b->rate) }}</td><td class="num">{{ ugx($b->amount) }}</td><td class="num">{{ ugx($row->consumed_value) }}</td><td>{{ $row->pct !== null ? $row->pct.'%' : '' }}</td>
            @if($canEdit)<td><div class="row-actions">
                <button type="button" class="btn-icon" data-modal-target="#boq-edit-{{ $b->id }}" title="Edit {{ $b->ref }}" aria-label="Edit {{ $b->ref }}"><i class="fas fa-pen"></i></button>
                <form method="POST" action="{{ route('projects.boq.destroy', [$project, $b]) }}" class="inline-form">@csrf @method('DELETE')
                    <button class="btn-icon danger" title="Remove {{ $b->ref }}" aria-label="Remove {{ $b->ref }}" data-confirm="Remove BOQ line {{ $b->ref }} ({{ $b->description }})? Lines already used on material requests cannot be removed." data-confirm-ok="Yes, remove"><i class="fas fa-trash-can"></i></button></form>
                <template id="boq-edit-{{ $b->id }}" data-title="Edit BOQ line {{ $b->ref }}">
                    <form method="POST" action="{{ route('projects.boq.update', [$project, $b]) }}">@csrf @method('PUT')
                        @include('projects.partials.boq-fields', ['line' => $b])
                        <button class="btn">Save line</button>
                    </form>
                </template>
            </div></td>@endif</tr>
    @empty <tr><td colspan="10" class="empty">No BOQ lines yet.</td></tr> @endforelse</tbody>
    <tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($boq->reduce(fn ($c, $r) => bcadd($c, (string) $r->boq->amount, 2), '0')) }}</td><td class="num">{{ ugx($boq->reduce(fn ($c, $r) => bcadd($c, $r->consumed_value, 2), '0')) }}</td><td colspan="{{ $canEdit ? 2 : 1 }}"></td></tr></tfoot>
</table>
</div>
@if($canEdit && $costCodes->isNotEmpty())
<template id="boq-create" data-title="Add BOQ line">
    <form method="POST" action="{{ route('projects.boq.store', $project) }}">@csrf
        @include('projects.partials.boq-fields', ['line' => null])
        <button class="btn">Add line</button>
    </form>
</template>
<template id="boq-import" data-title="Import BOQ lines (CSV)">
    <form method="POST" action="{{ route('projects.boq.import', $project) }}" enctype="multipart/form-data">@csrf
        <p class="muted"><a href="{{ route('projects.boq.template', $project) }}"><i class="fas fa-download"></i> Download the template</a>: ref, description, cost_code, unit, qty, rate, optional sku. All rows are checked first; if any is wrong nothing is imported.</p>
        <div class="field"><label for="boq_file">CSV file</label><input id="boq_file" type="file" name="file" accept=".csv,text/csv" required></div>
        <button class="btn">Import BOQ</button>
    </form>
</template>
@elseif($canEdit)
<p class="hint">Add cost codes first (Finance <i class="fas fa-arrow-right"></i> Cost codes).</p>
@endif
</x-tab>

<x-tab title="Milestones" icon="fa-flag-checkered" :count="$project->milestones->count()" name="milestones">
@if($canEdit)<div class="actions" style="margin-bottom:.75rem"><button type="button" class="btn small" data-modal-target="#milestone-create"><i class="fas fa-plus"></i> Add milestone</button></div>@endif
<ul class="timeline">
    @forelse($project->milestones as $m)
        <li @class(['approved' => $m->state() === 'done', 'rejected' => $m->state() === 'late'])><strong>{{ $m->name }}</strong>
            <span class="when">planned {{ $m->planned_on?->format('d M Y') ?? '-' }}@if($m->completed_on) · completed {{ $m->completed_on->format('d M Y') }}@elseif($m->state() === 'late') · <span class="badge rejected">Late</span>@endif</span>
            @if($m->notes)<div class="muted">{{ $m->notes }}</div>@endif
            @if($canEdit)<form method="POST" action="{{ route('projects.milestones.complete', [$project, $m]) }}" class="inline-form">@csrf @method('PUT')
                @if($m->completed_on)<button class="btn link small"><i class="fas fa-rotate-left"></i> Re-open</button>@else<input type="hidden" name="completed_on" value="{{ now()->format('Y-m-d') }}"><button class="btn link small"><i class="fas fa-check"></i> Mark complete today</button>@endif</form>@endif</li>
    @empty <li class="muted">No milestones yet.</li> @endforelse
</ul>
@if($canEdit)
<template id="milestone-create" data-title="Add milestone">
    <form method="POST" action="{{ route('projects.milestones.store', $project) }}">@csrf
        <div class="field"><label for="m_name">Milestone</label><input id="m_name" type="text" name="name" required maxlength="200"></div>
        <div class="field"><label for="planned_on">Planned date</label><input id="planned_on" type="date" name="planned_on"></div>
        <button class="btn">Add milestone</button>
    </form>
</template>
@endif
</x-tab>
</x-tabs>
@endsection
