{{-- Claim details for the "view" modal on the claims list; approvers get the decision form inside it. --}}
<dl class="meta detail-list">
    <dt>Status</dt><dd><span class="badge {{ $claim->tone() }}">{{ $claim->statusLabel() }}</span></dd>
    <dt>Project</dt><dd>{{ $claim->project->code }} - {{ $claim->project->name }}</dd>
    <dt>Claimant</dt><dd>{{ $claim->claimant->name }}</dd>
    <dt>Date spent</dt><dd>{{ $claim->incurred_on->format('d M Y') }}</dd>
    <dt>Amount</dt><dd><strong>{{ ugx($claim->amount) }} {{ currency() }}</strong></dd>
    <dt>Category</dt><dd>{{ $claim->category->name }}</dd>
    <dt>Description</dt><dd>{{ $claim->description }}</dd>
    <dt>Receipt</dt><dd>@if($claim->receipt_path)<a href="{{ route('claims.receipt', $claim) }}"><i class="fas fa-paperclip"></i> Download receipt</a>@else<span class="muted">None attached</span>@endif</dd>
    @if($claim->decided_at)<dt>Decision</dt><dd>{{ $claim->statusLabel() }} on {{ $claim->decided_at->format('d M Y H:i') }}@if($claim->decision_reason): {{ $claim->decision_reason }}@endif</dd>@endif
    <dt>Submitted</dt><dd>{{ $claim->created_at->format('d M Y H:i') }}@if($claim->updated_at->gt($claim->created_at->addMinute())) <span class="muted">(edited {{ $claim->updated_at->diffForHumans() }})</span>@endif</dd>
</dl>
<p class="modal-links"><a href="{{ route('claims.show', $claim) }}"><i class="fas fa-up-right-from-square"></i> Open full page</a>
    @can('edit-claim', $claim) · <a data-modal href="{{ route('claims.edit', $claim) }}">@if($claim->status === 'rejected')<i class="fas fa-rotate"></i> Edit &amp; resubmit @else<i class="fas fa-pen"></i> Edit @endif</a>@endcan</p>
@can('decide-claim', $claim)
<form method="POST" action="{{ route('claims.decide', $claim) }}">@csrf
    <input type="hidden" name="seen_at" value="{{ $claim->updated_at?->toIso8601String() }}">
    <div class="field"><label for="reason_{{ $claim->id }}">Comment (required to reject)</label><input id="reason_{{ $claim->id }}" type="text" name="reason" maxlength="1000" placeholder="Optional when approving"></div>
    <button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve</button>
    <button class="btn danger" name="decision" value="rejected"><i class="fas fa-ban"></i> Reject</button>
</form>
@endcan
