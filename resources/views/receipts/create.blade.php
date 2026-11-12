@extends('layouts.app')
@section('title', $type === 'opening' ? 'Opening balances' : 'Stock receipt')
@section('content')
<div class="page-head"><div><h1>{{ $type === 'opening' ? 'Opening balances' : 'Record stock receipt' }}</h1>
    <p>{{ $type === 'opening' ? 'Only for items with no stock history yet. Enter the physical count and unit cost.' : 'Goods received into the central store. This increases stock and updates the average cost.' }}</p></div></div>
<form method="POST" action="{{ route('receipts.store') }}" class="card">
    @csrf <input type="hidden" name="type" value="{{ $type }}">
    <div class="form-grid">
        <div class="field"><label>Date received</label><input type="date" name="received_on" value="{{ old('received_on', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        @if($type === 'purchase')
            <div class="field"><label>Supplier</label><input type="text" name="supplier_name" value="{{ old('supplier_name') }}" required></div>
            <div class="field"><label>Reference (invoice / delivery note)</label><input type="text" name="reference" value="{{ old('reference') }}"></div>
        @endif
        <div class="field"><label>Notes</label><input type="text" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Unit cost (UGX)</th></tr></thead>
        <tbody>@for($n = 0; $n < max(8, count(old('lines', []))); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]"><option value="">—</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(old("lines.$n.item_id") == $i->id)>{{ $i->sku }} – {{ $i->name }} ({{ $i->unit->abbreviation }})</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ old("lines.$n.qty") }}"></td>
                <td><input type="number" step="0.01" min="0" name="lines[{{ $n }}][unit_cost]" value="{{ old("lines.$n.unit_cost") }}"></td></tr>
        @endfor</tbody>
    </table></div>
    <p class="hint">Leave unused rows empty. Each item may appear once.</p>
    <button class="btn">Post to stock</button>
</form>
@endsection
