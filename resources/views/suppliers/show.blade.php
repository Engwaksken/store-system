@extends('layouts.app')
@section('title', $supplier->name)
@section('content')
<div class="page-head"><div><h1>{{ $supplier->name }} <small class="muted">{{ $supplier->code }}</small></h1></div>
    @can('manage-suppliers')<a class="btn secondary" data-modal href="{{ route('suppliers.edit', $supplier) }}"><i class="fas fa-pen"></i> Edit</a>@endcan</div>
<x-stats :cards="[
    ['Recent purchase orders', number_format($orders->count()), 'fa-file-invoice', 'navy'],
    ['PO value ('.currency().')', ugx($orders->reduce(fn ($c, $o) => bcadd($c, (string) $o->total, 2), '0')), 'fa-sack-dollar'],
    auth()->user()->can('view-invoices') ? ['Recent invoices', number_format($invoices->count()), 'fa-file-invoice-dollar', 'gold'] : null,
    auth()->user()->can('view-invoices') ? ['Invoice balance ('.currency().')', ugx($invoices->reduce(fn ($c, $i) => bcadd($c, (string) $i->balance(), 2), '0')), 'fa-scale-unbalanced'] : null,
]" />
<x-tabs id="supplier-show">
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta">
            <dt>Contact</dt><dd>{{ $supplier->contact_person ?: '-' }} {{ $supplier->phone }} {{ $supplier->email }}</dd>
            <dt>Address</dt><dd>{{ $supplier->address ?: '-' }}</dd><dt>TIN</dt><dd>{{ $supplier->tin ?: '-' }}</dd>
            <dt>Payment terms</dt><dd>{{ $supplier->payment_terms ?: '-' }}</dd>
        </dl>
    </x-tab>
    <x-tab title="Purchase orders" icon="fa-file-invoice" :count="$orders->count()" name="orders">
        <div class="table-wrap"><table>
            <thead><tr><th>PO</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
            <tbody>@forelse($orders as $o)<tr><td><a href="{{ route('pos.show', $o) }}">{{ $o->po_no }}</a></td><td>{{ $o->order_date->format('d M Y') }}</td><td><span class="badge {{ $o->tone() }}">{{ $o->statusLabel() }}</span></td><td class="num">{{ ugx($o->total) }}</td></tr>
            @empty <tr><td colspan="4" class="empty">None.</td></tr>@endforelse</tbody></table></div>
    </x-tab>
    <x-tab title="Invoices" icon="fa-file-invoice-dollar" :count="$invoices->count()" name="invoices">
        @can('view-invoices')
        <div class="table-wrap"><table>
            <thead><tr><th>Invoice</th><th>Date</th><th>Payment</th><th class="num">Balance</th></tr></thead>
            <tbody>@forelse($invoices as $i)<tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->supplier_invoice_no }}</a></td><td>{{ $i->invoice_date->format('d M Y') }}</td><td><span class="badge {{ $i->paymentTone() }}">{{ \App\Models\PurchaseInvoice::PAYMENT[$i->payment_status] }}</span></td><td class="num">{{ ugx($i->balance()) }}</td></tr>
            @empty <tr><td colspan="4" class="empty">None.</td></tr>@endforelse</tbody></table></div>
        @endcan
    </x-tab>
</x-tabs>
@endsection
