@extends('layouts.app')
@section('title', $supplier->name)
@section('content')
<div class="page-head"><div><h1>{{ $supplier->name }} <small class="muted">{{ $supplier->code }}</small></h1></div>
    @can('manage-suppliers')<a class="btn secondary" data-modal href="{{ route('suppliers.edit', $supplier) }}"><i class="fas fa-pen"></i> Edit</a>@endcan</div>
<div class="card"><dl class="meta">
    <dt>Contact</dt><dd>{{ $supplier->contact_person ?: '-' }} {{ $supplier->phone }} {{ $supplier->email }}</dd>
    <dt>Address</dt><dd>{{ $supplier->address ?: '-' }}</dd><dt>TIN</dt><dd>{{ $supplier->tin ?: '-' }}</dd>
    <dt>Payment terms</dt><dd>{{ $supplier->payment_terms ?: '-' }}</dd>
</dl></div>
<div class="grid grid-2">
<div class="card table-wrap"><h2>Recent purchase orders</h2><table>
    <thead><tr><th>PO</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
    <tbody>@forelse($orders as $o)<tr><td><a href="{{ route('pos.show', $o) }}">{{ $o->po_no }}</a></td><td>{{ $o->order_date->format('d M Y') }}</td><td><span class="badge {{ $o->tone() }}">{{ $o->statusLabel() }}</span></td><td class="num">{{ ugx($o->total) }}</td></tr>
    @empty <tr><td colspan="4" class="empty">None.</td></tr>@endforelse</tbody></table></div>
@can('view-invoices')
<div class="card table-wrap"><h2>Recent invoices</h2><table>
    <thead><tr><th>Invoice</th><th>Date</th><th>Payment</th><th class="num">Balance</th></tr></thead>
    <tbody>@forelse($invoices as $i)<tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->supplier_invoice_no }}</a></td><td>{{ $i->invoice_date->format('d M Y') }}</td><td><span class="badge {{ $i->paymentTone() }}">{{ \App\Models\PurchaseInvoice::PAYMENT[$i->payment_status] }}</span></td><td class="num">{{ ugx($i->balance()) }}</td></tr>
    @empty <tr><td colspan="4" class="empty">None.</td></tr>@endforelse</tbody></table></div>
@endcan
</div>
@endsection
