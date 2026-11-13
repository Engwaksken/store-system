@extends('layouts.app')
@section('title', $expense->exists ? 'Edit expense' : 'Record expense')
@section('content')
<div class="page-head"><h1>{{ $expense->exists ? 'Edit expense' : 'Record project expense' }}</h1></div>
<div class="alert warn">Do <strong>not</strong> record materials bought into the store here. Record them as a stock receipt; they are charged to a project only when issued, so they are never counted twice.</div>
<form method="POST" action="{{ $expense->exists ? route('expenses.update', $expense) : route('expenses.store') }}" enctype="multipart/form-data" class="card" style="max-width:760px">
    @csrf @if($expense->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>Project</label><select name="project_id" required><option value="">—</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $expense->project_id) == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label>Category</label><select name="category_id" required>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $expense->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label for="cost_code_id">Cost code</label><select id="cost_code_id" name="cost_code_id"><option value="">— none —</option>@foreach($costCodes as $cc)<option value="{{ $cc->id }}" @selected(old('cost_code_id', $expense->cost_code_id) == $cc->id)>{{ $cc->label() }}</option>@endforeach</select></div>
        <div class="field"><label>Date</label><input type="date" name="incurred_on" max="{{ now()->format('Y-m-d') }}" value="{{ old('incurred_on', $expense->incurred_on?->format('Y-m-d')) }}" required></div>
        <div class="field"><label>Amount (UGX)</label><input type="number" name="amount" step="1" min="1" value="{{ old('amount', $expense->amount) }}" required></div>
        <div class="field"><label>Payment mode</label><select name="payment_mode">@foreach(\App\Models\Expense::PAYMENT_MODES as $m)<option value="{{ $m }}" @selected(old('payment_mode', $expense->payment_mode) === $m)>{{ str_replace('_', ' ', $m) }}</option>@endforeach</select></div>
        <div class="field"><label>Reference (receipt / txn no.)</label><input type="text" name="reference" value="{{ old('reference', $expense->reference) }}"></div>
    </div>
    <div class="field"><label>Description</label><input type="text" name="description" value="{{ old('description', $expense->description) }}" required></div>
    <div class="field"><label>Proof (PDF/JPG/PNG, max 4 MB)</label><input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png">@if($expense->receipt_path)<div class="hint">A file is already attached; uploading replaces it.</div>@endif</div>
    <button class="btn">Save expense</button>
</form>
@endsection
