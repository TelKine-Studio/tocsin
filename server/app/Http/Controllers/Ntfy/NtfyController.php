<?php

namespace App\Http\Controllers\Ntfy;

use App\Actions\PublishMessage;
use App\Enums\TopicVisibility;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Topic;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ntfy-compatible HTTP API: existing scripts keep working by only changing
 * the server URL (curl -d "Backup done" https://tocsin.example.com/backups).
 *
 * @see https://docs.ntfy.sh/publish/
 */
class NtfyController extends Controller
{
    /** First URL segments that can never be topic names. */
    public const RESERVED = [
        'admin', 'api', 'app', 'apps', 'attachments', 'auth', 'broadcasting', 'build', 'create', 'dashboard',
        'docs', 'favicon.ico', 'favicon.svg', 'file', 'forgot-password', 'hooks', 'invitations', 'json', 'login', 'logout',
        'profile', 'raw', 'register', 'reset-password', 'robots.txt', 'settings', 'setup', 'sse', 'static',
        'storage', 'topics', 'up', 'v1', 'ws',
    ];

    public function __construct(protected Settings $settings, protected PublishMessage $publisher) {}

    /** PUT|POST /{topic} — the body is the message (or a file attachment). */
    public function publish(Request $request, string $topic): JsonResponse
    {
        $topic = $this->resolveTopic($request, $topic, forWriting: true);
        $input = $this->fieldsFromRequest($request);
        $body = $request->getContent();

        $isFile = $request->hasHeader('X-Filename') || $request->hasHeader('Filename') || $request->query('filename') !== null
            || ($body !== '' && ! mb_check_encoding($body, 'UTF-8'))
            || strlen($body) > $this->settings->integer('messages.max_length', 4096);

        if ($isFile && $body !== '') {
            $input['file'] = ['contents' => $body, 'name' => $input['filename'] ?? null, 'type' => $request->header('Content-Type')];
            $input['body'] ??= __('You received a file: :name', ['name' => $input['filename'] ?? 'attachment']);
        } else {
            $input['body'] ??= $body !== '' ? $body : __('triggered');
        }

        return $this->publishTo($topic, $input, $request);
    }

    /** GET /{topic}/publish|send|trigger — publish with query parameters (webhooks). */
    public function publishFromQuery(Request $request, string $topic): JsonResponse
    {
        $topic = $this->resolveTopic($request, $topic, forWriting: true);
        $input = $this->fieldsFromRequest($request);
        $input['body'] ??= __('triggered');

        return $this->publishTo($topic, $input, $request);
    }

    /** POST / — JSON publishing ({"topic": "...", "message": "...", ...}). */
    public function publishJson(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        if (! is_string($data['topic'] ?? null)) {
            return $this->error(400, 40001, 'invalid request: topic missing');
        }

        $topic = $this->resolveTopic($request, $data['topic'], forWriting: true);

        return $this->publishTo($topic, [
            'body' => $data['message'] ?? __('triggered'),
            'title' => $data['title'] ?? null,
            'priority' => $data['priority'] ?? null,
            'tags' => $data['tags'] ?? null,
            'click' => $data['click'] ?? null,
            'actions' => $data['actions'] ?? null,
            'icon' => $data['icon'] ?? null,
            'attach' => $data['attach'] ?? null,
            'filename' => $data['filename'] ?? null,
            'delay' => $data['delay'] ?? null,
            'collapse' => $data['collapse'] ?? null,
            'ack' => $data['ack'] ?? null,
            'ack_group' => $data['ack_group'] ?? null,
        ], $request);
    }

    /**
     * GET /{topic}/json|sse|raw — cached messages. Tocsin delivers in real
     * time through push notifications and WebSocket, so these endpoints
     * always answer in "poll" mode (?poll=1&since=...).
     */
    public function poll(Request $request, string $topic, string $format): Response|StreamedResponse
    {
        $topic = $this->resolveTopic($request, $topic, forWriting: false);

        $messages = $topic->messages()
            ->delivered()
            ->when($this->since($request->query('since')), fn ($query, array $since) => $query->where($since[0], '>', $since[1]))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each->setRelation('topic', $topic);

        return match ($format) {
            'raw' => response($messages->map(fn (Message $m) => str_replace("\n", ' ', $m->body))->implode("\n").($messages->isEmpty() ? '' : "\n"), 200, ['Content-Type' => 'text/plain; charset=utf-8']),
            'sse' => response($messages->map(fn (Message $m) => "event: message\ndata: ".json_encode($this->format($m), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n")->implode(''), 200, ['Content-Type' => 'text/event-stream']),
            default => response($messages->map(fn (Message $m) => json_encode($this->format($m), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->implode("\n").($messages->isEmpty() ? '' : "\n"), 200, ['Content-Type' => 'application/x-ndjson; charset=utf-8']),
        };
    }

    /** GET /{topic}/auth — checks read access, like ntfy. */
    public function auth(Request $request, string $topic): JsonResponse
    {
        $this->resolveTopic($request, $topic, forWriting: false);

        return response()->json(['success' => true]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['healthy' => true]);
    }

    /** GET /attachments/{message}/{name} — signed URL of a stored attachment. */
    /** GET /v1/topics/{topic}/icon — logo of a topic (public, cached). */
    public function icon(string $topic): StreamedResponse
    {
        $topic = Topic::where('name', $topic)->firstOrFail();

        abort_unless($topic->icon_path && Storage::disk('local')->exists($topic->icon_path), 404);

        $extension = pathinfo($topic->icon_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->response($topic->icon_path, $topic->name.'.'.$extension, [
            'Content-Type' => array_search($extension, Topic::ICON_TYPES, true) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }

    public function attachment(Message $message): StreamedResponse
    {
        abort_unless($message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404);

        // Only harmless types are displayed inline: anything else (HTML, SVG,
        // scripts...) is downloaded, so an attachment can never run in this origin.
        $type = (string) $message->attachment_type;
        $inline = in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic', 'application/pdf', 'text/plain'], true);

        return Storage::disk('local')->response($message->attachment_path, $message->attachment_name, [
            'Content-Type' => $inline ? $type : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    protected function publishTo(Topic $topic, array $input, Request $request): JsonResponse
    {
        try {
            $message = $this->publisher->handle($topic, $input, $request->user());
        } catch (ValidationException $e) {
            return $this->error(400, 40000, 'invalid request: '.collect($e->errors())->flatten()->first());
        } catch (QuotaExceededException $e) {
            // Same code as ntfy's "daily message quota reached".
            return $e->reason === 'quota'
                ? $this->error(429, 42908, 'limit reached: daily message quota, upgrade at '.$e->upgradeUrl())
                : $this->error(403, 40301, 'forbidden: '.$e->getMessage());
        }

        return response()->json($this->format($message->setRelation('topic', $topic)), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the topic, creating it on the fly for authorized users when
     * "Create topics on first publish" is enabled (as ntfy does).
     */
    protected function resolveTopic(Request $request, string $name, bool $forWriting): Topic
    {
        $name = mb_strtolower($name);
        $user = $request->user();

        if (! preg_match(Topic::NAME_PATTERN, $name) || in_array($name, self::RESERVED, true)) {
            abort($this->error(400, 40009, 'invalid topic name'));
        }

        $topic = Topic::query()->where('name', $name)->first();

        $canCreate = ! $topic && $forWriting && $user && $this->settings->boolean('topics.auto_create')
            ? Gate::forUser($user)->inspect('create', Topic::class)
            : null;

        // Hosted mode: tell why the topic cannot be created (plan limit).
        if ($canCreate?->denied() && $canCreate->message()) {
            abort($this->error(403, 40301, 'forbidden: '.$canCreate->message()));
        }

        if ($canCreate?->allowed()) {
            $topic = Topic::create([
                'name' => $name,
                'visibility' => TopicVisibility::Private,
                'owner_id' => $user->id,
            ]);
        }

        if (! $topic) {
            abort($user
                ? $this->error(404, 40401, 'topic not found')
                : $this->error(401, 40101, 'unauthorized: topic not found or authentication required'));
        }

        $allowed = $forWriting ? $topic->canWrite($user) : $topic->canRead($user);

        if (! $allowed) {
            abort($user ? $this->error(403, 40301, 'forbidden') : $this->error(401, 40101, 'unauthorized'));
        }

        return $topic;
    }

    /**
     * Message fields from headers (X-Title, Title, t, ...) and query parameters.
     *
     * @return array<string, mixed>
     */
    protected function fieldsFromRequest(Request $request): array
    {
        $value = function (array $names) use ($request): ?string {
            foreach ($names as $name) {
                $header = $request->header($name);
                if (filled($header)) {
                    return $this->decodeHeader($header);
                }
            }
            foreach ($names as $name) {
                if (filled($request->query(strtolower($name)))) {
                    return (string) $request->query(strtolower($name));
                }
            }

            return null;
        };

        return array_filter([
            'body' => $value(['X-Message', 'Message', 'm']),
            'title' => $value(['X-Title', 'Title', 't']),
            'priority' => $value(['X-Priority', 'Priority', 'prio', 'p']),
            'tags' => $value(['X-Tags', 'Tags', 'Tag', 'ta']),
            'click' => $value(['X-Click', 'Click']),
            'actions' => $value(['X-Actions', 'Actions', 'Action']),
            'icon' => $value(['X-Icon', 'Icon']),
            'attach' => $value(['X-Attach', 'Attach', 'a']),
            'filename' => $value(['X-Filename', 'Filename', 'file', 'f']),
            'delay' => $value(['X-Delay', 'Delay', 'X-At', 'At', 'X-In', 'In']),
            'collapse' => $value(['X-Collapse', 'Collapse', 'X-Group', 'Group']),
            'ack' => $value(['X-Ack', 'Ack']),
            'ack_group' => $value(['X-Ack-Group', 'Ack-Group']),
        ], fn ($v) => $v !== null);
    }

    /** ntfy accepts RFC 2047 encoded headers for non-ASCII titles. */
    protected function decodeHeader(string $value): string
    {
        return str_starts_with($value, '=?') ? (iconv_mime_decode($value, 0, 'UTF-8') ?: $value) : $value;
    }

    /** @return array{0: string, 1: mixed}|null */
    protected function since(mixed $since): ?array
    {
        if ($since === null || $since === '' || $since === 'all') {
            return null;
        }

        if ($since === 'latest') {
            return ['id', (int) Message::query()->max('id') - 1];
        }

        if (preg_match('/^\d{9,}$/', (string) $since)) {
            return ['dispatched_at', now()->setTimestamp((int) $since)];
        }

        if (preg_match('/^(\d+)([smhd])$/', (string) $since, $m)) {
            return ['dispatched_at', now()->sub(match ($m[2]) {
                's' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days'
            }, (int) $m[1])];
        }

        return ctype_digit((string) $since) ? ['id', (int) $since] : null;
    }

    /** @return array<string, mixed> Message in ntfy's JSON format. */
    protected function format(Message $message): array
    {
        $retention = $this->settings->integer('messages.retention_days');
        $time = ($message->dispatched_at ?? $message->scheduled_at ?? $message->created_at)->getTimestamp();

        return array_filter([
            'id' => (string) $message->id,
            'time' => $time,
            'expires' => $retention > 0 ? $time + $retention * 86400 : null,
            'event' => 'message',
            'topic' => $message->topic->name,
            'title' => $message->title,
            'message' => $message->body,
            'priority' => $message->priority->value === 3 ? null : $message->priority->value,
            'tags' => $message->tags ?: null,
            'click' => $message->click_url,
            'actions' => $message->actionsWithAcknowledgement() ?: null,
            'icon' => $message->icon_url,
            'attachment' => ($url = $message->attachmentUrl()) ? array_filter([
                'name' => $message->attachment_name,
                'url' => $url,
                'type' => $message->attachment_type,
                'size' => $message->attachment_size,
            ]) : null,
            // Tocsin extensions.
            'occurrences' => $message->occurrences > 1 ? $message->occurrences : null,
            'ack' => $message->requires_ack ? array_filter([
                'acknowledged' => $message->isAcknowledged(),
                'by' => $message->acknowledgedByName(),
                'at' => $message->acknowledged_at?->getTimestamp(),
            ], fn ($value) => $value !== null) : null,
        ], fn ($value) => $value !== null);
    }

    protected function error(int $http, int $code, string $error): JsonResponse
    {
        return response()->json(['code' => $code, 'http' => $http, 'error' => $error, 'link' => config('tocsin.repository').'/blob/main/docs/ntfy.md'], $http);
    }
}
