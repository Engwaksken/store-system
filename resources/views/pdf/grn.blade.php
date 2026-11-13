@extends('pdf.layout')
@section('title', $receipt->receipt_no)
@section('doc-head')<h1>{{ $receipt->type === 'opening' ? 'OPENING BALANCE' : 'GOODS RECEIVED NOTE' }}</h1><strong>{{ $receipt->receipt_no }}</strong><br>{{ $receipt->received_on->format('d M Y') }}@endsection
@section('body')
<table class="meta">
    <tr><td class="k">Store</td><td>{{ $receipt->store->name }}</td></tr>
    @if($receipt->supplier_name)<tr><td class="k">Supplier</td><td>{{ $receipt->supplier_name }}</td></tr>@endif
    @if($receipt->purchaseOrder)<tr><td class="k">Purchase order</td><td>{{ $receipt->purchaseOrder->po_no }}</td></tr>@endif
    @if($receipt->reference)<tr><td class="k">Delivery note / reference</td><td>{{ $receipt->reference }}</td></tr>@endif
    <tr><td class="k">Received by</td><td>{{ $receipt->receiver->name }}</td></tr>
    @if($receipt->notes)<tr><td class="k">Notes</td><td>{{ $receipt->notes }}</td></tr>@endif
</table>
<table class="grid">
    <thead><tr><th>#</th><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Amount (UGX)</th></tr></thead>
    <tbody>@foreach($receipt->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->unit_cost) }}</td><td class="num">{{ ugx($l->line_total) }}</td></tr>@endforeach</tbody>
    <tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($receipt->total_value) }}</td></tr></tfoot>
</table>
<table class="sign"><tr><td>Delivered by (supplier / driver)</td><td>Received by (storekeeper)</td><td>Checked by</td></tr></table>
@endsection
