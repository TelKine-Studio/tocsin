<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Topic */
class TopicResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'name' => $this->name,
            'display_name' => $this->label(),
            'description' => $this->description,
            'icon' => $this->iconUrl(),
            'visibility' => $this->visibility->value,
            'anonymous_publish' => $this->anonymous_publish,
            'channel' => $this->clientChannelName(),
            'permissions' => [
                'read' => $this->canRead($user),
                'write' => $this->canWrite($user),
                'manage' => $this->isManagedBy($user),
            ],
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
