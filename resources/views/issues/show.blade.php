@extends('layouts.app')
@section('title', $issue->issue_no)
@section('content')
<div class="page-head no-print"><div><h1>Issue voucher {{ $issue->issue_no }}</h1></div>
    <div class="actions"><button type="button" class="btn secondary" data-print><i class="fas fa-print"></i> Print voucher</button><a class="btn secondary" href="{{ route('issues.pdf', $issue) }}">PDF</a>@can('record-return', $issue)<a class="btn secondary" data-modal href="{{ route('returns.create', $issue) }}">Return to store</a>@endcan<a class="btn secondary" href="{{ route('requests.show', $issue->request) }}">Open request</a></div></div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>@if($logo = \App\Models\AppSetting::get('logo_path'))<img src="{{ asset('storage/'.$logo) }}" alt="" style="height:48px;display:block;margin-bottom:.4rem">@endif<h2 style="margin:0">{{ $companyName }}</h2><div class="muted">{{ \App\Models\AppSetting::get('company_address') }} {{ \App\Models\AppSetting::get('company_phone') }}</div></div>
        <div class="right"><h2 style="margin:0">STORE ISSUE VOUCHER</h2><div><strong>{{ $issue->issue_no }}</strong></div><div>{{ $issue->issued_at->format('d M Y H:i') }}</div></div>
    </div>
    <hr style="border:0;border-top:1px solid var(--line);margin:1rem 0">
    <dl class="meta">
        <dt>Project</dt><dd>{{ $issue->project->code }} · {{ $issue->project->name }}</dd>
        <dt>Request</dt><dd>{{ $issue->request->request_no }} (v{{ $issue->request->version }}) by {{ $issue->request->requester->name }}</dd>
        <dt>Approved by</dt><dd>@foreach($issue->request->approvals->where('version', $issue->request->version)->where('decision', 'approved') as $a){{ $a->step === 'pm' ? 'PM' : 'Overall' }}: {{ $a->approver->name }} ({{ $a->decided_at->format('d M Y') }})@if(! $loop->last); @endif @endforeach</dd>
        <dt>Store</dt><dd>{{ $issue->store->label() }}</dd>
        <dt>Issued by</dt><dd>{{ $issue->issuer->name }} (Storekeeper)</dd>
        <dt>Recipient</dt><dd>{{ $issue->recipient_name }}</dd>
        @if($issue->notes)<dt>Notes</dt><dd>{{ $issue->notes }}</dd>@endif
    </dl>
    <div class="table-wrap" style="margin-top:1rem"><table>
        <thead><tr><th>#</th><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th>@can('view-stock-value')<th class="num">Unit cost</th><th class="num">Value (UGX)</th>@endcan</tr></thead>
        <tbody>@foreach($issue->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td>
            @can('view-stock-value')<td class="num">{{ ugx($l->unit_cost_snapshot) }}</td><td class="num">{{ ugx($l->line_value) }}</td>@endcan</tr>@endforeach</tbody>
        @can('view-stock-value')<tfoot><tr><td colspan="6">Total</td><td class="num">{{ ugx($issue->total_value) }}</td></tr></tfoot>@endcan
    </table></div>

    @if($issue->returns->isNotEmpty())
        <div class="alert warn" style="margin-top:1rem">Returned to store: @foreach($issue->returns as $r)<a href="{{ route('returns.show', $r) }}">{{ $r->return_no }}</a> ({{ $r->returned_at->format('d M Y') }}, {{ ugx($r->total_value) }} UGX)@if(! $loop->last); @endif @endforeach</div>
    @endif
    @if($issue->acknowledgment)
        <div class="alert ok" style="margin-top:1rem">Received and acknowledged by <strong>{{ $issue->acknowledgment->recipient_name }}</strong> on {{ $issue->acknowledgment->acknowledged_at->format('d M Y H:i') }} (recorded by {{ $issue->acknowledgment->user->name }}).@if($issue->acknowledgment->remarks) "{{ $issue->acknowledgment->remarks }}"@endif</div>
    @endif
    <div class="signatures"><div>Issued by (storekeeper)</div><div>Received by (name &amp; signature)</div><div>Date</div></div>
    @if($footer = \App\Models\AppSetting::get('voucher_footer'))<p class="muted" style="margin-top:1.5rem;font-size:.8rem">{{ $footer }}</p>@endif
</div>

@can('acknowledge-issue', $issue)
<form method="POST" action="{{ route('issues.acknowledge', $issue) }}" class="card no-print" style="max-width:640px">
    @csrf
    <h2>Acknowledge receipt</h2>
    <div class="field"><label>Name of person who received the goods</label><input type="text" name="recipient_name" value="{{ old('recipient_name', $issue->recipient_name) }}"></div>
    <div class="field"><label>Remarks (e.g. condition, shortages)</label><input type="text" name="remarks" value="{{ old('remarks') }}"></div>
    <button class="btn">Confirm materials received</button>
</form>
@endcan
@endsection
