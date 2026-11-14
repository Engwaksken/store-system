@extends('layouts.app')
@section('title', 'Supplier invoices')
@section('content')
<div class="page-head"><div><h1>Supplier invoices</h1><p>Recorded against purchase orders and matched to goods received. Record invoices from a PO page.</p></div></div>
<x-stats :cards="[
    ['Invoices', $stats['total'], 'fa-file-invoice-dollar', 'navy'],
    ['Unpaid', $stats['unpaid'], 'fa-hourglass-half', 'gold'],
    ['Overdue', $stats['overdue'], 'fa-triangle-exclamation', $stats['overdue'] ? 'bad' : ''],
    ['Outstanding ('.currency().')', ugx($stats['balance']), 'fa-scale-unbalanced'],
]" />
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="match_status">Match</label><select id="match_status" name="match_status"><option value="">All</option>@foreach(\App\Models\PurchaseInvoice::MATCH as $k => $v)<option value="{{ $k }}" @selected(request('match_status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label for="payment_status">Payment</label><select id="payment_status" name="payment_status"><option value="">All</option>@foreach(\App\Models\PurchaseInvoice::PAYMENT as $k => $v)<option value="{{ $k }}" @selected(request('payment_status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Ref</th><th>Supplier invoice</th><th>Supplier</th><th>PO</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Match</th><th>Payment</th></tr></thead>
    <tbody>@forelse($invoices as $i)
        <tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->pi_no }}</a></td><td>{{ $i->supplier_invoice_no }}</td><td>{{ $i->supplier->name }}</td><td>{{ $i->order->po_no }}</td>
            <td class="nowrap">{{ $i->invoice_date->format('d M Y') }}</td><td class="nowrap">{{ $i->due_date?->format('d M Y') }}</td><td class="num">{{ ugx($i->total) }}</td><td class="num">{{ ugx($i->balance()) }}</td>
            <td><span class="badge {{ $i->matchTone() }}">{{ \App\Models\PurchaseInvoice::MATCH[$i->match_status] }}</span></td><td><span class="badge {{ $i->paymentTone() }}">{{ \App\Models\PurchaseInvoice::PAYMENT[$i->payment_status] }}</span></td></tr>
    @empty <tr><td colspan="10" class="empty">No supplier invoices.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $invoices->links() }}</div>
@endsection
