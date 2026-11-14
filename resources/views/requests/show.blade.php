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

@php($deciding = $canPm || $canOverall)
<x-stats :cards="[
    ['Items', number_format($req->lines->count()), 'fa-list', 'navy'],
    ['Lines outstanding', number_format($req->lines->filter(fn ($l) => (float) $l->outstanding() > 0)->count()), 'fa-hourglass-half', 'gold'],
    ['Issue vouchers', number_format($req->issues->count()), 'fa-dolly'],
    ['Needed on', $req->needed_on?->format('d M Y') ?? '-', 'fa-calendar-day'],
]" />

@if($deciding)
<form method="POST" action="{{ $canPm ? route('requests.pm-decision', $req) : route('requests.overall-decision', $req) }}">
@csrf
<div class="card">
    <h2>{{ $canPm ? 'Project Manager decision' : 'Overall Manager decision' }}</h2>
    @include('requests.partials.lines')
    <p class="hint">You may reduce quantities but not increase them. Approving does not move stock. Rejecting needs a reason and ends this version; the requester can revise and resubmit.</p>
    <div class="field"><label>Comment / reason</label><textarea name="reason" maxlength="1000">{{ old('reason') }}</textarea></div>
    <div class="actions"><button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve</button><button class="btn danger" name="decision" value="rejected" data-confirm="Reject this document? The person who raised it will be notified." data-confirm-ok="Yes, reject"><i class="fas fa-ban"></i> Reject</button></div>
</div>
</form>
@endif

<x-tabs id="request-show">
    <x-tab title="Items" icon="fa-list" :count="$req->lines->count()" name="items">
        @unless($deciding)
            @include('requests.partials.lines')
        @endunless
    </x-tab>
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta">
            <dt>Purpose</dt><dd>{{ $req->purpose }}</dd>
            <dt>Needed on</dt><dd>{{ $req->needed_on?->format('d M Y') ?? '-' }}</dd>
            <dt>Submitted</dt><dd>{{ $req->submitted_at?->format('d M Y H:i') ?? 'not yet' }}</dd>
            <dt>PM step</dt><dd>{{ $req->projectManager?->name ?? ($req->project->manager?->name ?? '-') }}@if($req->submitted_at && $wf->needsSubstitutePm($req)) <small class="muted">(requester is the PM, so Owner/Admin reviews)</small>@endif</dd>
        </dl>
    </x-tab>
    <x-tab title="Decision history" icon="fa-clock-rotate-left" :count="$req->approvals->count()" name="history">
        <ul class="timeline">
        @forelse($req->approvals as $a)
            <li class="{{ $a->decision }}"><strong>{{ $a->step === 'pm' ? 'PM' : 'Overall' }} {{ $a->decision }}</strong> by {{ $a->approver->name }}@if($a->was_substitute) <small>(substitute)</small>@endif
                <span class="muted">· v{{ $a->version }}</span><div class="when">{{ $a->decided_at->format('d M Y H:i') }}</div>
                @if($a->reason)<div>"{{ $a->reason }}"</div>@endif</li>
        @empty <li>No decisions yet.</li> @endforelse
        </ul>
    </x-tab>
    <x-tab title="Issue vouchers" icon="fa-dolly" :count="$req->issues->count()" name="issues">
        @if($req->issues->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Voucher</th><th>Issued</th><th>Recipient</th><th>Acknowledged</th></tr></thead>
            <tbody>@foreach($req->issues as $i)<tr><td><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }}</a></td><td>{{ $i->issued_at->format('d M Y H:i') }}</td><td>{{ $i->recipient_name }}</td>
                <td>@if($i->acknowledgment)<span class="badge approved">Yes</span>@else<span class="badge pending">Pending</span>@endif</td></tr>@endforeach</tbody>
        </table></div>
        @endif
    </x-tab>
</x-tabs>
@endsection
