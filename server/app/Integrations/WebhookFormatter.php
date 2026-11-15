<?php

namespace App\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the payload of a third-party tool into a Tocsin message
 * (body, title, priority, tags, click).
 */
class WebhookFormatter
{
    /** @return array<string, mixed> Input for PublishMessage. */
    public function format(string $source, Request $request): array
    {
        $payload = $request->isJson() ? $request->json()->all() : ($request->all() ?: null);

        if ($source === 'auto') {
            $source = $this->detect($request, $payload);
        }

        $message = match ($source) {
            'alertmanager', 'grafana' => $this->alertmanager($payload ?? []),
            'github' => $this->github((string) $request->header('X-GitHub-Event'), $payload ?? []),
            'gitlab' => $this->gitlab((string) $request->header('X-Gitlab-Event'), $payload ?? []),
            'uptime_kuma' => $this->uptimeKuma($payload ?? []),
            'slack' => $this->slack($payload ?? []),
            'discord' => $this->discord($payload ?? []),
            default => $this->generic($payload, $request->getContent()),
        };

        $message['body'] = Str::limit(trim((string) ($message['body'] ?? '')) ?: __('Webhook received.'), 3900);

        return array_filter($message, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    public function detect(Request $request, ?array $payload): string
    {
        return match (true) {
            $request->hasHeader('X-GitHub-Event') => 'github',
            $request->hasHeader('X-Gitlab-Event') => 'gitlab',
            is_array($payload['alerts'] ?? null) => 'alertmanager',
            isset($payload['heartbeat']) || isset($payload['monitor']) => 'uptime_kuma',
            isset($payload['embeds']) || (isset($payload['content']) && ! isset($payload['text'])) => 'discord',
            isset($payload['text']) || isset($payload['blocks']) => 'slack',
            default => 'generic',
        };
    }

    /** Prometheus Alertmanager and Grafana alerting (same webhook format). */
    protected function alertmanager(array $payload): array
    {
        $alerts = collect($payload['alerts'] ?? []);
        $status = strtolower((string) ($payload['status'] ?? $payload['state'] ?? 'firing'));
        $resolved = in_array($status, ['resolved', 'ok'], true);
        $common = $payload['commonAnnotations'] ?? [];
        $labels = $payload['commonLabels'] ?? [];
        $severity = strtolower((string) ($labels['severity'] ?? $alerts->first()['labels']['severity'] ?? ''));

        $title = $payload['title'] ?? (($resolved ? '✅ ' : '🔥 ').($labels['alertname'] ?? $alerts->first()['labels']['alertname'] ?? __('Alert')));

        $body = $payload['message'] ?? $alerts->map(function ($alert) {
            $annotations = $alert['annotations'] ?? [];
            $line = $annotations['summary'] ?? $annotations['description'] ?? ($alert['labels']['alertname'] ?? __('Alert'));

            return (count($alert['labels'] ?? []) > 0 && isset($alert['labels']['instance']) ? '['.$alert['labels']['instance'].'] ' : '').$line;
        })->filter()->implode("\n") ?: ($common['summary'] ?? $common['description'] ?? '');

        if ($alerts->count() > 1) {
            $title .= ' ('.$alerts->count().')';
        }

        return [
            'title' => $title,
            'body' => $body,
            'priority' => $resolved ? 3 : match ($severity) {
                'critical', 'page', 'emergency' => 5,
                'warning', 'error', 'high' => 4,
                'info', 'low' => 2,
                default => 4,
            },
            'tags' => array_filter([$resolved ? 'resolved' : 'firing', $severity ?: null]),
            'click' => $payload['externalURL'] ?? $payload['ruleUrl'] ?? $alerts->first()['generatorURL'] ?? null,
            // Repeated notifications of a still-firing alert are grouped, and
            // the "resolved" notification closes the firing one.
            'collapse' => ($group = $this->alertGroup($payload, $alerts)).'|'.($resolved ? 'resolved' : 'firing'),
            'resolves' => $resolved ? $group.'|firing' : null,
        ];
    }

    protected function github(string $event, array $p): array
    {
        $repo = $p['repository']['full_name'] ?? 'GitHub';
        $sender = $p['sender']['login'] ?? null;

        return match ($event) {
            'ping' => ['title' => $repo, 'body' => __('Webhook connected: :zen', ['zen' => $p['zen'] ?? 'ok']), 'tags' => ['github']],
            'push' => [
                'title' => "{$repo} · ".Str::after((string) ($p['ref'] ?? ''), 'refs/heads/'),
                'body' => trans_choice(':count new commit|:count new commits', count($p['commits'] ?? []))." ({$sender})\n".collect($p['commits'] ?? [])->take(5)->map(fn ($c) => '• '.Str::limit(strtok((string) $c['message'], "\n"), 80))->implode("\n"),
                'click' => $p['compare'] ?? null,
                'tags' => ['github', 'push'],
            ],
            'pull_request' => [
                'title' => "{$repo} · PR #".($p['number'] ?? '').' '.($p['action'] ?? ''),
                'body' => ($p['pull_request']['title'] ?? '').($sender ? " ({$sender})" : ''),
                'click' => $p['pull_request']['html_url'] ?? null,
                'tags' => ['github', 'pull-request'],
            ],
            'issues' => [
                'title' => "{$repo} · Issue #".($p['issue']['number'] ?? '').' '.($p['action'] ?? ''),
                'body' => ($p['issue']['title'] ?? '').($sender ? " ({$sender})" : ''),
                'click' => $p['issue']['html_url'] ?? null,
                'tags' => ['github', 'issue'],
            ],
            'release' => [
                'title' => "{$repo} · ".__('Release :name', ['name' => $p['release']['tag_name'] ?? '']),
                'body' => $p['release']['name'] ?? $p['release']['tag_name'] ?? '',
                'click' => $p['release']['html_url'] ?? null,
                'tags' => ['github', 'release'],
            ],
            'workflow_run' => [
                'title' => "{$repo} · ".($p['workflow_run']['name'] ?? 'Workflow'),
                'body' => __('Workflow :status on :branch', ['status' => $p['workflow_run']['conclusion'] ?? $p['workflow_run']['status'] ?? '', 'branch' => $p['workflow_run']['head_branch'] ?? '']),
                'priority' => ($p['workflow_run']['conclusion'] ?? null) === 'failure' ? 4 : 3,
                'click' => $p['workflow_run']['html_url'] ?? null,
                'tags' => ['github', $p['workflow_run']['conclusion'] ?? 'workflow'],
            ],
            default => ['title' => "{$repo} · {$event}", 'body' => ($p['action'] ?? $event).($sender ? " ({$sender})" : ''), 'tags' => ['github']],
        };
    }

    protected function gitlab(string $event, array $p): array
    {
        $project = $p['project']['path_with_namespace'] ?? 'GitLab';
        $user = $p['user_name'] ?? $p['user']['name'] ?? null;

        return match ($event) {
            'Push Hook' => [
                'title' => "{$project} · ".Str::after((string) ($p['ref'] ?? ''), 'refs/heads/'),
                'body' => trans_choice(':count new commit|:count new commits', (int) ($p['total_commits_count'] ?? 0)).($user ? " ({$user})" : ''),
                'tags' => ['gitlab', 'push'],
            ],
            'Merge Request Hook' => [
                'title' => "{$project} · MR !".($p['object_attributes']['iid'] ?? '').' '.($p['object_attributes']['action'] ?? ''),
                'body' => $p['object_attributes']['title'] ?? '',
                'click' => $p['object_attributes']['url'] ?? null,
                'tags' => ['gitlab', 'merge-request'],
            ],
            'Pipeline Hook' => [
                'title' => "{$project} · Pipeline ".($p['object_attributes']['status'] ?? ''),
                'body' => ($p['object_attributes']['ref'] ?? '').($user ? " ({$user})" : ''),
                'priority' => ($p['object_attributes']['status'] ?? null) === 'failed' ? 4 : 3,
                'tags' => ['gitlab', $p['object_attributes']['status'] ?? 'pipeline'],
            ],
            default => ['title' => "{$project} · {$event}", 'body' => $p['object_kind'] ?? $event, 'tags' => ['gitlab']],
        };
    }

    protected function uptimeKuma(array $p): array
    {
        $up = (int) ($p['heartbeat']['status'] ?? 1) === 1;
        $name = $p['monitor']['name'] ?? 'Uptime Kuma';

        return [
            'title' => ($up ? '✅ ' : '🔴 ').$name,
            'body' => $p['msg'] ?? $p['heartbeat']['msg'] ?? ($up ? __('Up') : __('Down')),
            'priority' => $up ? 3 : 5,
            'tags' => [$up ? 'up' : 'down'],
            'click' => $p['monitor']['url'] ?? null,
            'collapse' => 'kuma:'.$name.'|'.($up ? 'up' : 'down'),
            'resolves' => $up ? 'kuma:'.$name.'|down' : null,
        ];
    }

    /** Stable identifier of an Alertmanager / Grafana alert group. */
    protected function alertGroup(array $payload, Collection $alerts): string
    {
        if (is_string($payload['groupKey'] ?? null) && $payload['groupKey'] !== '') {
            return 'alert:'.$payload['groupKey'];
        }

        return 'alert:'.($payload['commonLabels']['alertname'] ?? $alerts->first()['labels']['alertname'] ?? $payload['title'] ?? 'alert');
    }

    /** Slack incoming webhook format (used by Coolify and many other tools). */
    protected function slack(array $p): array
    {
        $blocks = collect($p['blocks'] ?? [])
            ->map(fn ($block) => $block['text']['text'] ?? collect($block['fields'] ?? [])->pluck('text')->implode("\n"))
            ->filter();
        $attachments = collect($p['attachments'] ?? [])
            ->map(fn ($a) => trim(($a['title'] ?? '')."\n".($a['text'] ?? $a['fallback'] ?? '')))
            ->filter();

        $text = $this->stripMrkdwn((string) ($p['text'] ?? ''));
        $details = $this->stripMrkdwn($blocks->merge($attachments)->implode("\n\n"));
        [$title, $body] = $this->splitTitle($text !== '' ? $text."\n".$details : $details);

        return ['title' => $title, 'body' => $body];
    }

    /** Discord webhook format. */
    protected function discord(array $p): array
    {
        $embed = $p['embeds'][0] ?? [];
        $fields = collect($embed['fields'] ?? [])->map(fn ($f) => ($f['name'] ?? '').': '.($f['value'] ?? ''))->implode("\n");

        return [
            'title' => $embed['title'] ?? $p['username'] ?? null,
            'body' => trim(($p['content'] ?? '')."\n".($embed['description'] ?? '')."\n".$fields),
            'click' => $embed['url'] ?? null,
        ];
    }

    protected function generic(?array $payload, string $raw): array
    {
        if ($payload === null) {
            return ['body' => $raw];
        }

        $body = Arr::first([$payload['message'] ?? null, $payload['body'] ?? null, $payload['text'] ?? null, $payload['msg'] ?? null, $payload['description'] ?? null], fn ($v) => is_string($v) && $v !== '');

        return [
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : ($payload['subject'] ?? null),
            'body' => $body ?? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'priority' => $payload['priority'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'click' => is_string($payload['url'] ?? null) ? $payload['url'] : ($payload['click'] ?? null),
        ];
    }

    protected function stripMrkdwn(string $text): string
    {
        // <https://url|label> → label, *bold* → bold
        $text = preg_replace('/<(https?:[^|>]+)\|([^>]+)>/', '$2', $text);

        return trim(preg_replace('/[*_~`]{1,3}([^*_~`]+)[*_~`]{1,3}/', '$1', $text));
    }

    /** @return array{0: ?string, 1: string} First short line as title. */
    protected function splitTitle(string $text): array
    {
        $lines = preg_split('/\R/', trim($text));

        if (count($lines) > 1 && mb_strlen($lines[0]) <= 120) {
            return [array_shift($lines), trim(implode("\n", $lines))];
        }

        return [null, trim($text)];
    }
}
