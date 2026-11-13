@extends('layouts.app')
@section('title', $req->exists ? 'Edit request' : 'New material request')
@section('content')
<div class="page-head"><div><h1>{{ $req->exists ? 'Edit '.$req->request_no.' (v'.$req->version.')' : 'New material request' }}</h1>
    <p>Route: you → Project Manager → Overall Manager → Storekeeper issues.</p></div></div>
@if($projects->isEmpty())<div class="alert warn">You are not assigned to any active project, so you cannot raise requests.</div>@endif
@php($old = old('lines', $req->exists ? $req->lines->map(fn ($l) => ['item_id' => $l->item_id, 'boq_item_id' => $l->boq_item_id, 'requested_qty' => $l->requested_qty + 0, 'notes' => $l->notes])->all() : []))
@php($boqProjects = $projects->filter(fn ($p) => $p->boqItems->isNotEmpty()))
<form method="POST" action="{{ $req->exists ? route('requests.update', $req) : route('requests.store') }}" class="card">
    @csrf @if($req->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>Project</label><select name="project_id" required @disabled($req->exists)>
            <option value="">— select —</option>
            @foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $req->project_id ?? request('project_id')) == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach
        </select>@if($req->exists)<input type="hidden" name="project_id" value="{{ $req->project_id }}">@endif</div>
        <div class="field"><label>Needed on</label><input type="date" name="needed_on" value="{{ old('needed_on', $req->needed_on?->format('Y-m-d')) }}"></div>
    </div>
    <div class="field"><label>Purpose / work activity</label><textarea name="purpose" required maxlength="1000">{{ old('purpose', $req->purpose) }}</textarea></div>
    <div class="table-wrap"><table id="request-lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th>@if($boqProjects->isNotEmpty())<th>BOQ line (optional)</th>@endif<th>Notes</th></tr></thead>
        <tbody>@for($n = 0; $n < max(3, count($old)); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]"><option value="">—</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(($old[$n]['item_id'] ?? null) == $i->id)>{{ $i->name }} ({{ $i->unit->abbreviation }}) · in store: {{ qty($i->onHand()) }}</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][requested_qty]" value="{{ $old[$n]['requested_qty'] ?? '' }}"></td>
                @if($boqProjects->isNotEmpty())<td><select name="lines[{{ $n }}][boq_item_id]" aria-label="BOQ line"><option value="">—</option>@foreach($boqProjects as $bp)<optgroup label="{{ $bp->code }}">@foreach($bp->boqItems as $b)<option value="{{ $b->id }}" @selected(($old[$n]['boq_item_id'] ?? null) == $b->id)>{{ $b->label() }}</option>@endforeach</optgroup>@endforeach</select></td>@endif
                <td><input type="text" name="lines[{{ $n }}][notes]" value="{{ $old[$n]['notes'] ?? '' }}" maxlength="500"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#request-lines">+ Add line</button></p>
    <p class="hint">Leave unused rows empty. Store balance is shown for information only. Approval does not reserve stock. Linking a BOQ line (of the same project) lets consumption be reported per BOQ line and cost code.</p>
    <div class="actions"><button class="btn" name="action" value="submit">Submit for approval</button><button class="btn secondary" name="action" value="draft">Save draft</button></div>
</form>
@include('partials.add-line')
@endsection
