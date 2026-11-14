@extends('layouts.app')
@section('title', $req->request_no)
@section('content')
@php($canPm = Gate::allows('pm-decide', $req))
@php($canOverall = Gate::allows('overall-decide', $req))
<div class="page-head">
    <div><h1>{{ $req->request_no }} <small class="muted" style="font-size:1rem">version {{ $req->version }}</small></h1>
        <p>{{ $req->project->code }} · {{ $req->project->name }} - requested by {{ $req->requester->name }}</p></div>
    <div class="actions">
        @include('partials.status', ['status' => $req->status])
        @can('edit-request', $req)
            <a class="btn secondary" data-modal href="{{ route('requests.edit', $req) }}">Edit</a>
            <form method="POST" action="{{ route('requests.submit', $req) }}" class="inline-form">@csrf<button class="btn">Submit</button></form>
        @endcan
        @if(auth()->id() === $req->requester_id && $req->status->isRejected())
            <form method="POST" action="{{ route('requests.revise', $req) }}" class="inline-form">@csrf<button class="btn gold">Revise &amp; resubmit</button></form>
        @endif
        @if((auth()->id() === $req->requester_id || auth()->user()->isAdmin()) && in_array($req->status, [\App\Enums\RequestStatus::Draft, \App\Enums\RequestStatus::Submitted]))
            <form method="POST" action="{{ route('requests.cancel', $req) }}" class="inline-form">@csrf<button class="btn secondary">Cancel request</button></form>
        @endif
        @can('issue-request', $req)<a class="btn" data-modal href="{{ route('issues.create', $req) }}">Issue materials</a>@endcan
    </div>
</div>

<div class="grid grid-2">
    <div class="card"><h2>Request</h2><dl class="meta">
        <dt>Purpose</dt><dd>{{ $req->purpose }}</dd>
        <dt>Needed on</dt><dd>{{ $req->needed_on?->format('d M Y') ?? '-' }}</dd>
        <dt>Submitted</dt><dd>{{ $req->submitted_at?->format('d M Y H:i') ?? 'not yet' }}</dd>
        <dt>PM step</dt><dd>{{ $req->projectManager?->name ?? ($req->project->manager?->name ?? '-') }}@if($req->submitted_at && $wf->needsSubstitutePm($req)) <small class="muted">(requester is the PM, so Owner/Admin reviews)</small>@endif</dd>
    </dl></div>
    <div class="card"><h2>Decision history</h2>
        <ul class="timeline">
        @forelse($req->approvals as $a)
            <li class="{{ $a->decision }}"><strong>{{ $a->step === 'pm' ? 'PM' : 'Overall' }} {{ $a->decision }}</strong> by {{ $a->approver->name }}@if($a->was_substitute) <small>(substitute)</small>@endif
                <span class="muted">· v{{ $a->version }}</span><div class="when">{{ $a->decided_at->format('d M Y H:i') }}</div>
                @if($a->reason)<div>"{{ $a->reason }}"</div>@endif</li>
        @empty <li>No decisions yet.</li> @endforelse
        </ul>
    </div>
</div>

@php($deciding = $canPm || $canOverall)
@if($deciding)
<form method="POST" action="{{ $canPm ? route('requests.pm-decision', $req) : route('requests.overall-decision', $req) }}">
@csrf
@endif
<div class="card"><h2>Items</h2><div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">PM approved</th><th class="num">Final approved</th><th class="num">Issued</th><th class="num">Outstanding</th><th class="num">In store now</th>@if($deciding)<th class="num">{{ $canPm ? 'Approve qty' : 'Final qty' }}</th>@endif</tr></thead>
    <tbody>@foreach($req->lines as $l)
        <tr><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small>@if($l->notes)<br><small class="muted">{{ $l->notes }}</small>@endif</td>
            <td class="num">{{ qty($l->requested_qty) }}</td>
            <td class="num">{{ $l->pm_approved_qty !== null ? qty($l->pm_approved_qty) : '-' }}</td>
            <td class="num">{{ $l->final_approved_qty !== null ? qty($l->final_approved_qty) : '-' }}</td>
            <td class="num">{{ qty($l->issued_qty) }}</td>
            <td class="num">{{ qty($l->outstanding()) }}</td>
            <td class="num">{{ qty($l->item->onHand()) }}</td>
            @if($deciding)
                @php($max = $canPm ? $l->requested_qty : $l->pm_approved_qty)
                <td class="num"><input type="number" name="qty[{{ $l->id }}]" step="0.001" min="0" max="{{ $max + 0 }}" value="{{ old('qty.'.$l->id, $max + 0) }}"></td>
            @endif
        </tr>
    @endforeach</tbody>
</table></div></div>
@if($deciding)
<div class="card">
    <h2>{{ $canPm ? 'Project Manager decision' : 'Overall Manager decision' }}</h2>
    <p class="hint">You may reduce quantities but not increase them. Approving does not move stock. Rejecting needs a reason and ends this version; the requester can revise and resubmit.</p>
    <div class="field"><label>Comment / reason</label><textarea name="reason" maxlength="1000">{{ old('reason') }}</textarea></div>
    <div class="actions"><button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve</button><button class="btn danger" name="decision" value="rejected" data-confirm="Reject this document? The person who raised it will be notified." data-confirm-ok="Yes, reject"><i class="fas fa-ban"></i> Reject</button></div>
</div>
</form>
@endif

@if($req->issues->isNotEmpty())
<div class="card"><h2>Issue vouchers</h2><table>
    <thead><tr><th>Voucher</th><th>Issued</th><th>Recipient</th><th>Acknowledged</th></tr></thead>
    <tbody>@foreach($req->issues as $i)<tr><td><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }}</a></td><td>{{ $i->issued_at->format('d M Y H:i') }}</td><td>{{ $i->recipient_name }}</td>
        <td>@if($i->acknowledgment)<span class="badge approved">Yes</span>@else<span class="badge pending">Pending</span>@endif</td></tr>@endforeach</tbody>
</table></div>
@endif
@endsection
