@extends('layouts.app')
@section('title', 'Returns to store')
@section('content')
<div class="page-head"><div><h1>Returns to store</h1><p>Unused material brought back from site. Recorded against the original voucher; the project is credited at the issue cost.</p></div></div>
<x-stats :cards="[
    ['Returns', $stats['total'], 'fa-rotate-left', 'navy'],
    ['This month', $stats['month'], 'fa-calendar-day'],
    ['Value returned ('.currency().')', ugx($stats['value']), 'fa-sack-dollar', 'gold'],
]" />
<x-list-filters :list="$list" />
<div class="card table-wrap"><table>
    <thead><tr><th>Return</th><th>Date</th><th>Voucher</th><th>Project</th><th>Store</th><th>Returned by</th><th class="num">Value ({{ currency() }})</th><th>Received by</th></tr></thead>
    <tbody>@forelse($returns as $r)
        <tr><td><a href="{{ route('returns.show', $r) }}">{{ $r->return_no }}</a></td><td class="nowrap">{{ $r->returned_at->format('d M Y') }}</td>
            <td><a href="{{ route('issues.show', $r->issue) }}">{{ $r->issue->issue_no }}</a></td><td>{{ $r->project->code }}</td><td>{{ $r->store->code }}</td>
            <td>{{ $r->returned_by_name }}</td><td class="num">{{ ugx($r->total_value) }}</td><td>{{ $r->receiver->name }}</td></tr>
    @empty <tr><td colspan="8" class="empty">{{ $list->isFiltered() ? 'No returns match these filters.' : 'No returns yet. Start one from an issue voucher.' }}</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $returns->links() }}</div>
@endsection
