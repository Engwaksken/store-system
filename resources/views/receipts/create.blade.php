@extends('layouts.app')
@section('title', $type === 'opening' ? 'Opening balances' : 'Stock receipt')
@section('content')
<div class="page-head"><div><h1>{{ $type === 'opening' ? 'Opening balances' : 'Record stock receipt' }}</h1>
    <p>{{ $type === 'opening' ? 'Only for items with no stock history yet. Enter the physical count and unit cost.' : 'Goods received into a store. This increases stock and updates the average cost.' }}</p></div></div>
<form method="POST" action="{{ route('receipts.store') }}" class="card">
    @csrf <input type="hidden" name="type" value="{{ $type }}"> @include('partials.idem')
    <div class="form-grid">
        <div class="field"><label for="store_id">Store</label><select id="store_id" name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id', request('store_id')) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label>Date received</label><input type="date" name="received_on" value="{{ old('received_on', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        @if($type === 'purchase')
            <div class="field"><label>Supplier</label><input type="text" name="supplier_name" value="{{ old('supplier_name') }}" required></div>
            <div class="field"><label>Reference (invoice / delivery note)</label><input type="text" name="reference" value="{{ old('reference') }}"></div>
        @endif
        <div class="field"><label>Notes</label><input type="text" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Unit cost (UGX)</th></tr></thead>
        <tbody>@for($n = 0; $n < max(4, count(old('lines', []))); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]"><option value="">—</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(old("lines.$n.item_id") == $i->id)>{{ $i->sku }} – {{ $i->name }} ({{ $i->unit->abbreviation }})</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ old("lines.$n.qty") }}"></td>
                <td><input type="number" step="0.01" min="0" name="lines[{{ $n }}][unit_cost]" value="{{ old("lines.$n.unit_cost") }}"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#lines">+ Add line</button></p>
    <p class="hint">Leave unused rows empty. Each item may appear once.</p>
    <button class="btn">Post to stock</button>
</form>
@if($type === 'opening')
<form method="POST" action="{{ route('receipts.opening-import') }}" enctype="multipart/form-data" class="card">
    @csrf
    <h2>Or import from a spreadsheet (CSV)</h2>
    <p class="muted">1. Download the template for a store: @foreach($stores as $s)<a href="{{ route('receipts.opening-template', ['store_id' => $s->id]) }}">{{ $s->code }}</a>@if(! $loop->last), @endif @endforeach. It lists every item with no stock history in that store. 2. Fill in <code>qty</code> and <code>unit_cost</code> in Excel and save as CSV. 3. Upload it here. If any row is wrong, nothing is imported and the errors are listed.</p>
    <div class="form-grid">
        <div class="field"><label for="import_store_id">Store</label><select id="import_store_id" name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id', request('store_id')) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label>Count date</label><input type="date" name="received_on" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label>CSV file</label><input type="file" name="file" accept=".csv,text/csv" required></div>
    </div>
    <button class="btn secondary">Import opening balances</button>
</form>
@endif
@include('partials.add-line')
@endsection
