<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One generic e-mail for "something is waiting for you / happened to your request". Uses the existing SMTP mailbox; no SMS. */
class WorkflowAlert extends Notification
{
    use Queueable;

    public function __construct(public string $subject, public string $body, public string $url, public string $action = 'Open request')
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
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
}
