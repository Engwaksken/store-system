@extends('layouts.app')
@section('title', $req->exists ? 'Edit request' : 'New material request')
@section('content')
<div class="page-head"><div><h1>{{ $req->exists ? 'Edit '.$req->request_no.' (v'.$req->version.')' : 'New material request' }}</h1>
    <p>Route: you <i class="fas fa-arrow-right"></i> Project Manager <i class="fas fa-arrow-right"></i> Overall Manager <i class="fas fa-arrow-right"></i> Storekeeper issues.</p></div></div>
@if($req->exists && $req->status->isRejected())
    @php($rejection = $req->approvals()->where('version', $req->version)->where('decision', 'rejected')->latest('id')->first())
    <div class="alert bad"><i class="fas fa-ban"></i> This request was rejected{{ $rejection ? ' by '.$rejection->approver->name : '' }}@if($rejection?->reason): <strong>{{ $rejection->reason }}</strong>@endif.
        Correct it below; saving sends it back to the Project Manager as version {{ $req->version + 1 }}.</div>
@elseif($req->exists && $req->status !== \App\Enums\RequestStatus::Draft)
    <div class="alert warn"><i class="fas fa-triangle-exclamation"></i> This request is waiting for approval{{ $req->status === \App\Enums\RequestStatus::PmApproved ? ' and the Project Manager has already approved it' : '' }}.
        Saving your changes makes it version {{ $req->version + 1 }} and sends it back to the Project Manager to approve again.</div>
@endif
@if($projects->isEmpty())<div class="alert warn">You are not assigned to any active project, so you cannot raise requests.</div>@endif
@php($old = old('lines', $req->exists ? $req->lines->map(fn ($l) => ['item_id' => $l->item_id, 'boq_item_id' => $l->boq_item_id, 'requested_qty' => $l->requested_qty + 0, 'notes' => $l->notes])->all() : []))
@php($boqProjects = $projects->filter(fn ($p) => $p->boqItems->isNotEmpty()))
<form method="POST" action="{{ $req->exists ? route('requests.update', $req) : route('requests.store') }}" class="card">
    @csrf @if($req->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>Project</label><select name="project_id" required @disabled($req->exists)>
            <option value="">Select</option>
            @foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $req->project_id ?? request('project_id')) == $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach
        </select>@if($req->exists)<input type="hidden" name="project_id" value="{{ $req->project_id }}">@endif</div>
        <div class="field"><label>Needed on</label><input type="date" name="needed_on" value="{{ old('needed_on', $req->needed_on?->format('Y-m-d')) }}"></div>
    </div>
    <div class="field"><label>Purpose / work activity</label><textarea name="purpose" required maxlength="1000">{{ old('purpose', $req->purpose) }}</textarea></div>
    <div class="table-wrap"><table id="request-lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th>@if($boqProjects->isNotEmpty())<th>BOQ line (optional)</th>@endif<th>Notes</th></tr></thead>
        <tbody>@for($n = 0; $n < max(3, count($old)); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]"><option value="">None</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(($old[$n]['item_id'] ?? null) == $i->id)>{{ $i->name }} ({{ $i->unit->abbreviation }}) · in store: {{ qty($i->onHand()) }}</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][requested_qty]" value="{{ $old[$n]['requested_qty'] ?? '' }}"></td>
                @if($boqProjects->isNotEmpty())<td><select name="lines[{{ $n }}][boq_item_id]" aria-label="BOQ line"><option value="">None</option>@foreach($boqProjects as $bp)<optgroup label="{{ $bp->code }}">@foreach($bp->boqItems as $b)<option value="{{ $b->id }}" @selected(($old[$n]['boq_item_id'] ?? null) == $b->id)>{{ $b->label() }}</option>@endforeach</optgroup>@endforeach</select></td>@endif
                <td><input type="text" name="lines[{{ $n }}][notes]" value="{{ $old[$n]['notes'] ?? '' }}" maxlength="500"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#request-lines"><i class="fas fa-plus"></i> Add line</button></p>
    <p class="hint">Leave unused rows empty. Store balance is shown for information only. Approval does not reserve stock. Linking a BOQ line (of the same project) lets consumption be reported per BOQ line and cost code.</p>
    @if($req->exists && $req->status !== \App\Enums\RequestStatus::Draft)
        <div class="actions"><button class="btn" name="action" value="resubmit"><i class="fas fa-paper-plane"></i> Save &amp; send back for approval</button></div>
    @else
        <div class="actions"><button class="btn" name="action" value="submit"><i class="fas fa-paper-plane"></i> Submit for approval</button><button class="btn secondary" name="action" value="draft">Save draft</button></div>
    @endif
</form>
@endsection
