<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Emailed to the people of an escalation step when nobody acknowledged a message. */
class EscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Message $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message;
        $topic = $message->topic;
        $title = $message->title ?: $topic->label();
        $minutes = (int) $message->dispatched_at?->diffInMinutes(now(), true);

        $mail = (new MailMessage)
            ->subject(__('[:topic] Not acknowledged: :title', ['topic' => $topic->label(), 'title' => Str::limit($title, 80)]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(trans_choice('Nobody has acknowledged this message of :topic for :count minute.|Nobody has acknowledged this message of :topic for :count minutes.', max(1, $minutes), ['topic' => $topic->label()]))
            ->line('**'.e($title).'**')
            ->line(e(Str::limit($message->body, 1000)));

        if ($message->occurrences > 1) {
            $mail->line(__('Received :count times.', ['count' => $message->occurrences]));
        }

        return $mail
            ->action(__('Open and acknowledge'), route('topics.show', $topic).'#message-'.$message->id)
            ->line(__('You receive this email because you are part of the escalation of this topic.'));
    }
}
