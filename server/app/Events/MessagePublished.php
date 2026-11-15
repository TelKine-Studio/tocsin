<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagePublished implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): Channel
    {
        $topic = $this->message->topic;

        return $topic->isPublic()
            ? new Channel($topic->channelName())
            : new PrivateChannel($topic->channelName());
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new MessageResource($this->message))->resolve();
    }
}
