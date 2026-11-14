@extends('layouts.app')
@section('title', 'New transfer')
@section('content')
<div class="page-head"><div><h1>Dispatch stock transfer</h1><p>Quantities leave the source store now and show as in transit until the destination confirms receipt.</p></div></div>
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="from_pick">Source store (shows its balances)</label><select id="from_pick" name="from_store_id" data-autosubmit>
        @foreach($fromStores as $s)<option value="{{ $s->id }}" @selected($fromId === $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
</div></form>
<form method="POST" action="{{ route('transfers.store') }}" class="card">
    @csrf @include('partials.idem')
    <input type="hidden" name="from_store_id" value="{{ $fromId }}">
    <div class="form-grid">
        <div class="field"><label for="to_store_id">Destination store</label><select id="to_store_id" name="to_store_id" required><option value="">Select</option>
            @foreach($toStores->where('id', '!=', $fromId) as $s)<option value="{{ $s->id }}" @selected(old('to_store_id') == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="notes">Notes (vehicle, driver, reason)</label><input id="notes" type="text" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Item (on hand at source)</th><th class="num">Quantity</th></tr></thead>
        <tbody>@for($n = 0; $n < max(3, count(old('lines', []))); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]" aria-label="Item"><option value="">None</option>@foreach($items as $i)@if((float) $i->onHand($fromId) > 0)<option value="{{ $i->id }}" @selected(old("lines.$n.item_id") == $i->id)>{{ $i->sku }} - {{ $i->name }} ({{ qty($i->onHand($fromId)) }} {{ $i->unit->abbreviation }})</option>@endif @endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ old("lines.$n.qty") }}" aria-label="Quantity"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#lines"><i class="fas fa-plus"></i> Add line</button></p>
    <button class="btn">Dispatch transfer</button>
</form>
@endsection
