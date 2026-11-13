@extends('layouts.app')
@section('title', $po->po_no)
@section('content')
<div class="page-head no-print"><div><h1>Purchase order {{ $po->po_no }} <span class="badge {{ $po->tone() }}">{{ $po->statusLabel() }}</span></h1></div>
    <div class="actions">
        <button class="btn secondary" onclick="window.print()">Print PO</button>
        @can('receive-po', $po)<a class="btn" href="{{ route('pos.receive', $po) }}">Receive goods (GRN)</a>@endcan
        @if(in_array($po->status, ['approved', 'partially_received', 'received', 'closed']))
            @can('record-invoice')<a class="btn secondary" href="{{ route('invoices.create', $po) }}">Record supplier invoice</a>@endcan
        @endif
    </div></div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>@if($logo = \App\Models\AppSetting::get('logo_path'))<img src="{{ asset('storage/'.$logo) }}" alt="" style="height:48px;display:block;margin-bottom:.4rem">@endif<h2 style="margin:0">{{ $companyName }}</h2>
            <div class="muted">{{ \App\Models\AppSetting::get('company_address') }} {{ \App\Models\AppSetting::get('company_phone') }}</div></div>
        <div class="right"><h2 style="margin:0">PURCHASE ORDER</h2><div><strong>{{ $po->po_no }}</strong></div><div>{{ $po->order_date->format('d M Y') }}</div></div>
    </div>
    <hr style="border:0;border-top:1px solid var(--line);margin:1rem 0">
    <div class="grid grid-2">
        <dl class="meta"><dt>Supplier</dt><dd><strong>{{ $po->supplier->name }}</strong><br>{{ $po->supplier->address }} {{ $po->supplier->phone }}@if($po->supplier->tin)<br>TIN {{ $po->supplier->tin }}@endif</dd></dl>
        <dl class="meta">
            <dt>Deliver to</dt><dd>{{ $po->store->name }}@if($po->store->location), {{ $po->store->location }}@endif</dd>
            @if($po->project)<dt>Project</dt><dd>{{ $po->project->code }} · {{ $po->project->name }}</dd>@endif
            @if($po->expected_on)<dt>Expected</dt><dd>{{ $po->expected_on->format('d M Y') }}</dd>@endif
            @if($po->requisition)<dt>Requisition</dt><dd class="no-print-link"><a href="{{ route('prs.show', $po->requisition) }}">{{ $po->requisition->pr_no }}</a></dd>@endif
        </dl>
    </div>
    <div class="table-wrap" style="margin-top:1rem"><table>
        <thead><tr><th>#</th><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit price</th><th class="num">Amount (UGX)</th><th class="num no-print">Received</th><th class="num no-print">Invoiced</th></tr></thead>
        <tbody>@foreach($po->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td>
            <td class="num">{{ ugx($l->unit_price) }}</td><td class="num">{{ ugx($l->line_total) }}</td><td class="num no-print">{{ qty($l->received_qty) }}</td><td class="num no-print">{{ qty($l->invoiced_qty) }}</td></tr>@endforeach</tbody>
        <tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($po->total) }}</td><td colspan="2" class="no-print"></td></tr></tfoot>
    </table></div>
    @if($po->terms)<p><strong>Terms:</strong> {{ $po->terms }}</p>@endif
    @if($po->notes)<p class="muted">{{ $po->notes }}</p>@endif
    <p class="muted">Raised by {{ $po->creator->name }}@if($po->decided_at) · {{ $po->status === 'rejected' ? 'Rejected' : 'Approved' }} by {{ $po->decider?->name }} on {{ $po->decided_at->format('d M Y') }}@endif
        @if($po->decision_reason) · {{ $po->decision_reason }}@endif</p>
    <div class="signatures"><div>Prepared by (Procurement)</div><div>Approved by</div><div>Supplier acceptance</div></div>
</div>

@can('decide-po', $po)
<form method="POST" action="{{ route('pos.decide', $po) }}" class="card no-print" style="max-width:640px">
    @csrf <input type="hidden" name="lock_version" value="{{ $po->lock_version }}">
    <h2>Approve or reject</h2>
    @if(bccomp((string) $po->total, $threshold, 2) > 0)<p class="alert warn">Above the owner threshold of {{ ugx($threshold) }} UGX: Owner/Admin approval.</p>@endif
    <div class="field"><label for="reason">Comment (required to reject)</label><input id="reason" type="text" name="reason"></div>
    <div class="actions"><button class="btn" name="decision" value="approved">Approve PO</button><button class="btn danger" name="decision" value="rejected">Reject</button></div>
</form>
@elseif($po->status === 'pending_approval')
    <p class="alert warn no-print">Waiting for approval{{ bccomp((string) $po->total, $threshold, 2) > 0 ? ' by the Owner/Admin (above threshold)' : ' by the Overall Manager or Owner/Admin' }}. The person who raised a PO cannot approve it.</p>
@endif

@if($po->receipts->isNotEmpty() || $po->invoices->isNotEmpty())
<div class="grid grid-2 no-print">
    <div class="card table-wrap"><h2>Goods received</h2><table><thead><tr><th>GRN</th><th>Date</th><th class="num">Value</th></tr></thead>
        <tbody>@forelse($po->receipts as $r)<tr><td><a href="{{ route('receipts.show', $r) }}">{{ $r->receipt_no }}</a></td><td>{{ $r->received_on->format('d M Y') }}</td><td class="num">{{ ugx($r->total_value) }}</td></tr>@empty<tr><td colspan="3" class="empty">None.</td></tr>@endforelse</tbody></table></div>
    @can('view-invoices')
    <div class="card table-wrap"><h2>Supplier invoices</h2><table><thead><tr><th>Invoice</th><th>Match</th><th class="num">Total</th></tr></thead>
        <tbody>@forelse($po->invoices as $i)<tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->supplier_invoice_no }}</a></td><td><span class="badge {{ $i->matchTone() }}">{{ \App\Models\PurchaseInvoice::MATCH[$i->match_status] }}</span></td><td class="num">{{ ugx($i->total) }}</td></tr>@empty<tr><td colspan="3" class="empty">None.</td></tr>@endforelse</tbody></table></div>
    @endcan
</div>
@endif

@can('buy')
    @if(in_array($po->status, ['pending_approval', 'approved']) && $po->lines->every(fn ($l) => (float) $l->received_qty == 0))
    <form method="POST" action="{{ route('pos.cancel', $po) }}" class="card no-print" style="max-width:640px">@csrf
        <h2>Cancel purchase order</h2><div class="field"><label for="cancel_reason">Reason</label><input id="cancel_reason" type="text" name="reason" required minlength="5"></div>
        <button class="btn danger">Cancel PO</button></form>
    @elseif(in_array($po->status, ['partially_received', 'received']))
    <form method="POST" action="{{ route('pos.close', $po) }}" class="card no-print" style="max-width:640px">@csrf
        <h2>Close purchase order</h2><p class="muted">Use when no further deliveries will come. Remaining quantities stop counting as committed.</p>
        <div class="field"><label for="close_reason">Reason</label><input id="close_reason" type="text" name="reason" required minlength="5"></div>
        <button class="btn secondary">Close PO</button></form>
    @endif
@endcan
@endsection
