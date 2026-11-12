@extends('layouts.app')
@section('title', $item->exists ? 'Edit item' : 'New item')
@section('content')
<div class="page-head"><h1>{{ $item->exists ? 'Edit '.$item->sku : 'New item' }}</h1></div>
@if($categories->isEmpty() || $units->isEmpty())<div class="alert warn">Create at least one category and unit first under <a href="{{ route('catalog.setup') }}">Categories &amp; units</a>.</div>@endif
<form method="POST" action="{{ $item->exists ? route('items.update', $item) : route('items.store') }}" class="card" style="max-width:720px">
    @csrf @if($item->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>SKU / code</label><input type="text" name="sku" value="{{ old('sku', $item->sku) }}" required maxlength="40"></div>
        <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $item->name) }}" required></div>
        <div class="field"><label>Category</label><select name="category_id" required>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $item->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label>Unit</label><select name="unit_id" required>@foreach($units as $u)<option value="{{ $u->id }}" @selected(old('unit_id', $item->unit_id) == $u->id)>{{ $u->name }} ({{ $u->abbreviation }})</option>@endforeach</select></div>
        <div class="field"><label>Minimum (reorder) level</label><input type="number" step="0.001" min="0" name="min_level" value="{{ old('min_level', $item->min_level ?? 0) }}" required></div>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))> Active (can be requested)</label></div>
    </div>
    <p class="hint">Quantities and costs are never edited here. Use opening balances, receipts or adjustments, so every change is recorded in the ledger.</p>
    <button class="btn">Save item</button>
</form>
@endsection
