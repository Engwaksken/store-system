@extends('layouts.app')
@section('title', 'Notification settings')
@section('content')
<div class="page-head"><div><h1>Notification settings</h1><p>Choose how the store system tells you about approvals, deliveries and other work for you.</p></div>
    <div class="actions"><a class="btn secondary" href="{{ route('notifications.index') }}"><i class="fas fa-bell"></i> My notifications</a>
        <form method="POST" action="{{ route('notifications.test') }}" class="inline-form">@csrf<button class="btn"><i class="fas fa-paper-plane"></i> Send me a test</button></form></div></div>

<x-stats :cards="[
    ['In-app (bell)', 'Always on', 'fa-bell', 'navy'],
    ['E-mail', ! $mailGlobal ? 'Off for everyone' : ($user->notify_email ? 'On' : 'Off'), 'fa-envelope', $mailGlobal && $user->notify_email ? '' : 'gold'],
    ['Push', ! $pushReady ? 'Not set up' : ($user->notify_push ? 'On' : 'Off'), 'fa-mobile-screen', $pushReady && $user->notify_push ? '' : 'gold'],
    ['Devices with push', $devices->count(), 'fa-laptop'],
]" />

<x-tabs id="notification-settings">
    <x-tab title="Channels" icon="fa-sliders" name="channels">
        <form method="POST" action="{{ route('notifications.settings.update') }}" class="settings-pane">
            @csrf @method('PUT')
            <div class="field"><label class="check"><input type="checkbox" checked disabled> <span><strong>In-app</strong>: the bell in the top bar, with a pop-up when something new arrives. Always on.</span></label></div>
            <div class="field"><input type="hidden" name="notify_email" value="0">
                <label class="check"><input type="checkbox" name="notify_email" value="1" @checked($user->notify_email) @disabled(! $mailGlobal)> <span><strong>E-mail</strong> to {{ $user->email }}</span></label>
                @unless($mailGlobal)<p class="hint">E-mail alerts are switched off for everyone by the administrator (CMS_MAIL_ALERTS).</p>@endunless</div>
            <div class="field"><input type="hidden" name="notify_push" value="0">
                <label class="check"><input type="checkbox" name="notify_push" value="1" @checked($user->notify_push) @disabled(! $pushReady)> <span><strong>Push</strong> to the devices listed under <em>Devices</em>, even when the site is closed</span></label></div>
            <button class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </x-tab>

    <x-tab title="Devices" icon="fa-mobile-screen" name="devices" :count="$devices->count()">
        @if(! $pushReady)
            <div class="alert warn"><i class="fas fa-triangle-exclamation"></i> Push notifications are not set up on this server yet. The administrator runs <code>php artisan cms:push-keys</code> once to turn them on.</div>
        @else
            <div class="push-panel" data-push data-vapid="{{ $vapidKey }}" data-subscribe-url="{{ route('push.subscribe') }}" data-unsubscribe-url="{{ route('push.unsubscribe') }}">
                <p class="push-status muted" data-push-status>Checking this device...</p>
                <div class="actions">
                    <button type="button" class="btn" data-push-enable hidden><i class="fas fa-bell"></i> Turn on push for this device</button>
                    <button type="button" class="btn secondary" data-push-disable hidden><i class="fas fa-bell-slash"></i> Turn off for this device</button>
                </div>
                <p class="hint">Works in Chrome, Edge, Firefox and Samsung Internet on Android and computers, and in Safari on Mac. On iPhone and iPad, first add this site to the Home Screen (Share, then Add to Home Screen) and turn push on from there.</p>
            </div>
        @endif
        <div class="table-wrap" style="margin-top:1rem"><table>
            <thead><tr><th>Device</th><th>Added</th><th>Last push</th><th></th></tr></thead>
            <tbody>@forelse($devices as $d)
                <tr><td><i class="fas fa-laptop muted"></i> {{ $d->device ?? 'Browser' }}</td><td>{{ $d->created_at->format('d M Y') }}</td><td>{{ $d->last_used_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="right"><form method="POST" action="{{ route('push.unsubscribe', $d) }}" class="inline-form">@csrf @method('DELETE')
                        <button class="btn-icon danger" title="Remove device" aria-label="Remove {{ $d->device }}" data-confirm="Stop push notifications on {{ $d->device ?? 'this device' }}?" data-confirm-ok="Yes, remove"><i class="fas fa-trash-can"></i></button></form></td></tr>
            @empty <tr><td colspan="4" class="empty">No devices yet. Turn push on above on each phone or computer you use.</td></tr> @endforelse</tbody>
        </table></div>
    </x-tab>

    <x-tab title="What you are told about" icon="fa-list-check" name="events">
        <div class="table-wrap"><table style="max-width:820px">
            <thead><tr><th>When</th><th>Who is notified</th></tr></thead>
            <tbody>
                <tr><td>Material request submitted, edited or resubmitted</td><td>Project Manager (Owner/Admin when the requester is the PM)</td></tr>
                <tr><td>PM approved</td><td>Overall Manager; the requester gets a progress update</td></tr>
                <tr><td>Fully approved</td><td>Storekeepers (ready to issue) and the requester</td></tr>
                <tr><td>Rejected or cancelled</td><td>The requester, or whoever was due to decide</td></tr>
                <tr><td>Materials issued / receipt confirmed</td><td>The requester / the storekeeper who issued</td></tr>
                <tr><td>Transfer dispatched, received or cancelled</td><td>Destination storekeepers / the dispatcher</td></tr>
                <tr><td>Stock count submitted / decided</td><td>Owner/Admin and Overall Manager / the counter</td></tr>
                <tr><td>Purchase requisitions, purchase orders, invoice exceptions</td><td>The approvers and the person who raised them</td></tr>
                <tr><td>Expense claims submitted, decided, approved for payment</td><td>Approver, claimant, accountant</td></tr>
                <tr><td>Reminders, escalations and budget alerts (daily)</td><td>Whoever must act; Owner/Admin after the escalation period</td></tr>
            </tbody>
        </table></div>
    </x-tab>
</x-tabs>
@endsection
