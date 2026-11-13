@extends('layouts.app')
@section('title', $project->code.' controls')
@section('content')
<div class="page-head"><div><h1>{{ $project->code }} · budget, BOQ &amp; milestones</h1><p><a href="{{ route('projects.show', $project) }}">← {{ $project->name }}</a></p></div></div>

<div class="grid grid-4" style="margin-bottom:1.25rem">
    <div class="stat navy"><div class="label">Budget</div><div class="value">{{ ugx($summary->budget) }}</div></div>
    <div class="stat"><div class="label">Actual cost</div><div class="value">{{ ugx($summary->total) }}</div></div>
    <div class="stat gold"><div class="label">Committed</div><div class="value">{{ ugx($summary->committed) }}</div></div>
    <div @class(['stat', 'bad' => $summary->flag === 'over'])><div class="label">Uncommitted budget</div><div class="value">{{ ugx($summary->uncommitted) }}</div></div>
</div>

<div class="card table-wrap"><h2>Budget vs committed vs actual by cost code</h2>
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
                    @if($r->flag === 'over')<span class="badge rejected">Over</span>@elseif($r->flag === 'warn')<span class="badge pending">≥{{ \App\Services\ProjectCostService::WARN_PCT }}%</span>@endif</td></tr>
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
    @if($canEdit)<p><button class="btn">Save budgets</button></p>@endif
</form>
<p class="hint">Actual = materials issued (net of returns, allocated by the BOQ line on the request) + expenses. Committed = approved purchase orders not yet received + approved claims not yet paid. Variance = budget − (actual + committed). Rows turn amber from {{ \App\Services\ProjectCostService::WARN_PCT }}% and red above 100%.</p>
</div>

<div class="card table-wrap"><h2>Bill of quantities</h2><table>
    <thead><tr><th>Ref</th><th>Description</th><th>Cost code</th><th class="num">Qty</th><th>Unit</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">Materials used</th><th>%</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>@forelse($boq as $row)
        @php($b = $row->boq)
        <tr @class(['low' => $row->pct !== null && $row->pct > 100])><td>{{ $b->ref }}</td><td>{{ $b->description }}@if($b->item) <small class="muted">({{ $b->item->sku }})</small>@endif</td><td>{{ $b->costCode->code }}</td>
            <td class="num">{{ qty($b->qty) }}</td><td>{{ $b->unit }}</td><td class="num">{{ ugx($b->rate) }}</td><td class="num">{{ ugx($b->amount) }}</td><td class="num">{{ ugx($row->consumed_value) }}</td><td>{{ $row->pct !== null ? $row->pct.'%' : '' }}</td>
            @if($canEdit)<td><form method="POST" action="{{ route('projects.boq.destroy', [$project, $b]) }}" class="inline-form">@csrf @method('DELETE')<button class="btn link small" aria-label="Remove {{ $b->ref }}">Remove</button></form></td>@endif</tr>
    @empty <tr><td colspan="10" class="empty">No BOQ lines yet.</td></tr> @endforelse</tbody>
    <tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($boq->reduce(fn ($c, $r) => bcadd($c, (string) $r->boq->amount, 2), '0')) }}</td><td class="num">{{ ugx($boq->reduce(fn ($c, $r) => bcadd($c, $r->consumed_value, 2), '0')) }}</td><td colspan="{{ $canEdit ? 2 : 1 }}"></td></tr></tfoot>
</table>
@if($canEdit && $costCodes->isNotEmpty())
<form method="POST" action="{{ route('projects.boq.store', $project) }}" style="margin-top:1rem">
    @csrf
    <h3>Add BOQ line</h3>
    <div class="grid grid-4">
        <div><label for="ref">Ref</label><input id="ref" type="text" name="ref" value="{{ old('ref') }}" required maxlength="30"></div>
        <div style="grid-column:span 3"><label for="description">Description</label><input id="description" type="text" name="description" value="{{ old('description') }}" required></div>
        <div><label for="cost_code_id">Cost code</label><select id="cost_code_id" name="cost_code_id" required>@foreach($costCodes as $cc)<option value="{{ $cc->id }}" @selected(old('cost_code_id') == $cc->id)>{{ $cc->label() }}</option>@endforeach</select></div>
        <div><label for="unit">Unit</label><input id="unit" type="text" name="unit" value="{{ old('unit') }}" required maxlength="20"></div>
        <div><label for="qty">Quantity</label><input id="qty" type="number" step="0.001" min="0" name="qty" value="{{ old('qty') }}" required></div>
        <div><label for="rate">Rate (UGX)</label><input id="rate" type="number" step="0.01" min="0" name="rate" value="{{ old('rate') }}" required></div>
        <div style="grid-column:span 2"><label for="item_id">Catalogue item (optional)</label><select id="item_id" name="item_id"><option value="">—</option>@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->sku }} – {{ $i->name }}</option>@endforeach</select></div>
    </div>
    <button class="btn secondary" style="margin-top:.75rem">Add line</button>
</form>
@elseif($canEdit)
<p class="hint">Add cost codes first (Finance → Cost codes).</p>
@endif
</div>

<div class="card"><h2>Milestones</h2>
<ul class="timeline">
    @forelse($project->milestones as $m)
        <li @class(['approved' => $m->state() === 'done', 'rejected' => $m->state() === 'late'])><strong>{{ $m->name }}</strong>
            <span class="when">planned {{ $m->planned_on?->format('d M Y') ?? '—' }}@if($m->completed_on) · completed {{ $m->completed_on->format('d M Y') }}@elseif($m->state() === 'late') · <span class="badge rejected">Late</span>@endif</span>
            @if($m->notes)<div class="muted">{{ $m->notes }}</div>@endif
            @if($canEdit)<form method="POST" action="{{ route('projects.milestones.complete', [$project, $m]) }}" class="inline-form">@csrf @method('PUT')
                @if($m->completed_on)<button class="btn link small">Re-open</button>@else<input type="hidden" name="completed_on" value="{{ now()->format('Y-m-d') }}"><button class="btn link small">Mark complete today</button>@endif</form>@endif</li>
    @empty <li class="muted">No milestones yet.</li> @endforelse
</ul>
@if($canEdit)
<form method="POST" action="{{ route('projects.milestones.store', $project) }}" style="margin-top:1rem">@csrf
    <div class="grid grid-4">
        <div style="grid-column:span 2"><label for="m_name">Milestone</label><input id="m_name" type="text" name="name" required maxlength="200"></div>
        <div><label for="planned_on">Planned date</label><input id="planned_on" type="date" name="planned_on"></div>
        <div><label>&nbsp;</label><button class="btn secondary">Add milestone</button></div>
    </div></form>
@endif
</div>
@endsection
