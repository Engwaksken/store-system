@extends('layouts.app')
@section('title', $transfer->transfer_no)
@section('content')
<div class="page-head"><div><h1>Transfer {{ $transfer->transfer_no }} <span class="badge {{ $transfer->tone() }}">{{ $transfer->statusLabel() }}</span></h1>
    <p>{{ $transfer->fromStore->label() }} <i class="fas fa-arrow-right"></i> {{ $transfer->toStore->label() }}</p></div>
    <button type="button" class="btn secondary no-print" data-print><i class="fas fa-print"></i> Print delivery note</button></div>
<div class="card"><dl class="meta">
    <dt>Dispatched</dt><dd>{{ $transfer->dispatched_at->format('d M Y H:i') }} by {{ $transfer->dispatcher->name }}</dd>
    @if($transfer->notes)<dt>Notes</dt><dd>{{ $transfer->notes }}</dd>@endif
    @if($transfer->received_at)<dt>{{ $transfer->status === 'cancelled' ? 'Cancelled' : 'Received' }}</dt><dd>{{ $transfer->received_at->format('d M Y H:i') }} by {{ $transfer->receiver?->name }}</dd>@endif
    @if($transfer->receive_notes)<dt>Remarks</dt><dd>{{ $transfer->receive_notes }}</dd>@endif
</dl></div>

@can('receive', $transfer)
<form method="POST" action="{{ route('transfers.receive', $transfer) }}" class="card">
    @csrf
    <h2>Confirm receipt</h2>
    <p class="muted">Count what arrived. Any shortfall needs a reason and is written off; it does not come back to the source store.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>SKU</th><th>Item</th><th class="num">Dispatched</th><th class="num">Received</th><th>Shortfall reason</th></tr></thead>
        <tbody>@foreach($transfer->lines as $l)
            <tr><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td><td class="num">{{ qty($l->qty) }}</td>
                <td class="num"><input type="number" step="0.001" min="0" max="{{ $l->qty }}" name="received[{{ $l->id }}]" value="{{ old('received.'.$l->id, (float) $l->qty) }}" required aria-label="Received {{ $l->item->sku }}"></td>
                <td><input type="text" name="reason[{{ $l->id }}]" value="{{ old('reason.'.$l->id) }}" aria-label="Shortfall reason {{ $l->item->sku }}"></td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="field"><label for="receive_notes">Remarks</label><input id="receive_notes" type="text" name="receive_notes" value="{{ old('receive_notes') }}"></div>
    <button class="btn">Confirm received quantities</button>
</form>
@else
<div class="card table-wrap"><table>
    <thead><tr><th>SKU</th><th>Item</th><th class="num">Dispatched</th><th class="num">Received</th><th class="num">Shortfall</th><th>Reason</th>@can('view-stock-value')<th class="num">Unit cost</th>@endcan</tr></thead>
    <tbody>@foreach($transfer->lines as $l)
        <tr @class(['low' => (float) $l->shortfall() > 0])><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td><td class="num">{{ qty($l->qty) }}</td>
            <td class="num">{{ $l->received_qty === null ? '-' : qty($l->received_qty) }}</td><td class="num">{{ (float) $l->shortfall() > 0 ? qty($l->shortfall()) : '' }}</td><td>{{ $l->shortfall_reason }}</td>
            @can('view-stock-value')<td class="num">{{ ugx($l->unit_cost) }}</td>@endcan</tr>
    @endforeach</tbody>
</table>
@if($transfer->status === 'in_transit')<p class="hint">Waiting for a storekeeper at {{ $transfer->toStore->name }} (not the dispatcher) to confirm receipt.</p>@endif
<div class="signatures print-only"><div>Dispatched by</div><div>Driver / carrier</div><div>Received by</div></div>
</div>
@endcan

@can('cancel', $transfer)
<form method="POST" action="{{ route('transfers.cancel', $transfer) }}" class="card no-print" style="max-width:640px">
    @csrf
    <h2>Cancel transfer</h2>
    <p class="muted">Only while in transit. All quantities go back into {{ $transfer->fromStore->name }}.</p>
    <div class="field"><label for="cancel_reason">Reason</label><input id="cancel_reason" type="text" name="cancel_reason" required minlength="5"></div>
    <button class="btn danger" data-confirm="Cancel this transfer? The stock goes back to the source store." data-confirm-ok="Yes, cancel transfer"><i class="fas fa-ban"></i> Cancel transfer</button>
</form>
@endcan
@endsection
