@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="page-head"><div><h1>Notifications</h1><p>Approvals, deliveries, reminders and budget alerts for you.</p></div>
    <div class="actions"><a class="btn secondary" href="{{ route('notifications.settings') }}"><i class="fas fa-gear"></i> Notification settings</a>
    <form method="POST" action="{{ route('notifications.read-all') }}" class="inline-form">@csrf<button class="btn secondary"><i class="fas fa-check-double"></i> Mark all as read</button></form></div></div>
<x-stats :cards="[
    ['Notifications', $stats['total'], 'fa-bell', 'navy'],
    ['Unread', $stats['unread'], 'fa-envelope', $stats['unread'] ? 'gold' : ''],
    ['Last 7 days', $stats['week'], 'fa-calendar-week'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_unread">Show</label><select id="f_unread" name="unread"><option value="">All notifications</option><option value="1" @selected(request()->boolean('unread'))>Unread only</option></select></div>
</x-list-filters>
<div data-bulk-scope="notifications">
<x-bulk-bar :action="route('notifications.bulk-delete')" scope="notifications"><button class="btn small danger" data-confirm="Delete the selected notifications?" data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button></x-bulk-bar>
<div class="card table-wrap"><table>
    <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all on this page"></th><th>When</th><th>Subject</th><th>Details</th><th></th></tr></thead>
    <tbody>@forelse($notifications as $n)
        @php($tone = ['ok' => 'approved', 'bad' => 'rejected', 'warn' => 'pending'][$n->data['kind'] ?? ''] ?? 'partial')
        <tr @style(['font-weight:600' => $n->read_at === null])><td class="check-col"><input type="checkbox" value="{{ $n->id }}" data-row-check aria-label="Select {{ $n->data['subject'] ?? 'notification' }}"></td><td class="nowrap">{{ $n->created_at->diffForHumans() }}</td>
            <td>@if($n->read_at === null)<span class="badge {{ $tone }}">New</span> @endif{{ $n->data['subject'] ?? '' }}</td>
            <td style="font-weight:400">{{ $n->data['body'] ?? '' }}</td>
            <td class="right"><a class="btn small secondary" href="{{ route('notifications.open', $n->id) }}">{{ $n->data['action'] ?? 'Open' }}</a></td></tr>
    @empty <tr><td colspan="5" class="empty">{{ $list->isFiltered() ? 'No notifications match these filters.' : 'Nothing yet.' }}</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $notifications->links() }}</div>
</div>
@endsection
