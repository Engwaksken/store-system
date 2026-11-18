@extends('layouts.app')
@section('title', 'Issue '.$req->request_no)
@section('content')
<div class="page-head"><div><h1>Issue materials · {{ $req->request_no }}</h1><p>{{ $req->project->code }} · requested by {{ $req->requester->name }}. You can issue up to the outstanding approved quantity, limited by stock on hand.</p></div></div>
<form method="GET" class="card filters"><div class="grid grid-4"><div><label for="store_pick">Issue from store</label>
    <select id="store_pick" name="store_id" data-autosubmit>@foreach($stores as $s)<option value="{{ $s->id }}" @selected($storeId === $s->id)>{{ $s->label() }}</option>@endforeach</select></div></div></form>
<form method="POST" action="{{ route('issues.store', $req) }}" class="card">
    @csrf @include('partials.idem') <input type="hidden" name="store_id" value="{{ $storeId }}">
    <div class="table-wrap"><table>
        <thead><tr><th>Item</th><th class="num">Final approved</th><th class="num">Already issued</th><th class="num">Outstanding</th><th class="num">On hand (this store)</th><th class="num">Issue now</th></tr></thead>
        <tbody>@foreach($req->lines as $l)
            @php($out = (float) $l->outstanding())
            @php($max = min($out, (float) $l->item->onHand($storeId)))
            <tr @class(['low' => $out > 0 && $max < $out])><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td>
                <td class="num">{{ qty($l->final_approved_qty) }}</td><td class="num">{{ qty($l->issued_qty) }}</td><td class="num">{{ qty($out) }}</td><td class="num">{{ qty($l->item->onHand($storeId)) }}</td>
                <td class="num">@if($out > 0)<input type="number" step="0.001" min="0" max="{{ $max }}" name="qty[{{ $l->id }}]" value="{{ old('qty.'.$l->id, $max) }}">@else - @endif</td></tr>
        @endforeach</tbody>
    </table></div>
    @if($shortfall)
        <div class="alert warn"><i class="fas fa-truck-ramp-box"></i> <strong>Not enough stock for {{ count($shortfall) }} item(s).</strong>
            Issue what is in stock now. When you confirm, the balance is sent to the Overall Manager as a restock requisition
            (quantity = the larger of the balance not in stock and the item's minimum stock level):
            <ul>@foreach($shortfall as [$l, $balance, $have, $restockQty])<li>{{ $l->item->name }}: {{ qty($have) }} in this store, {{ qty($balance) }} short → restock {{ qty($restockQty) }} {{ $l->item->unit->abbreviation }}</li>@endforeach</ul>
            @if($openRestock->isNotEmpty())<span class="muted">Already requested: {{ $openRestock->pluck('pr_no')->implode(', ') }} (items on it are not asked for again).</span>@endif
        </div>
    @else
    <p class="hint">Highlighted rows have less stock than approved; the remainder stays outstanding for a later issue.</p>
    @endif
    <div class="form-grid">
        <div class="field"><label>Recipient name (person collecting)</label><input type="text" name="recipient_name" value="{{ old('recipient_name', $req->requester->name) }}" required></div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <button class="btn">Confirm issue &amp; create voucher</button>
</form>
@if($shortfall)
<form method="POST" action="{{ route('issues.restock', $req) }}" class="card modal-keep">@csrf <input type="hidden" name="store_id" value="{{ $storeId }}">
    <p class="muted">Nothing to issue yet? Send the shortfall to the Overall Manager for restocking without creating a voucher.</p>
    <button class="btn secondary" data-confirm="Send the shortfall to the Overall Manager as a restock requisition?" data-confirm-ok="Yes, send"><i class="fas fa-truck-ramp-box"></i> Request restock only</button>
</form>
@endif
@endsection
