<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** In-app notifications (the bell). Users only ever see and change their own. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->paginate(30),
            'stats' => [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                'week' => $user->notifications()->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
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
}
