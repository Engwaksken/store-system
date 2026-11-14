{{-- BOQ line fields, shared by the add and edit modals on the project controls page. $line is null when adding. --}}
@php($k = $line?->id ?? 'new')
<div class="form-grid">
    <div class="field"><label for="ref_{{ $k }}">Ref</label><input id="ref_{{ $k }}" type="text" name="ref" value="{{ $line?->ref }}" required maxlength="30"></div>
    <div class="field"><label for="cost_code_{{ $k }}">Cost code</label><select id="cost_code_{{ $k }}" name="cost_code_id" required>
        @foreach($costCodes as $cc)<option value="{{ $cc->id }}" @selected($line?->cost_code_id == $cc->id)>{{ $cc->label() }}</option>@endforeach</select></div>
</div>
<div class="field"><label for="description_{{ $k }}">Description</label><input id="description_{{ $k }}" type="text" name="description" value="{{ $line?->description }}" required maxlength="500"></div>
<div class="form-grid">
    <div class="field"><label for="unit_{{ $k }}">Unit</label><input id="unit_{{ $k }}" type="text" name="unit" value="{{ $line?->unit }}" required maxlength="20"></div>
    <div class="field"><label for="qty_{{ $k }}">Quantity</label><input id="qty_{{ $k }}" type="number" step="0.001" min="0" name="qty" value="{{ $line ? (float) $line->qty : '' }}" required></div>
    <div class="field"><label for="rate_{{ $k }}">Rate (UGX)</label><input id="rate_{{ $k }}" type="number" step="0.01" min="0" name="rate" value="{{ $line ? (float) $line->rate : '' }}" required></div>
    <div class="field"><label for="item_{{ $k }}">Catalogue item (optional)</label><select id="item_{{ $k }}" name="item_id"><option value="">None</option>
        @foreach($items as $i)<option value="{{ $i->id }}" @selected($line?->item_id == $i->id)>{{ $i->sku }} - {{ $i->name }}</option>@endforeach</select></div>
</div>
