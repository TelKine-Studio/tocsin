<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic->name,
            'title' => $this->title,
            'message' => $this->body,
            'priority' => $this->priority->value,
            'tags' => $this->tags ?? [],
            'click' => $this->click_url,
            'actions' => $this->actionsWithAcknowledgement(),
            'icon' => $this->topic->iconFor($this->resource),
            'attachment' => ($url = $this->attachmentUrl()) ? [
                'name' => $this->attachment_name,
                'url' => $url,
                'type' => $this->attachment_type,
                'size' => $this->attachment_size,
            ] : null,
            'delivery' => $this->push_status ? [
                'status' => $this->push_status,
                'devices' => $this->push_count,
                'failed' => $this->push_failed,
            ] : null,
            'occurrences' => $this->occurrences ?? 1,
            'last_seen' => $this->last_seen_at?->getTimestamp(),
            // Each send of a grouped message, oldest first.
            'repeats' => ($log = $this->repeatLog()) ? array_map(fn (array $repeat) => [
                'time' => $repeat['time']->getTimestamp(),
                'title' => $repeat['title'],
                'message' => $repeat['body'],
                'priority' => $repeat['priority'],
            ], $log) : null,
            'ack' => $this->requires_ack ? [
                'acknowledged' => $this->isAcknowledged(),
                'by' => $this->acknowledgedByName(),
                'resolved' => $this->wasResolved(),
                'label' => $this->acknowledgementLabel(),
                'at' => $this->acknowledged_at?->getTimestamp(),
                'url' => $this->isAcknowledged() ? null : $this->acknowledgeUrl(),
                'reminders' => $this->escalation_level,
                'next_reminder' => $this->next_escalation_at?->getTimestamp(),
            ] : null,
            'time' => ($this->dispatched_at ?? $this->created_at)?->getTimestamp(),
            'created_at' => ($this->dispatched_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
