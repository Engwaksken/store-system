@extends('layouts.app')
@section('title', $claim->claim_no)
@section('content')
<div class="page-head"><div><h1>Claim {{ $claim->claim_no }} <span class="badge {{ $claim->tone() }}">{{ $claim->statusLabel() }}</span></h1>
    <p>{{ $claim->project->code }} · {{ $claim->project->name }}</p></div>
    @if($claim->receipt_path)<a class="btn secondary" href="{{ route('claims.receipt', $claim) }}">Download receipt</a>@endif</div>
<div class="card"><dl class="meta">
    <dt>Claimant</dt><dd>{{ $claim->claimant->name }}</dd>
    <dt>Date spent</dt><dd>{{ $claim->incurred_on->format('d M Y') }}</dd>
    <dt>Amount</dt><dd><strong>{{ ugx($claim->amount) }} UGX</strong></dd>
    <dt>Category</dt><dd>{{ $claim->category->name }}</dd>
    <dt>Cost code</dt><dd>{{ $claim->costCode?->label() ?? '—' }}</dd>
    <dt>Description</dt><dd>{{ $claim->description }}</dd>
    @if($claim->decided_at)<dt>Decision</dt><dd>{{ $claim->status === 'rejected' ? 'Rejected' : 'Approved' }} by {{ $claim->approver->name }} on {{ $claim->decided_at->format('d M Y H:i') }}@if($claim->decision_reason): {{ $claim->decision_reason }}@endif</dd>@endif
    @if($claim->expense)<dt>Paid</dt><dd>Recorded as expense #{{ $claim->expense->id }} ({{ $claim->expense->payment_mode }}{{ $claim->expense->reference ? ', '.$claim->expense->reference : '' }})</dd>@endif
</dl></div>
@can('decide-claim', $claim)
<form method="POST" action="{{ route('claims.decide', $claim) }}" class="card" style="max-width:640px">@csrf
    <h2>Approve or reject</h2>
    <div class="field"><label for="reason">Comment (required to reject)</label><input id="reason" type="text" name="reason"></div>
    <div class="actions"><button class="btn" name="decision" value="approved">Approve</button><button class="btn danger" name="decision" value="rejected">Reject</button></div>
</form>
@endcan
@can('pay-claim', $claim)
<form method="POST" action="{{ route('claims.pay', $claim) }}" class="card" style="max-width:640px">@csrf
    <h2>Record payment</h2><p class="muted">Creates the project expense. A claim can be paid only once.</p>
    <div class="form-grid">
        <div class="field"><label for="payment_mode">Paid by</label><select id="payment_mode" name="payment_mode">@foreach(\App\Models\Expense::PAYMENT_MODES as $m)<option value="{{ $m }}">{{ str_replace('_', ' ', $m) }}</option>@endforeach</select></div>
        <div class="field"><label for="reference">Reference</label><input id="reference" type="text" name="reference"></div>
    </div>
    <button class="btn">Record payment</button>
</form>
@endcan
@endsection
