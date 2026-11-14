@extends('layouts.app')
@section('title', $claim->claim_no)
@section('content')
<div class="page-head"><div><h1>Claim {{ $claim->claim_no }} <span class="badge {{ $claim->tone() }}">{{ $claim->statusLabel() }}</span></h1>
    <p>{{ $claim->project->code }} · {{ $claim->project->name }}</p></div>
    <div class="actions">
        @if($claim->receipt_path)<a class="btn secondary" href="{{ route('claims.receipt', $claim) }}"><i class="fas fa-paperclip"></i> Download receipt</a>@endif
        @can('edit-claim', $claim)
            @if($claim->status === 'rejected')<a class="btn gold" data-modal href="{{ route('claims.edit', $claim) }}"><i class="fas fa-rotate"></i> Edit &amp; resubmit</a>
            @else<a class="btn secondary" data-modal href="{{ route('claims.edit', $claim) }}"><i class="fas fa-pen"></i> Edit</a>@endif
        @endcan
        @can('delete-claim', $claim)<form method="POST" action="{{ route('claims.destroy', $claim) }}" class="inline-form">@csrf @method('DELETE')
            <button class="btn danger" data-confirm="Delete claim {{ $claim->claim_no }}? This cannot be undone; it stays in the activity log." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button></form>@endcan
    </div></div>
<x-stats :cards="[
    ['Amount ('.currency().')', ugx($claim->amount), 'fa-money-bill-wave', 'navy'],
    ['Status', $claim->statusLabel(), 'fa-signal', $claim->status === 'rejected' ? 'bad' : ''],
    ['Date spent', $claim->incurred_on->format('d M Y'), 'fa-calendar-day', 'gold'],
    ['Category', $claim->category->name, 'fa-tag'],
]" />
@can('decide-claim', $claim)
<form method="POST" action="{{ route('claims.decide', $claim) }}" class="card" style="max-width:640px">@csrf
    <input type="hidden" name="seen_at" value="{{ $claim->updated_at?->toIso8601String() }}">
    <h2>Approve or reject</h2>
    <div class="field"><label for="reason">Comment (required to reject)</label><input id="reason" type="text" name="reason"></div>
    <div class="actions"><button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve</button><button class="btn danger" name="decision" value="rejected" data-confirm="Reject this document? The person who raised it will be notified." data-confirm-ok="Yes, reject"><i class="fas fa-ban"></i> Reject</button></div>
</form>
@endcan
<x-tabs id="claim-show">
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta">
            <dt>Claimant</dt><dd>{{ $claim->claimant->name }}</dd>
            <dt>Date spent</dt><dd>{{ $claim->incurred_on->format('d M Y') }}</dd>
            <dt>Amount</dt><dd><strong>{{ ugx($claim->amount) }} {{ currency() }}</strong></dd>
            <dt>Category</dt><dd>{{ $claim->category->name }}</dd>
            <dt>Cost code</dt><dd>{{ $claim->costCode?->label() ?? '-' }}</dd>
            <dt>Description</dt><dd>{{ $claim->description }}</dd>
            @if($claim->decided_at)<dt>Decision</dt><dd>{{ $claim->status === 'rejected' ? 'Rejected' : 'Approved' }} by {{ $claim->approver->name }} on {{ $claim->decided_at->format('d M Y H:i') }}@if($claim->decision_reason): {{ $claim->decision_reason }}@endif</dd>@endif
            @if($claim->expense)<dt>Paid</dt><dd>Recorded as expense #{{ $claim->expense->id }} ({{ $claim->expense->payment_mode }}{{ $claim->expense->reference ? ', '.$claim->expense->reference : '' }})</dd>@endif
        </dl>
    </x-tab>
    <x-tab title="Record payment" icon="fa-money-check-dollar" name="payment">
        @can('pay-claim', $claim)
        <form method="POST" action="{{ route('claims.pay', $claim) }}" style="max-width:640px">@csrf
            <p class="muted">Creates the project expense. A claim can be paid only once.</p>
            <div class="form-grid">
                <div class="field"><label for="payment_mode">Paid by</label><select id="payment_mode" name="payment_mode">@foreach(\App\Models\Expense::PAYMENT_MODES as $m)<option value="{{ $m }}">{{ str_replace('_', ' ', $m) }}</option>@endforeach</select></div>
                <div class="field"><label for="reference">Reference</label><input id="reference" type="text" name="reference"></div>
            </div>
            <button class="btn">Record payment</button>
        </form>
        @endcan
    </x-tab>
</x-tabs>
@endsection
