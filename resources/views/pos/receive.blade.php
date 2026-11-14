@extends('layouts.app')
@section('title', 'Receive '.$po->po_no)
@section('content')
<div class="page-head"><div><h1>Goods received · {{ $po->po_no }}</h1><p>{{ $po->supplier->name }} <i class="fas fa-arrow-right"></i> {{ $po->store->label() }}. Enter what was delivered now; you cannot receive more than is still on order. Stock is valued at the PO price.</p></div></div>
<form method="POST" action="{{ route('pos.receive.store', $po) }}" class="card">
    @csrf @include('partials.idem')
    <div class="table-wrap"><table>
        <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Received so far</th><th class="num">Outstanding</th><th class="num">Received now</th></tr></thead>
        <tbody>@foreach($po->lines as $l)
            <tr><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td><td class="num">{{ qty($l->qty) }}</td><td class="num">{{ qty($l->received_qty) }}</td><td class="num">{{ qty($l->outstanding()) }}</td>
                <td class="num">@if((float) $l->outstanding() > 0)<input type="number" step="0.001" min="0" max="{{ $l->outstanding() }}" name="qty[{{ $l->id }}]" value="{{ old('qty.'.$l->id) }}" aria-label="Received {{ $l->item->sku }}">@else - @endif</td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="form-grid">
        <div class="field"><label for="received_on">Date received</label><input id="received_on" type="date" name="received_on" value="{{ old('received_on', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label for="reference">Delivery note no.</label><input id="reference" type="text" name="reference" value="{{ old('reference') }}"></div>
        <div class="field"><label for="notes">Notes (condition, vehicle)</label><input id="notes" type="text" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <button class="btn">Post GRN</button>
</form>
@endsection
