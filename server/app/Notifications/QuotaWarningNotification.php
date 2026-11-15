<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent once a day when an account used 80 % of its daily quota. */
class QuotaWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $used, public int $limit) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fmt = fn (int $n) => number_format($n, 0, ',', ' ');

        return (new MailMessage)
            ->subject(__(':percent % of your daily quota used', ['percent' => (int) round($this->used / max(1, $this->limit) * 100)]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your account has published :used of its :limit messages today.', ['used' => $fmt($this->used), 'limit' => $fmt($this->limit)]))
            ->line(__('When the limit is reached, new messages are refused until midnight. Upgrade to keep your notifications flowing.'))
            ->action(__('See the plans'), route('account.plan'));
    }
}
