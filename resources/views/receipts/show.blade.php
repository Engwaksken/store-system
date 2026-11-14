@extends('layouts.app')
@section('title', $receipt->receipt_no)
@section('content')
<div class="page-head"><div><h1>{{ $receipt->receipt_no }}</h1><p>{{ ucfirst($receipt->type) }} · {{ $receipt->received_on->format('d M Y') }} · recorded by {{ $receipt->receiver->name }}</p></div>
    <div class="actions no-print"><button class="btn secondary" onclick="window.print()">Print</button><a class="btn secondary" href="{{ route('receipts.pdf', $receipt) }}">PDF</a></div></div>
<div class="card"><dl class="meta"><dt>Store</dt><dd>{{ $receipt->store->label() }}</dd>@if($receipt->purchase_order_id)<dt>Purchase order</dt><dd><a href="{{ route('pos.show', $receipt->purchase_order_id) }}">{{ $receipt->purchaseOrder->po_no }}</a></dd>@endif<dt>Supplier</dt><dd>{{ $receipt->supplier_name ?: '-' }}</dd><dt>Reference</dt><dd>{{ $receipt->reference ?: '-' }}</dd><dt>Notes</dt><dd>{{ $receipt->notes ?: '-' }}</dd></dl></div>
<div class="card table-wrap"><table>
    <thead><tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
    <tbody>@foreach($receipt->lines as $l)<tr><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->unit_cost) }}</td><td class="num">{{ ugx($l->line_total) }}</td></tr>@endforeach</tbody>
    <tfoot><tr><td colspan="5">Total</td><td class="num">{{ ugx($receipt->total_value) }}</td></tr></tfoot>
</table></div>
@endsection
