@extends('layouts.app')
@section('title', $pr->pr_no)
@section('content')
<div class="page-head"><div><h1>Requisition {{ $pr->pr_no }} <span class="badge {{ $pr->tone() }}">{{ $pr->statusLabel() }}</span></h1>
    <p>{{ $pr->project ? $pr->project->code.' · '.$pr->project->name : 'Store restock' }} · deliver to {{ $pr->store->label() }}</p></div>
    <div class="actions">
        @if($pr->status === 'draft' && auth()->id() === $pr->requested_by)
            <form method="POST" action="{{ route('prs.submit', $pr) }}" class="inline-form">@csrf<button class="btn">Submit for approval</button></form>
        @endif
        @if(in_array($pr->status, ['draft', 'submitted', 'approved']) && (auth()->id() === $pr->requested_by || Gate::allows('buy')))
            <form method="POST" action="{{ route('prs.cancel', $pr) }}" class="inline-form">@csrf<button class="btn secondary">Cancel</button></form>
        @endif
        @if($pr->status === 'approved')
            @can('buy')<a class="btn gold" data-modal href="{{ route('pos.create', ['pr' => $pr->id]) }}">Raise purchase order</a>@endcan
        @endif
    </div></div>
<x-stats :cards="[
    ['Items', number_format($pr->lines->count()), 'fa-list', 'navy'],
    ['Estimated total ('.currency().')', ugx($pr->estimated_total), 'fa-calculator'],
    ['Quotations', number_format($pr->quotes->count()), 'fa-file-signature', 'gold'],
    $cheapest !== null ? ['Lowest quote ('.currency().')', ugx($cheapest), 'fa-arrow-down-wide-short'] : ['Purchase orders', number_format($pr->purchaseOrders->count()), 'fa-file-invoice'],
]" />

@can('decide-pr', $pr)
<form method="POST" action="{{ route('prs.decide', $pr) }}" class="card" style="max-width:640px">
    @csrf
    <h2>Approve or reject</h2>
    <div class="field"><label for="reason">Comment (required to reject)</label><input id="reason" type="text" name="reason"></div>
    <div class="actions"><button class="btn" name="decision" value="approved"><i class="fas fa-check"></i> Approve</button><button class="btn danger" name="decision" value="rejected" data-confirm="Reject this document? The person who raised it will be notified." data-confirm-ok="Yes, reject"><i class="fas fa-ban"></i> Reject</button></div>
</form>
@endcan

<x-tabs id="pr-show">
    <x-tab title="Items" icon="fa-list" :count="$pr->lines->count()" name="items">
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Est. unit cost</th>
                @foreach($pr->quotes as $q)<th class="num">{{ $q->supplier->code }}@if($q->is_selected) <i class="fas fa-check text-ok" title="Selected"></i>@endif</th>@endforeach</tr></thead>
            <tbody>@foreach($pr->lines as $l)
                <tr><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->est_unit_cost) }}</td>
                    @foreach($pr->quotes as $q)<td class="num">{{ ugx($q->lines->firstWhere('purchase_requisition_line_id', $l->id)?->unit_price) }}</td>@endforeach</tr>
            @endforeach</tbody>
            <tfoot><tr><td colspan="4">Total</td><td class="num">{{ ugx($pr->estimated_total) }}</td>@foreach($pr->quotes as $q)<td class="num" @style(['font-weight:700' => (string) $q->total === (string) $cheapest])>{{ ugx($q->total) }}</td>@endforeach</tr></tfoot>
        </table></div>
    </x-tab>
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta">
            <dt>Purpose</dt><dd>{{ $pr->purpose }}</dd>
            <dt>Requested by</dt><dd>{{ $pr->requester->name }}@if($pr->submitted_at), submitted {{ $pr->submitted_at->format('d M Y H:i') }}@endif</dd>
            @if($pr->needed_on)<dt>Needed by</dt><dd>{{ $pr->needed_on->format('d M Y') }}</dd>@endif
            @if($pr->decided_at)<dt>Decision</dt><dd>{{ ucfirst($pr->status === 'rejected' ? 'rejected' : 'approved') }} by {{ $pr->decider->name }} on {{ $pr->decided_at->format('d M Y H:i') }}@if($pr->decision_reason): {{ $pr->decision_reason }}@endif</dd>@endif
            @if($pr->purchaseOrders->isNotEmpty())<dt>Purchase orders</dt><dd>@foreach($pr->purchaseOrders as $o)<a href="{{ route('pos.show', $o) }}">{{ $o->po_no }}</a> ({{ $o->statusLabel() }}) @endforeach</dd>@endif
        </dl>
    </x-tab>
    <x-tab title="Quotations" icon="fa-file-signature" :count="$pr->quotes->count()" name="quotes">
        @if($pr->quotes->isNotEmpty())
        <div class="table-wrap"><table>
            <thead><tr><th>Supplier</th><th>Ref</th><th>Date</th><th>Valid until</th><th class="num">Total</th><th>Selection</th></tr></thead>
            <tbody>@foreach($pr->quotes->sortBy('total') as $q)
                <tr><td>{{ $q->supplier->name }}</td><td>{{ $q->quote_ref }}</td><td>{{ $q->quoted_on->format('d M Y') }}</td><td>{{ $q->valid_until?->format('d M Y') }}</td><td class="num">{{ ugx($q->total) }}</td>
                    <td>@if($q->is_selected)<span class="badge approved">Selected</span> {{ $q->selection_reason }}
                        @elseif($pr->status === 'approved' && Gate::allows('buy'))
                            <form method="POST" action="{{ route('quotes.select', $q) }}" class="inline-form">@csrf
                                @if((string) $q->total !== (string) $cheapest)<input type="text" name="selection_reason" placeholder="Reason (not the lowest)" required aria-label="Reason for choosing {{ $q->supplier->name }}">@endif
                                <button class="btn small secondary">Select</button></form>
                        @endif</td></tr>
            @endforeach</tbody>
        </table></div>
        @endif
    </x-tab>
    <x-tab title="Record a quote" icon="fa-plus" name="record-quote">
        @if($pr->status === 'approved' && Gate::allows('buy') && $suppliers->isNotEmpty())
        <form method="POST" action="{{ route('prs.quotes.store', $pr) }}">
            @csrf
            <div class="form-grid">
                <div class="field"><label for="supplier_id">Supplier</label><select id="supplier_id" name="supplier_id" required>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->label() }}</option>@endforeach</select></div>
                <div class="field"><label for="quote_ref">Quote reference</label><input id="quote_ref" type="text" name="quote_ref"></div>
                <div class="field"><label for="quoted_on">Quote date</label><input id="quoted_on" type="date" name="quoted_on" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required></div>
                <div class="field"><label for="valid_until">Valid until</label><input id="valid_until" type="date" name="valid_until"></div>
            </div>
            <div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price ({{ currency() }})</th></tr></thead>
                <tbody>@foreach($pr->lines as $l)<tr><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td><input type="number" step="0.01" min="0" name="price[{{ $l->id }}]" required aria-label="Price {{ $l->item->sku }}"></td></tr>@endforeach</tbody></table></div>
            <button class="btn secondary">Save quote</button>
        </form>
        @endif
    </x-tab>
</x-tabs>
@endsection
