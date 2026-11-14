@extends('layouts.app')
@section('title', $receipt->receipt_no)
@section('content')
<div class="page-head"><div><h1>{{ $receipt->receipt_no }}</h1><p>{{ ucfirst($receipt->type) }} · {{ $receipt->received_on->format('d M Y') }} · recorded by {{ $receipt->receiver->name }}</p></div>
    <div class="actions no-print"><button type="button" class="btn secondary" data-print><i class="fas fa-print"></i> Print</button><a class="btn secondary" href="{{ route('receipts.pdf', $receipt) }}">PDF</a></div></div>
<div class="no-print">
<x-stats :cards="[
    ['Lines', number_format($receipt->lines->count()), 'fa-list', 'navy'],
    ['Total value ('.currency().')', ugx($receipt->total_value), 'fa-sack-dollar'],
    ['Type', ucfirst($receipt->type), 'fa-truck-ramp-box', 'gold'],
    ['Received on', $receipt->received_on->format('d M Y'), 'fa-calendar-day'],
]" />
</div>
<x-tabs id="receipt-show">
    <x-tab title="Lines" icon="fa-list" :count="$receipt->lines->count()" name="lines">
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
            <tbody>@foreach($receipt->lines as $l)<tr><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->unit_cost) }}</td><td class="num">{{ ugx($l->line_total) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td colspan="5">Total</td><td class="num">{{ ugx($receipt->total_value) }}</td></tr></tfoot>
        </table></div>
    </x-tab>
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta"><dt>Store</dt><dd>{{ $receipt->store->label() }}</dd>@if($receipt->purchase_order_id)<dt>Purchase order</dt><dd><a href="{{ route('pos.show', $receipt->purchase_order_id) }}">{{ $receipt->purchaseOrder->po_no }}</a></dd>@endif<dt>Supplier</dt><dd>{{ $receipt->supplier_name ?: '-' }}</dd><dt>Reference</dt><dd>{{ $receipt->reference ?: '-' }}</dd><dt>Notes</dt><dd>{{ $receipt->notes ?: '-' }}</dd></dl>
    </x-tab>
</x-tabs>
@endsection
