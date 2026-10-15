<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to a newly invited staff member with a link to set their password (task 2.4).
 * The token comes from the 'invites' password broker, valid for 7 days.
 */
class StaffInvitation extends Notification
{
    public function __construct(public string $token, public string $role) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invite.accept', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('You have been invited to join :app as :role.', ['app' => config('app.name'), 'role' => $this->role]))
            ->action(__('Set your password'), $url)
            ->line(__('This link expires in 7 days. If you were not expecting this invitation, you can ignore this email.'));
    }
}
