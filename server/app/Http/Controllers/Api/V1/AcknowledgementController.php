<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AcknowledgeMessage;
use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/messages/{message}/ack?signature=… — the "Acknowledge" button
 * of notifications. The signed URL is only sent to subscribed devices; an
 * optional token or password tells who acknowledged.
 */
class AcknowledgementController extends Controller
{
    public function __invoke(Request $request, Message $message, AcknowledgeMessage $acknowledge): JsonResponse
    {
        $message->loadMissing('topic');

        if (! $message->requires_ack) {
            return response()->json(['message' => __('This message does not need an acknowledgement.')], 422);
        }

        $user = $request->user();
        $name = $request->header('X-Name') ?? $request->input('name');

        $acknowledged = $acknowledge->handle($message, $user && $message->topic->canRead($user) ? $user : null, is_string($name) ? $name : null);

        return response()->json([
            'acknowledged' => true,
            'already' => ! $acknowledged,
            'by' => $message->acknowledgedByName(),
            'at' => $message->acknowledged_at?->toIso8601String(),
        ]);
    }
}
