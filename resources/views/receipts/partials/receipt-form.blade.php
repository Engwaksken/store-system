{{-- Stock receipt / opening balance entry form (used on its own, or as the first tab for opening balances). --}}
<form method="POST" action="{{ route('receipts.store') }}" @class(['card' => ! ($inTabs ?? false)])>
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
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Unit cost ({{ currency() }})</th></tr></thead>
        <tbody>@for($n = 0; $n < max(4, count(old('lines', []))); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]"><option value="">None</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(old("lines.$n.item_id") == $i->id)>{{ $i->sku }} - {{ $i->name }} ({{ $i->unit->abbreviation }})</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ old("lines.$n.qty") }}"></td>
                <td><input type="number" step="0.01" min="0" name="lines[{{ $n }}][unit_cost]" value="{{ old("lines.$n.unit_cost") }}"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#lines"><i class="fas fa-plus"></i> Add line</button></p>
    <p class="hint">Leave unused rows empty. Each item may appear once.</p>
    <button class="btn"><i class="fas fa-check"></i> Post to stock</button>
</form>
