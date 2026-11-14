@extends('layouts.app')
@section('title', 'Stock adjustment')
@section('content')
<div class="page-head"><div><h1>Stock adjustment</h1><p>Use for counted differences, damage or loss. Positive adds stock, negative removes it. Stock can never go below zero.</p></div></div>
<form method="GET" class="card filters" style="max-width:640px"><label for="store_pick">Store</label>
    <select id="store_pick" name="store_id" onchange="this.form.submit()">@foreach($stores as $s)<option value="{{ $s->id }}" @selected($storeId === $s->id)>{{ $s->label() }}</option>@endforeach</select></form>
<form method="POST" action="{{ route('adjustments.store') }}" class="card" style="max-width:640px">
    @csrf <input type="hidden" name="store_id" value="{{ $storeId }}">
    <div class="field"><label>Item</label><select name="item_id" required><option value="">Select</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(old('item_id', request('item_id')) == $i->id)>{{ $i->sku }} - {{ $i->name }} (on hand here {{ qty($i->onHand($storeId)) }} {{ $i->unit->abbreviation }})</option>@endforeach</select></div>
    <div class="field"><label>Quantity change (+ / -)</label><input type="number" step="0.001" name="qty_delta" value="{{ old('qty_delta') }}" required></div>
    <div class="field"><label>Reason (required, audited)</label><textarea name="reason" required minlength="5">{{ old('reason') }}</textarea></div>
    <button class="btn">Post adjustment</button>
</form>
@endsection
