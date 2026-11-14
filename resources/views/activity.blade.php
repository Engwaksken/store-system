@extends('layouts.app')
@section('title', 'Activity log')
@section('content')
<div class="page-head"><div><h1>Activity log</h1><p>Append-only and hash-chained: any edit or deletion is detected by the daily integrity check.</p></div>
    <div class="actions"><a class="btn secondary" href="{{ $list->exportUrl('xlsx') }}" title="Download the filtered log (all columns, incl. hash) as Excel"><i class="fas fa-file-excel"></i> Excel</a></div></div>
<x-stats :cards="[
    ['Log entries', number_format($stats['total']), 'fa-clock-rotate-left', 'navy'],
    ['Today', number_format($stats['today']), 'fa-calendar-day'],
    ['Last 7 days', number_format($stats['week']), 'fa-calendar-week', 'gold'],
    ['Active users (7 days)', $stats['users'], 'fa-users'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_action">Action starts with</label><input id="f_action" type="text" name="action" value="{{ request('action') }}" placeholder="e.g. po. or stock."></div>
    <div><label for="f_user">User</label><select id="f_user" name="user_id"><option value="">All users</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(request()->integer('user_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
    <tbody>@forelse($logs as $l)
        <tr><td class="nowrap">{{ $l->created_at->format('Y-m-d H:i:s') }}</td><td>{{ $l->user?->name ?? 'system' }}</td><td><code>{{ $l->action }}</code></td><td>{{ $l->description }}</td><td>{{ $l->ip_address }}</td></tr>
    @empty <tr><td colspan="5" class="empty">No activity{{ $list->isFiltered() ? ' matches these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $logs->links() }}</div>
@endsection
