@extends('layouts.app')
@section('title', 'Activity log')
@section('content')
<div class="page-head"><div><h1>Activity log</h1><p>Append-only and hash-chained: any edit or deletion is detected by the daily integrity check.</p></div>
    <div class="actions"><a class="btn secondary" href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}">Excel</a><a class="btn secondary" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">CSV</a></div></div>
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="action">Action starts with</label><input id="action" type="text" name="action" value="{{ request('action') }}" placeholder="e.g. po. or stock."></div>
    <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
    <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
    <tbody>@forelse($logs as $l)
        <tr><td class="nowrap">{{ $l->created_at->format('Y-m-d H:i:s') }}</td><td>{{ $l->user?->name ?? 'system' }}</td><td><code>{{ $l->action }}</code></td><td>{{ $l->description }}</td><td>{{ $l->ip_address }}</td></tr>
    @empty <tr><td colspan="5" class="empty">No activity.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $logs->links() }}</div>
@endsection
