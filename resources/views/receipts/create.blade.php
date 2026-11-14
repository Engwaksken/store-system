@extends('layouts.app')
@section('title', $type === 'opening' ? 'Opening balances' : 'Stock receipt')
@section('content')
<div class="page-head"><div><h1>{{ $type === 'opening' ? 'Opening balances' : 'Record stock receipt' }}</h1>
    <p>{{ $type === 'opening' ? 'Only for items with no stock history yet. Enter the physical count and unit cost.' : 'Goods received into a store. This increases stock and updates the average cost.' }}</p></div></div>
@if($type === 'opening')
<x-tabs id="opening-balances">
<x-tab title="Enter by hand" icon="fa-keyboard" name="manual">
@include('receipts.partials.receipt-form', ['inTabs' => true])
</x-tab>
<x-tab title="Import from CSV" icon="fa-file-import" name="import">
<form method="POST" action="{{ route('receipts.opening-import') }}" enctype="multipart/form-data">
    @csrf
    <p class="muted">1. Download the template for a store: @foreach($stores as $s)<a href="{{ route('receipts.opening-template', ['store_id' => $s->id]) }}">{{ $s->code }}</a>@if(! $loop->last), @endif @endforeach. It lists every item with no stock history in that store. 2. Fill in <code>qty</code> and <code>unit_cost</code> in Excel and save as CSV. 3. Upload it here. If any row is wrong, nothing is imported and the errors are listed.</p>
    <div class="form-grid">
        <div class="field"><label for="import_store_id">Store</label><select id="import_store_id" name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id', request('store_id')) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label>Count date</label><input type="date" name="received_on" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label>CSV file</label><input type="file" name="file" accept=".csv,text/csv" required></div>
    </div>
    <button class="btn secondary"><i class="fas fa-file-import"></i> Import opening balances</button>
</form>
</x-tab>
</x-tabs>
@else
@include('receipts.partials.receipt-form')
@endif
@endsection
