@extends('pdf.layout')
@section('title', $issue->issue_no)
@section('doc-head')<h1>STORE ISSUE VOUCHER</h1><strong>{{ $issue->issue_no }}</strong><br>{{ $issue->issued_at->format('d M Y H:i') }}@endsection
@section('body')
<table class="meta">
    <tr><td class="k">Project</td><td>{{ $issue->project->code }} · {{ $issue->project->name }}</td></tr>
    <tr><td class="k">Store</td><td>{{ $issue->store->name }}</td></tr>
    <tr><td class="k">Request</td><td>{{ $issue->request->request_no }} (v{{ $issue->request->version }}) by {{ $issue->request->requester->name }}</td></tr>
    <tr><td class="k">Approved by</td><td>@foreach($issue->request->approvals->where('version', $issue->request->version)->where('decision', 'approved') as $a){{ $a->step === 'pm' ? 'PM' : 'Overall' }}: {{ $a->approver->name }} ({{ $a->decided_at->format('d M Y') }})@if(! $loop->last); @endif @endforeach</td></tr>
    <tr><td class="k">Issued by</td><td>{{ $issue->issuer->name }}</td></tr>
    <tr><td class="k">Recipient</td><td>{{ $issue->recipient_name }}</td></tr>
</table>
<table class="grid">
    <thead><tr><th>#</th><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th>@if($showValue)<th class="num">Unit cost</th><th class="num">Value ({{ currency() }})</th>@endif</tr></thead>
    <tbody>@foreach($issue->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td>
        @if($showValue)<td class="num">{{ ugx($l->unit_cost_snapshot) }}</td><td class="num">{{ ugx($l->line_value) }}</td>@endif</tr>@endforeach</tbody>
    @if($showValue)<tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($issue->total_value) }}</td></tr></tfoot>@endif
</table>
@if($issue->acknowledgment)<p>Acknowledged by <strong>{{ $issue->acknowledgment->recipient_name }}</strong> on {{ $issue->acknowledgment->acknowledged_at->format('d M Y H:i') }}.</p>@endif
<table class="sign"><tr><td>Issued by (storekeeper)</td><td>Received by (name &amp; signature)</td><td>Date</td></tr></table>
@endsection
