@extends('layouts.app')
@php($editing = isset($claim) && $claim->exists)
@section('title', $editing ? 'Edit claim '.$claim->claim_no : 'New expense claim')
@section('content')
<div class="page-head"><div><h1>{{ $editing ? 'Edit claim '.$claim->claim_no : 'New expense claim' }}</h1>
    <p>{{ $editing ? 'You can change the claim until it is approved. The approver is told about the change.' : 'For money you spent on a project (transport, small purchases, casual labour). Attach the receipt.' }}</p></div></div>
@if($projects->isEmpty())<div class="alert warn">You are not assigned to an active project.</div>@endif
<form method="POST" action="{{ $editing ? route('claims.update', $claim) : route('claims.store') }}" enctype="multipart/form-data" class="card" style="max-width:760px">
    @csrf @if($editing) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id" required><option value="">Select</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $claim->project_id ?? null) == $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label for="incurred_on">Date spent</label><input id="incurred_on" type="date" name="incurred_on" value="{{ old('incurred_on', $editing ? $claim->incurred_on->format('Y-m-d') : now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id" required>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $claim->category_id ?? null) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label for="cost_code_id">Cost code (if known)</label><select id="cost_code_id" name="cost_code_id"><option value="">None</option>@foreach($costCodes as $cc)<option value="{{ $cc->id }}" @selected(old('cost_code_id', $claim->cost_code_id ?? null) == $cc->id)>{{ $cc->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="amount">Amount ({{ currency() }})</label><input id="amount" type="number" step="1" min="1" name="amount" value="{{ old('amount', $editing ? (float) $claim->amount : null) }}" required></div>
        <div class="field"><label for="receipt">{{ $editing && $claim->receipt_path ? 'Replace receipt (optional)' : 'Receipt (PDF/JPG/PNG, max 4 MB)' }}</label><input id="receipt" type="file" name="receipt" accept=".pdf,image/png,image/jpeg">
            @if($editing && $claim->receipt_path)<p class="hint"><a href="{{ route('claims.receipt', $claim) }}"><i class="fas fa-paperclip"></i> Current receipt</a> stays unless you upload a new one.</p>@endif</div>
    </div>
    <div class="field"><label for="description">What was it for?</label><textarea id="description" name="description" required maxlength="1000">{{ old('description', $claim->description ?? null) }}</textarea></div>
    <button class="btn">{{ $editing ? 'Save changes' : 'Submit claim' }}</button>
</form>
@endsection
