@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="page-head"><div><h1>Notifications</h1><p>Approvals, deliveries, reminders and budget alerts for you.</p></div>
    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn secondary">Mark all as read</button></form></div>
<x-stats :cards="[
    ['Notifications', $stats['total'], 'fa-bell', 'navy'],
    ['Unread', $stats['unread'], 'fa-envelope', $stats['unread'] ? 'gold' : ''],
    ['Last 7 days', $stats['week'], 'fa-calendar-week'],
]" />
<div class="card table-wrap"><table>
    <thead><tr><th>When</th><th>Subject</th><th>Details</th><th></th></tr></thead>
    <tbody>@forelse($notifications as $n)
        @php($tone = ['ok' => 'approved', 'bad' => 'rejected', 'warn' => 'pending'][$n->data['kind'] ?? ''] ?? 'partial')
        <tr @style(['font-weight:600' => $n->read_at === null])><td class="nowrap">{{ $n->created_at->diffForHumans() }}</td>
            <td>@if($n->read_at === null)<span class="badge {{ $tone }}">New</span> @endif{{ $n->data['subject'] ?? '' }}</td>
            <td style="font-weight:400">{{ $n->data['body'] ?? '' }}</td>
            <td class="right"><a class="btn small secondary" href="{{ route('notifications.open', $n->id) }}">{{ $n->data['action'] ?? 'Open' }}</a></td></tr>
    @empty <tr><td colspan="4" class="empty">Nothing yet.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $notifications->links() }}</div>
@endsection
