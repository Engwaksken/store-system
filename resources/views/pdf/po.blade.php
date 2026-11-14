@extends('pdf.layout')
@section('title', $po->po_no)
@section('doc-head')<h1>PURCHASE ORDER</h1><strong>{{ $po->po_no }}</strong><br>{{ $po->order_date->format('d M Y') }}<br><span class="muted">{{ $po->statusLabel() }}</span>@endsection
@section('body')
<table style="width:100%"><tr>
    <td style="width:50%;vertical-align:top"><h2>Supplier</h2><strong>{{ $po->supplier->name }}</strong><br>{{ $po->supplier->address }}<br>{{ $po->supplier->phone }} {{ $po->supplier->email }}@if($po->supplier->tin)<br>TIN {{ $po->supplier->tin }}@endif</td>
    <td style="vertical-align:top"><h2>Deliver to</h2>{{ $po->store->name }}@if($po->store->location), {{ $po->store->location }}@endif
        @if($po->project)<br>Project: {{ $po->project->code }} · {{ $po->project->name }}@endif
        @if($po->expected_on)<br>Expected: {{ $po->expected_on->format('d M Y') }}@endif</td>
</tr></table>
<table class="grid">
    <thead><tr><th>#</th><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit price</th><th class="num">Amount ({{ currency() }})</th></tr></thead>
    <tbody>@foreach($po->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->unit_price) }}</td><td class="num">{{ ugx($l->line_total) }}</td></tr>@endforeach</tbody>
    <tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($po->total) }}</td></tr></tfoot>
</table>
@if($po->terms)<p><strong>Terms:</strong> {{ $po->terms }}</p>@endif
<p class="muted">Raised by {{ $po->creator->name }}@if($po->decided_at && $po->decider) · {{ $po->status === 'rejected' ? 'Rejected' : 'Approved' }} by {{ $po->decider->name }} on {{ $po->decided_at->format('d M Y') }}@endif</p>
<table class="sign"><tr><td>Prepared by (Procurement)</td><td>Approved by</td><td>Supplier acceptance</td></tr></table>
@endsection
