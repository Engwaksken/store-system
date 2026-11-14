@extends('layouts.app')
@section('title', $count->count_no)
@section('content')
@php($editable = Gate::allows('edit-count', $count))
<div class="page-head"><div><h1>Stock count {{ $count->count_no }} <span class="badge {{ $count->tone() }}">{{ $count->statusLabel() }}</span></h1>
    <p>{{ $count->store->label() }} · opened {{ $count->created_at->format('d M Y H:i') }} by {{ $count->counter->name }}@if($count->notes) · {{ $count->notes }}@endif</p></div>
    <button class="btn secondary no-print" onclick="window.print()">Print count sheet</button></div>

@if($count->decided_at)
    <div class="alert {{ $count->status === 'approved' ? 'ok' : 'bad' }}">{{ $count->statusLabel() }} by {{ $count->decider->name }} on {{ $count->decided_at->format('d M Y H:i') }}@if($count->decision_reason): {{ $count->decision_reason }}@endif
        @if($count->adjustments->isNotEmpty()) · {{ $count->adjustments->count() }} adjustment(s) posted ({{ $count->adjustments->pluck('adjustment_no')->implode(', ') }})@endif</div>
@endif

<form method="POST" action="{{ route('counts.update', $count) }}" class="card">
    @csrf @method('PUT')
    <div class="table-wrap"><table>
        <thead><tr><th>SKU</th><th>Item</th><th>Unit</th><th class="num">Book qty</th><th class="num">Counted</th><th class="num">Variance</th><th>Reason (required if variance)</th></tr></thead>
        <tbody>@foreach($count->lines->sortBy(fn ($l) => $l->item->name) as $l)
            @php($v = $l->variance())
            <tr @class(['low' => $v !== null && (float) $v != 0])><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ qty($l->system_qty) }}</td>
                @if($editable)
                    <td class="num"><input type="number" step="0.001" min="0" name="lines[{{ $l->id }}][counted_qty]" value="{{ old("lines.{$l->id}.counted_qty", $l->counted_qty === null ? '' : (float) $l->counted_qty) }}" aria-label="Counted {{ $l->item->sku }}"></td>
                    <td class="num">{{ $v === null ? '' : ($v > 0 ? '+' : '').qty($v) }}</td>
                    <td><input type="text" name="lines[{{ $l->id }}][reason]" value="{{ old("lines.{$l->id}.reason", $l->reason) }}" aria-label="Reason {{ $l->item->sku }}"></td>
                @else
                    <td class="num">{{ $l->counted_qty === null ? '-' : qty($l->counted_qty) }}</td><td class="num">{{ $v === null ? '' : ($v > 0 ? '+' : '').qty($v) }}</td><td>{{ $l->reason }}</td>
                @endif</tr>
        @endforeach</tbody>
    </table></div>
    @if($editable)
        <p class="hint">Save as often as you like. Submitting locks the sheet and sends it for approval.</p>
        <div class="actions"><button class="btn secondary" name="action" value="save">Save counts</button><button class="btn" name="action" value="submit">Submit for approval</button></div>
    @endif
    <div class="signatures print-only"><div>Counted by</div><div>Witness</div><div>Approved by</div></div>
</form>

@can('decide-count', $count)
<form method="POST" action="{{ route('counts.decide', $count) }}" class="card no-print" style="max-width:640px">
    @csrf
    <h2>Approve or reject</h2>
    <p class="muted">Approving posts each variance as a stock adjustment (counted - book) on {{ $count->store->name }}.</p>
    <div class="field"><label for="reason">Comment (required to reject)</label><input id="reason" type="text" name="reason"></div>
    <div class="actions"><button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve &amp; post</button><button class="btn danger" name="decision" value="rejected" data-confirm="Reject this document? The person who raised it will be notified." data-confirm-ok="Yes, reject"><i class="fas fa-ban"></i> Reject</button></div>
</form>
@endcan
@endsection
