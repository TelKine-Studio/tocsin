<?php

namespace App\Actions;

use App\Enums\Priority;
use App\Events\MessagePublished;
use App\Events\MessageUpdated;
use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Support\Ntfy\ActionParser;
use App\Support\Ntfy\DelayParser;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublishMessage
{
    /** Scheduled messages can be delayed up to this many days. */
    public const MAX_DELAY_DAYS = 30;

    /** Longest accepted X-Collapse key. */
    public const MAX_COLLAPSE_LENGTH = 150;

    public function __construct(protected Settings $settings, protected AcknowledgeMessage $acknowledge) {}

    /**
     * Validate, store and deliver (or schedule) a message.
     *
     * Input keys: body, title, priority, tags, click, actions, icon, attach
     * (URL), filename, file (['contents' => string, 'name' => ?string,
     * 'type' => ?string]), delay, collapse (grouping key), ack (true / false
     * to force or skip the acknowledgement) and resolves (collapse key of
     * an alert this message closes).
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(Topic $topic, array $input, ?User $author = null): Message
    {
        $normalized = $this->normalize($input);

        $data = Validator::make($normalized, [
            'body' => ['required', 'string', 'max:'.$this->settings->integer('messages.max_length', 4096)],
            'title' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::enum(Priority::class)],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'max:32'],
            'click' => ['nullable', 'url:http,https', 'max:2048'],
            'icon' => ['nullable', 'url:http,https', 'max:2048'],
            'attach' => ['nullable', 'url:http,https', 'max:2048'],
            'filename' => ['nullable', 'string', 'max:200'],
        ], [
            'priority.*' => __('The priority must be a number between 1 and 5, or one of min, low, default, high, urgent.'),
        ])->validate();

        $scheduledAt = $this->scheduledAt($input['delay'] ?? null);
        $hasFile = isset($input['file']) && is_array($input['file']) && ($input['file']['contents'] ?? '') !== '';
        $requiresAck = $this->requiresAcknowledgement($topic, $input['ack'] ?? null, (int) $data['priority']);
        $collapseKey = $this->collapseKey($topic, $normalized, $input['collapse'] ?? null, $hasFile || filled($data['attach'] ?? null));

        if (filled($input['resolves'] ?? null)) {
            $this->resolve($topic, (string) $input['resolves']);
        }

        if ($scheduledAt === null && $collapseKey !== null && ($existing = $this->findGroup($topic, $collapseKey))) {
            return $this->group($existing->setRelation('topic', $topic), $data, $normalized, $requiresAck);
        }

        $message = $topic->messages()->create([
            'user_id' => $author?->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'priority' => $data['priority'],
            'tags' => $data['tags'] ?: null,
            'click_url' => $data['click'] ?? null,
            'actions' => $normalized['actions'] ?: null,
            'icon_url' => $data['icon'] ?? null,
            'attachment_url' => $data['attach'] ?? null,
            'attachment_name' => $data['attach'] ?? null ? ($data['filename'] ?? basename(parse_url($data['attach'], PHP_URL_PATH) ?: 'file')) : null,
            'scheduled_at' => $scheduledAt,
            'collapse_key' => $collapseKey,
            'requires_ack' => $requiresAck,
        ]);

        if ($hasFile) {
            $this->storeAttachment($message, $input['file'], $data['filename'] ?? null);
        }

        $message->setRelation('topic', $topic);

        if ($scheduledAt === null) {
            $this->deliver($message);
        }

        return $message;
    }

    /**
     * Makes a stored message visible and sends it: realtime broadcast and
     * push notifications. Called immediately, or later for scheduled ones.
     */
    public function deliver(Message $message): void
    {
        $now = now();
        $firstStep = $message->requires_ack ? ($message->topic->escalationSteps()[0] ?? null) : null;

        $message->forceFill([
            'dispatched_at' => $now,
            'last_seen_at' => $now,
            'next_escalation_at' => $firstStep ? $now->copy()->addMinutes($firstStep['after']) : null,
        ])->save();
        $message->topic->forceFill(['last_message_at' => $message->dispatched_at])->save();

        SendMessagePush::dispatch($message);

        try {
            broadcast(new MessagePublished($message));
        } catch (Throwable $e) {
            // The message is stored either way: clients that are not connected
            // in realtime fetch it with their next refresh.
            Log::warning('Unable to broadcast message', ['topic' => $message->topic->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Grouping key of a message: the X-Collapse key, or a hash of its
     * content so that identical messages are grouped automatically.
     * The key is kept even when grouping is disabled on the topic (window
     * of 0), so that "resolved" alerts still find their firing alert.
     *
     * @param  array<string, mixed>  $normalized
     */
    protected function collapseKey(Topic $topic, array $normalized, mixed $collapse, bool $hasAttachment): ?string
    {
        if (is_scalar($collapse) && trim((string) $collapse) !== '') {
            return 'key:'.mb_substr(trim((string) $collapse), 0, self::MAX_COLLAPSE_LENGTH);
        }

        if ($hasAttachment || $topic->group_window <= 0) {
            return null;
        }

        return 'auto:'.sha1(json_encode([$normalized['title'], $normalized['body'], (int) $normalized['priority'], $normalized['tags']]));
    }

    protected function findGroup(Topic $topic, string $collapseKey): ?Message
    {
        if ($topic->group_window <= 0) {
            return null;
        }

        return $topic->messages()
            ->delivered()
            ->where('collapse_key', $collapseKey)
            ->where('last_seen_at', '>=', now()->subMinutes($topic->group_window))
            ->latest('id')
            ->first();
    }

    /**
     * Counts a new occurrence on an existing message instead of creating
     * a new one. Subscribers are notified again only when the priority
     * rises: the counter is updated in the apps in real time.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $normalized
     */
    protected function group(Message $message, array $data, array $normalized, bool $requiresAck): Message
    {
        $priority = (int) $data['priority'];
        $raised = $priority > $message->priority->value;
        $now = now();

        $message->forceFill([
            'occurrences' => $message->occurrences + 1,
            'last_seen_at' => $now,
            // With an explicit key, the latest content wins ("CPU at 97%").
            'title' => $data['title'] ?? $message->title,
            'body' => $data['body'],
            'tags' => $data['tags'] ?: $message->tags,
            'click_url' => $data['click'] ?? $message->click_url,
            'actions' => $normalized['actions'] ?: $message->actions,
            'priority' => max($priority, $message->priority->value),
        ]);

        // The alert became serious enough to require an acknowledgement.
        if (($requiresAck || $message->topic->requiresAcknowledgement($message->priority->value)) && ! $message->requires_ack && ! $message->isAcknowledged()) {
            $firstStep = $message->topic->escalationSteps()[0] ?? null;
            $message->forceFill([
                'requires_ack' => true,
                'next_escalation_at' => $firstStep ? $now->copy()->addMinutes($firstStep['after']) : null,
            ]);
            $raised = true;
        }

        $message->save();
        $message->topic->forceFill(['last_message_at' => $now])->save();

        if ($raised) {
            SendMessagePush::dispatch($message);
        }

        MessageUpdated::safelyBroadcast($message);

        return $message;
    }

    /** A "resolved" alert closes (acknowledges) the matching firing alert. */
    protected function resolve(Topic $topic, string $collapseKey): void
    {
        $firing = $topic->messages()
            ->delivered()
            ->where('collapse_key', 'key:'.mb_substr($collapseKey, 0, self::MAX_COLLAPSE_LENGTH))
            ->where('requires_ack', true)
            ->whereNull('acknowledged_at')
            ->latest('id')
            ->first();

        if ($firing) {
            $this->acknowledge->handle($firing->setRelation('topic', $topic), null, Message::RESOLVED);
        }
    }

    protected function requiresAcknowledgement(Topic $topic, mixed $ack, int $priority): bool
    {
        if ($ack !== null && $ack !== '') {
            $forced = filter_var($ack, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($forced !== null) {
                return $forced;
            }
        }

        return $topic->requiresAcknowledgement($priority);
    }

    protected function scheduledAt(mixed $delay): ?\DateTimeInterface
    {
        if ($delay === null || $delay === '') {
            return null;
        }

        $at = DelayParser::parse($delay);

        if ($at === null) {
            throw ValidationException::withMessages(['delay' => __('Invalid delay. Use a duration (30m, 2h, 1d), a Unix timestamp or a date.')]);
        }

        if ($at->isBefore(now()->addSeconds(10))) {
            return null;
        }

        if ($at->isAfter(now()->addDays(self::MAX_DELAY_DAYS))) {
            throw ValidationException::withMessages(['delay' => __('A message can be delayed by :days days at most.', ['days' => self::MAX_DELAY_DAYS])]);
        }

        return $at;
    }

    /** @param array{contents: string, name?: ?string, type?: ?string} $file */
    protected function storeAttachment(Message $message, array $file, ?string $filename): void
    {
        $maxBytes = $this->settings->integer('attachments.max_size_mb', 10) * 1024 * 1024;
        $size = strlen($file['contents']);

        if ($size > $maxBytes) {
            $message->delete();

            throw ValidationException::withMessages(['file' => __('The attachment is too large (max. :size MB).', ['size' => $maxBytes / 1024 / 1024])]);
        }

        $name = Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $filename ?? $file['name'] ?? 'attachment') ?: 'attachment', 150, '');
        $path = 'attachments/'.$message->topic_id.'/'.Str::ulid().'/'.$name;

        Storage::disk('local')->put($path, $file['contents']);

        $message->forceFill([
            'attachment_path' => $path,
            'attachment_name' => $name,
            'attachment_type' => $file['type'] ?? (new \finfo(FILEINFO_MIME_TYPE))->buffer($file['contents']) ?: 'application/octet-stream',
            'attachment_size' => $size,
            'attachment_url' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalize(array $input): array
    {
        $tags = $input['tags'] ?? [];

        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        $priority = $input['priority'] ?? null;
        $text = fn (string $key) => filled($input[$key] ?? null) ? trim((string) $input[$key]) : null;

        return [
            'body' => is_string($input['body'] ?? null) ? trim($input['body']) : ($input['body'] ?? null),
            'title' => $text('title'),
            'priority' => ($priority === null || $priority === '') ? Priority::Default->value : (Priority::parse($priority)?->value ?? $priority),
            'tags' => array_values(array_filter(array_map(fn ($tag) => is_string($tag) ? trim($tag) : $tag, (array) $tags), fn ($tag) => $tag !== '' && $tag !== null)),
            'click' => $text('click'),
            'icon' => $text('icon'),
            'attach' => $text('attach'),
            'filename' => $text('filename'),
            'actions' => ActionParser::parse($input['actions'] ?? null),
        ];
    }
}
