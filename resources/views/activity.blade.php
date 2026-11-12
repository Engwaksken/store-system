@extends('layouts.app')
@section('title', 'Activity log')
@section('content')
<div class="page-head"><h1>Activity log</h1>
    <form method="GET" class="actions filters"><input type="text" name="action" value="{{ request('action') }}" placeholder="Action prefix e.g. request."><button class="btn small">Filter</button></form>
</div>
<div class="card table-wrap"><table>
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
    <tbody>@forelse($logs as $l)
        <tr><td class="nowrap">{{ $l->created_at->format('Y-m-d H:i:s') }}</td><td>{{ $l->user?->name ?? 'system' }}</td><td><code>{{ $l->action }}</code></td><td>{{ $l->description }}</td><td>{{ $l->ip_address }}</td></tr>
    @empty <tr><td colspan="5" class="empty">No activity.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $logs->links() }}</div>
@endsection
