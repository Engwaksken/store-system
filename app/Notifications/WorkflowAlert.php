<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Support\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One generic alert for "something is waiting for you / happened to your document".
 *  - in-app (bell + pop-up in the top bar): always;
 *  - e-mail: unless CMS_MAIL_ALERTS=false for everyone, or the user switched e-mail off;
 *  - browser push: on every device where the user turned push on (Notification settings), when VAPID keys are set.
 */
class WorkflowAlert extends Notification
{
    use Queueable;

    public function __construct(public string $subject, public string $body, public string $url, public string $action = 'Open', public string $kind = 'info')
    {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('cms.mail_alerts', true) && ($notifiable->notify_email ?? true)) {
            $channels[] = 'mail';
        }
        if (PushSender::configured() && ($notifiable->notify_push ?? true) && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body)
            ->action($this->action, $this->url)
            ->line('You are receiving this because of your role in the store system. You can change how you are notified under Notification settings.');
    }

    /** @return array{title:string, body:string, url:string, tag:string, kind:string} */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->subject,
            'body' => mb_strimwidth($this->body, 0, 240, '...'),
            // Opening the push marks the matching bell notification read (same id across channels).
            'url' => $this->id ? route('notifications.open', $this->id) : $this->url,
            'tag' => md5($this->url),   // a newer alert about the same document replaces the older one on the device
            'kind' => $this->kind,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return ['subject' => $this->subject, 'body' => $this->body, 'url' => $this->url, 'action' => $this->action, 'kind' => $this->kind];
    }
}
