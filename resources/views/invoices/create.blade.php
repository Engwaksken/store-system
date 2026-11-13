@extends('layouts.app')
@section('title', 'Invoice for '.$po->po_no)
@section('content')
<div class="page-head"><div><h1>Record supplier invoice · {{ $po->po_no }}</h1><p>{{ $po->supplier->name }}. Enter the invoice exactly as printed. Lines that differ from the PO price, or bill more than was received, are flagged as a match exception and need approval before payment.</p></div></div>
<form method="POST" action="{{ route('invoices.store', $po) }}" enctype="multipart/form-data" class="card">
    @csrf
    <div class="form-grid">
        <div class="field"><label for="supplier_invoice_no">Supplier invoice no.</label><input id="supplier_invoice_no" type="text" name="supplier_invoice_no" value="{{ old('supplier_invoice_no') }}" required></div>
        <div class="field"><label for="invoice_date">Invoice date</label><input id="invoice_date" type="date" name="invoice_date" value="{{ old('invoice_date', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label for="due_date">Due date</label><input id="due_date" type="date" name="due_date" value="{{ old('due_date') }}"></div>
        <div class="field"><label for="tax">Tax / VAT (UGX)</label><input id="tax" type="number" step="0.01" min="0" name="tax" value="{{ old('tax', 0) }}"></div>
        <div class="field"><label for="attachment">Scan (PDF/JPG/PNG, max 5 MB)</label><input id="attachment" type="file" name="attachment" accept=".pdf,image/png,image/jpeg"></div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Item</th><th class="num">PO price</th><th class="num">Received</th><th class="num">Already invoiced</th><th class="num">Invoiced qty</th><th class="num">Invoiced unit price</th></tr></thead>
        <tbody>@foreach($po->lines as $l)
            @php($open = max(0, (float) $l->received_qty - (float) $l->invoiced_qty))
            <tr><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td><td class="num">{{ ugx($l->unit_price) }}</td><td class="num">{{ qty($l->received_qty) }}</td><td class="num">{{ qty($l->invoiced_qty) }}</td>
                <td class="num"><input type="number" step="0.001" min="0" name="lines[{{ $l->id }}][qty]" value="{{ old("lines.{$l->id}.qty", $open ?: '') }}" aria-label="Invoiced qty {{ $l->item->sku }}"></td>
                <td class="num"><input type="number" step="0.0001" min="0" name="lines[{{ $l->id }}][unit_price]" value="{{ old("lines.{$l->id}.unit_price", (float) $l->unit_price) }}" aria-label="Invoiced price {{ $l->item->sku }}"></td></tr>
        @endforeach</tbody>
    </table></div>
    <button class="btn">Record invoice</button>
</form>
@endsection
