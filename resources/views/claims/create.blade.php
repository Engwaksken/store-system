@extends('layouts.app')
@section('title', 'New expense claim')
@section('content')
<div class="page-head"><div><h1>New expense claim</h1><p>For money you spent on a project (transport, small purchases, casual labour). Attach the receipt.</p></div></div>
@if($projects->isEmpty())<div class="alert warn">You are not assigned to an active project.</div>@endif
<form method="POST" action="{{ route('claims.store') }}" enctype="multipart/form-data" class="card" style="max-width:760px">
    @csrf
    <div class="form-grid">
        <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id" required><option value="">—</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id') == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label for="incurred_on">Date spent</label><input id="incurred_on" type="date" name="incurred_on" value="{{ old('incurred_on', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id" required>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label for="cost_code_id">Cost code (if known)</label><select id="cost_code_id" name="cost_code_id"><option value="">—</option>@foreach($costCodes as $cc)<option value="{{ $cc->id }}" @selected(old('cost_code_id') == $cc->id)>{{ $cc->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="amount">Amount (UGX)</label><input id="amount" type="number" step="1" min="1" name="amount" value="{{ old('amount') }}" required></div>
        <div class="field"><label for="receipt">Receipt (PDF/JPG/PNG, max 4 MB)</label><input id="receipt" type="file" name="receipt" accept=".pdf,image/png,image/jpeg"></div>
    </div>
    <div class="field"><label for="description">What was it for?</label><textarea id="description" name="description" required maxlength="1000">{{ old('description') }}</textarea></div>
    <button class="btn">Submit claim</button>
</form>
@endsection
