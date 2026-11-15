<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');
        $inviter = $this->invitation->inviter?->name;

        return (new MailMessage)
            ->subject(__('Your account on :app', ['app' => $app]))
            ->greeting($this->invitation->name ? __('Hello :name,', ['name' => $this->invitation->name]) : __('Hello!'))
            ->line($inviter
                ? __(':inviter invited you to join :app, a self-hosted notification server.', ['inviter' => $inviter, 'app' => $app])
                : __('You have been invited to join :app, a self-hosted notification server.', ['app' => $app]))
            ->line(__('Choose your password to activate your account.'))
            ->action(__('Choose my password'), $this->url)
            ->line(__('This invitation expires on :date.', ['date' => $this->invitation->expires_at->isoFormat('LL')]));
    }
}
