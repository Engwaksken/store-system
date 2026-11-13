@extends('layouts.app')
@section('title', 'New purchase order')
@section('content')
<div class="page-head"><div><h1>New purchase order</h1>
    <p>@if($pr)From requisition <a href="{{ route('prs.show', $pr) }}">{{ $pr->pr_no }}</a>@if($quote) · selected quote from {{ $quote->supplier->name }} (prices must match the quote)@endif.@else Saving sends the PO for approval.@endif</p></div></div>
<form method="POST" action="{{ route('pos.store') }}" class="card">
    @csrf
    @if($pr)<input type="hidden" name="purchase_requisition_id" value="{{ $pr->id }}">@endif
    <div class="form-grid">
        <div class="field"><label for="supplier_id">Supplier</label><select id="supplier_id" name="supplier_id" required><option value="">—</option>
            @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $quote?->supplier_id) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="store_id">Deliver to store</label><select id="store_id" name="store_id" required>
            @foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id', $pr?->store_id) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="project_id">Project (optional)</label><select id="project_id" name="project_id" @disabled($pr)><option value="">— Store stock —</option>
            @foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $pr?->project_id) == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach</select>
            @if($pr)<p class="hint">Taken from the requisition.</p>@endif</div>
        <div class="field"><label for="order_date">Order date</label><input id="order_date" type="date" name="order_date" value="{{ old('order_date', now()->format('Y-m-d')) }}" required></div>
        <div class="field"><label for="expected_on">Expected delivery</label><input id="expected_on" type="date" name="expected_on" value="{{ old('expected_on') }}"></div>
        <div class="field"><label for="terms">Terms</label><input id="terms" type="text" name="terms" value="{{ old('terms') }}" placeholder="e.g. Delivery to site, 30 days credit"></div>
    </div>
    @php($rows = old('lines', $prefill))
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Unit price (UGX)</th></tr></thead>
        <tbody>@for($n = 0; $n < max(3, count($rows)); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]" aria-label="Item"><option value="">—</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(($rows[$n]['item_id'] ?? null) == $i->id)>{{ $i->sku }} – {{ $i->name }} ({{ $i->unit->abbreviation }})</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ $rows[$n]['qty'] ?? '' }}" aria-label="Quantity"></td>
                <td><input type="number" step="0.01" min="0" name="lines[{{ $n }}][unit_price]" value="{{ $rows[$n]['unit_price'] ?? '' }}" aria-label="Unit price"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#lines">+ Add line</button></p>
    <div class="field"><label for="notes">Notes</label><input id="notes" type="text" name="notes" value="{{ old('notes') }}"></div>
    <button class="btn">Raise PO &amp; send for approval</button>
</form>
@include('partials.add-line')
@endsection
