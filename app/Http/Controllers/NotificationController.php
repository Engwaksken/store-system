<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PushSubscription;
use App\Notifications\WorkflowAlert;
use App\Support\ListPage;
use App\Support\PushSender;
use Illuminate\Http\Request;

/** In-app notifications (the bell), the user's channel settings and push devices. Users only ever see and change their own. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $stats = [
            'total' => $user->notifications()->count(),
            'unread' => $user->unreadNotifications()->count(),
            'week' => $user->notifications()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
        // Only the signed-in user's own notifications (newest first, as the relation orders them).
        $query = $user->notifications()->getQuery()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'));

        return ListPage::make($request, $query, 'Notifications')
            ->period('created_at', 'Received')
            ->search(fn ($q, $term) => $q->where('data', 'like', '%'.addcslashes($term, '%_\\').'%'), 'Subject or details')
            ->perPage(30)
            ->columns([
                'Subject' => fn ($n) => $n->data['subject'] ?? '', 'Details' => fn ($n) => $n->data['body'] ?? '',
                'Date' => fn ($n) => $n->created_at->format('Y-m-d H:i'), 'Read' => fn ($n) => $n->read_at ? 'Yes' : 'No',
            ])
            ->respond(fn ($notifications, $list) => view('notifications.index', ['notifications' => $notifications, 'list' => $list, 'stats' => $stats]));
    }

    /** Mark as read and go to the document the alert is about (only same-site URLs are followed). */
    public function open(Request $request, string $id)
    {
        $n = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();
        $url = (string) ($n->data['url'] ?? '');
        $sameSite = str_starts_with($url, url('/'));

        return redirect($sameSite ? $url : route('notifications.index'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }

    /** Polled by the top bar every minute: unread count plus alerts newer than ?since (ISO time) for pop-ups. */
    public function poll(Request $request)
    {
        $user = $request->user();
        $since = $request->date('since');
        $fresh = $since
            ? $user->unreadNotifications()->where('created_at', '>', $since)->latest()->limit(3)->get()
                ->map(fn ($n) => [
                    'subject' => $n->data['subject'] ?? '', 'body' => $n->data['body'] ?? '', 'kind' => $n->data['kind'] ?? 'info',
                    'url' => route('notifications.open', $n->id),
                ])
            : collect();

        return response()->json(['unread' => $user->unreadNotifications()->count(), 'fresh' => $fresh->values(), 'now' => now()->toIso8601String()]);
    }

    public function settings(Request $request)
    {
        $user = $request->user();

        return view('notifications.settings', [
            'user' => $user,
            'devices' => $user->pushSubscriptions()->latest()->get(),
            'pushReady' => PushSender::configured(),
            'vapidKey' => config('cms.push.public_key'),
            'mailGlobal' => (bool) config('cms.mail_alerts', true),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['notify_email' => 'nullable|boolean', 'notify_push' => 'nullable|boolean']);
        $request->user()->forceFill(['notify_email' => (bool) ($data['notify_email'] ?? false), 'notify_push' => (bool) ($data['notify_push'] ?? false)])->save();

        return back()->with('status', 'Notification settings saved.');
    }

    /** Called by public/js/app.js after the browser grants permission and creates a push subscription. */
    public function subscribe(Request $request)
    {
        abort_unless(PushSender::configured(), 409, 'Push notifications are not set up on this server.');
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1000'],
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|in:aes128gcm,aesgcm',
        ]);
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        // An endpoint belongs to one browser profile: if another user signs in on it, the device moves to them.
        PushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'device' => $this->deviceName((string) $request->userAgent()),
        ]);
        ActivityLog::record('push.subscribed', 'Push notifications turned on for '.$this->deviceName((string) $request->userAgent()));

        return response()->json(['ok' => true]);
    }

    /** Remove a device: by id from the settings page, or by endpoint when the browser unsubscribes. */
    public function unsubscribe(Request $request, ?PushSubscription $subscription = null)
    {
        $query = $request->user()->pushSubscriptions();
        $subscription
            ? $query->whereKey($subscription->id)->delete()
            : $query->where('endpoint_hash', PushSubscription::hashEndpoint((string) $request->input('endpoint')))->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'Device removed. It will no longer get push notifications.');
    }

    public function test(Request $request)
    {
        $user = $request->user();
        $user->notify(new WorkflowAlert('Test notification', 'This is a test from the store system. If you can read this, notifications reach you.', route('notifications.settings'), 'Open settings', 'ok'));

        return back()->with('status', 'Test notification sent'.($user->pushSubscriptions()->exists() && PushSender::configured() ? ' to the bell, e-mail and your devices.' : ' to the bell and e-mail.'));
    }

    private function deviceName(string $ua): string
    {
        $browser = collect(['Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'])
            ->first(fn ($name, $needle) => str_contains($ua, $needle), 'Browser');
        $os = collect(['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'])
            ->first(fn ($name, $needle) => str_contains($ua, $needle), 'device');

        return "{$browser} on {$os}";
    }
}
