<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One generic alert for "something is waiting for you / happened to your document".
 * Always stored in-app (bell in the top bar); also e-mailed through the existing SMTP mailbox. No SMS/WhatsApp.
 */
class WorkflowAlert extends Notification
{
    use Queueable;

    public function __construct(public string $subject, public string $body, public string $url, public string $action = 'Open', public string $kind = 'info')
    {
    }

    public function via(object $notifiable): array
    {
        return config('cms.mail_alerts', true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body)
            ->action($this->action, $this->url)
            ->line('You are receiving this because of your role in the store system.');
    }

    public function toArray(object $notifiable): array
    {
        return ['subject' => $this->subject, 'body' => $this->body, 'url' => $this->url, 'action' => $this->action, 'kind' => $this->kind];
    }
}
