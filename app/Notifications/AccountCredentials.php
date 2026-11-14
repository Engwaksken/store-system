<?php

namespace App\Notifications;

use App\Models\AppSetting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mails a new user (or a user whose password an admin reset) their sign-in e-mail and temporary password.
 * Sent immediately, never queued: a queued job would store the plain password in the jobs table.
 * The account has must_change_password set, so the first sign-in goes straight to "Change password".
 */
class AccountCredentials extends Notification
{
    public function __construct(public string $temporaryPassword, public bool $isReset = false)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = AppSetting::get('company_name') ?: config('app.name');

        return (new MailMessage)
            ->subject($this->isReset ? "Your {$company} store system password was reset" : "Your {$company} store system account")
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->isReset
                ? 'An administrator reset your password for the store system. Sign in with the temporary password below.'
                : 'An account has been created for you in the store system as '.$notifiable->role->label().'. Sign in with the details below.')
            ->line('**E-mail:** '.$notifiable->email)
            ->line('**Temporary password:** `'.$this->temporaryPassword.'`')
            ->action('Sign in', route('login'))
            ->line('You will be asked to choose your own password straight after signing in. The temporary password stops working once you change it.')
            ->line('If you were not expecting this e-mail, tell your administrator.');
    }
}
