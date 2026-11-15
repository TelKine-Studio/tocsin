<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A message changed after it was published: new occurrence of a grouped
 * alert, acknowledgement or escalation. Clients replace it in their list.
 */
class MessageUpdated extends MessagePublished
{
    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /** Realtime is best effort: clients catch up with their next refresh. */
    public static function safelyBroadcast(Message $message): void
    {
        try {
            broadcast(new static($message));
        } catch (Throwable $e) {
            Log::warning('Unable to broadcast message update', ['message' => $message->id, 'error' => $e->getMessage()]);
        }
    }
}
