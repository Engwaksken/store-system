@extends('layouts.app')
@section('title', $invoice->pi_no)
@section('content')
<div class="page-head"><div><h1>Supplier invoice {{ $invoice->supplier_invoice_no }} <small class="muted">{{ $invoice->pi_no }}</small></h1>
    <p>{{ $invoice->supplier->name }} · PO <a href="{{ route('pos.show', $invoice->order) }}">{{ $invoice->order->po_no }}</a> ·
        <span class="badge {{ $invoice->matchTone() }}">{{ \App\Models\PurchaseInvoice::MATCH[$invoice->match_status] }}</span>
        <span class="badge {{ $invoice->paymentTone() }}">{{ \App\Models\PurchaseInvoice::PAYMENT[$invoice->payment_status] }}</span></p></div>
    @if($invoice->attachment_path)<a class="btn secondary" href="{{ route('invoices.attachment', $invoice) }}">View scan</a>@endif</div>

<div class="grid grid-3" style="margin-bottom:1.25rem">
    <div class="stat navy"><div class="label">Invoice total</div><div class="value">{{ ugx($invoice->total) }}</div></div>
    <div class="stat"><div class="label">Paid</div><div class="value">{{ ugx($invoice->amount_paid) }}</div></div>
    <div @class(['stat', 'gold' => (float) $invoice->balance() > 0])><div class="label">Balance</div><div class="value">{{ ugx($invoice->balance()) }}</div></div>
</div>

@if($invoice->match_status !== 'matched')
    <div class="alert {{ $invoice->match_status === 'exception' ? 'bad' : 'warn' }}"><strong>Three-way match:</strong><br>{!! nl2br(e($invoice->match_notes)) !!}
        @if($invoice->exception_approved_at)<br>Approved by {{ $invoice->exceptionApprover->name }} on {{ $invoice->exception_approved_at->format('d M Y') }}: {{ $invoice->exception_reason }}@endif</div>
@endif

<div class="card"><dl class="meta">
    <dt>Invoice date</dt><dd>{{ $invoice->invoice_date->format('d M Y') }}</dd><dt>Due</dt><dd>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</dd>
    <dt>Recorded by</dt><dd>{{ $invoice->recorder->name }}, {{ $invoice->created_at->format('d M Y H:i') }}</dd>
</dl></div>
<div class="card table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">PO price</th><th class="num">Amount</th></tr></thead>
    <tbody>@foreach($invoice->lines as $l)
        <tr @class(['low' => bccomp((string) $l->unit_price, (string) $l->orderLine->unit_price, 4) !== 0])><td>{{ $l->orderLine->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td class="num">{{ ugx($l->unit_price) }}</td><td class="num">{{ ugx($l->orderLine->unit_price) }}</td><td class="num">{{ ugx($l->line_total) }}</td></tr>
    @endforeach</tbody>
    <tfoot><tr><td colspan="4">Subtotal</td><td class="num">{{ ugx($invoice->subtotal) }}</td></tr><tr><td colspan="4">Tax</td><td class="num">{{ ugx($invoice->tax) }}</td></tr><tr><td colspan="4">Total</td><td class="num">{{ ugx($invoice->total) }}</td></tr></tfoot>
</table></div>

@can('approve-invoice-exception', $invoice)
<form method="POST" action="{{ route('invoices.approve-exception', $invoice) }}" class="card" style="max-width:640px">@csrf
    <h2>Approve match exception</h2><p class="muted">Only if the difference is agreed (e.g. supplier price change confirmed in writing).</p>
    <div class="field"><label for="ex_reason">Reason</label><input id="ex_reason" type="text" name="reason" required minlength="5"></div>
    <button class="btn">Approve exception</button></form>
@endcan

<div class="card table-wrap"><h2>Payments</h2><table>
    <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>By</th></tr></thead>
    <tbody>@forelse($invoice->payments as $p)<tr><td>{{ $p->payment_no }}</td><td>{{ $p->paid_on->format('d M Y') }}</td><td>{{ \App\Models\SupplierPayment::METHODS[$p->method] }}</td><td>{{ $p->reference }}</td><td class="num">{{ ugx($p->amount) }}</td><td>{{ $p->recorder->name }}</td></tr>
    @empty <tr><td colspan="6" class="empty">No payments recorded.</td></tr>@endforelse</tbody></table>
@can('pay-invoice', $invoice)
<form method="POST" action="{{ route('invoices.pay', $invoice) }}" style="margin-top:1rem">@csrf @include('partials.idem')
    <h3>Record a payment</h3><p class="hint">Record money already paid (bank, cheque, mobile money). The system does not move money.</p>
    <div class="grid grid-4">
        <div><label for="amount">Amount</label><input id="amount" type="number" step="0.01" min="0.01" max="{{ $invoice->balance() }}" name="amount" value="{{ old('amount', (float) $invoice->balance()) }}" required></div>
        <div><label for="paid_on">Paid on</label><input id="paid_on" type="date" name="paid_on" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></div>
        <div><label for="method">Method</label><select id="method" name="method">@foreach(\App\Models\SupplierPayment::METHODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        <div><label for="pay_ref">Reference</label><input id="pay_ref" type="text" name="reference"></div>
    </div>
    <button class="btn" style="margin-top:.75rem">Record payment</button></form>
@endcan
</div>
@endsection
