<?php

namespace App\Notifications\Channels;

use App\Support\PushSender;
use Illuminate\Notifications\Notification;

/** Notification channel: delivers toWebPush() to every device on which the user turned push on. */
class WebPushChannel
{
    public function __construct(private PushSender $sender)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! method_exists($notifiable, 'pushSubscriptions')) {
            return;
        }
        $this->sender->send($notifiable->pushSubscriptions()->get(), $notification->toWebPush($notifiable));
    }
}
