@extends('layouts.app')
@section('title', 'Issues & vouchers')
@section('content')
<div class="page-head"><h1>Issues &amp; vouchers</h1></div>
<x-stats :cards="[
    ['Vouchers', number_format($stats['total']), 'fa-file-lines', 'navy'],
    ['Issued this month', $stats['month'], 'fa-dolly'],
    ['Value this month (UGX)', ugx($stats['month_value']), 'fa-sack-dollar', 'gold'],
    ['Waiting to issue', $queue->count(), 'fa-hourglass-half', $queue->count() ? 'bad' : ''],
]" />
@can('issue-stock')
<div class="card"><h2>Ready to issue (both approvals complete)</h2><div class="table-wrap"><table>
    <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($queue as $r)<tr><td><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }}</a></td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td><td>@include('partials.status', ['status' => $r->status])</td>
        <td class="right"><a class="btn small" data-modal href="{{ route('issues.create', $r) }}">Issue</a></td></tr>
    @empty<tr><td colspan="5" class="empty">No approved requests waiting.</td></tr>@endforelse</tbody>
</table></div></div>
@endcan
<div class="card"><h2>Vouchers</h2><div class="table-wrap"><table>
    <thead><tr><th>Voucher</th><th>Request</th><th>Project</th><th>Issued</th><th>By</th><th>Recipient</th><th>Acknowledged</th></tr></thead>
    <tbody>@forelse($issues as $i)<tr><td><a href="{{ route('issues.show', $i) }}">{{ $i->issue_no }}</a></td><td>{{ $i->request->request_no }}</td><td>{{ $i->project->code }}</td>
        <td>{{ $i->issued_at->format('d M Y H:i') }}</td><td>{{ $i->issuer->name }}</td><td>{{ $i->recipient_name }}</td>
        <td>@if($i->acknowledgment)<span class="badge approved">Yes</span>@else<span class="badge pending">Pending</span>@endif</td></tr>
    @empty<tr><td colspan="7" class="empty">No vouchers yet.</td></tr>@endforelse</tbody>
</table></div><div class="pagination">{{ $issues->links() }}</div></div>
@endsection
