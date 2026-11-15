<?php

namespace App\Support\Ntfy;

/**
 * Parses notification actions, in ntfy's short format or as JSON:
 *
 *   view, Open portal, https://example.com, clear=true; http, Close door, https://api/door, method=PUT, body={"open":false}
 *
 * Supported actions: "view" (open a URL) and "http" (send a request).
 *
 * @see https://docs.ntfy.sh/publish/#action-buttons
 */
class ActionParser
{
    public const MAX_ACTIONS = 3;

    /**
     * @return list<array{action: string, label: string, url: string, method?: string, headers?: array<string, string>, body?: string, clear?: bool}>
     */
    public static function parse(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value) && str_starts_with(ltrim($value), '[')) {
            $value = json_decode($value, true) ?? [];
        }

        $actions = is_array($value)
            ? array_map(fn ($action) => is_array($action) ? $action : [], $value)
            : array_map(self::parseShort(...), array_filter(array_map('trim', explode(';', (string) $value))));

        return array_values(array_filter(array_map(self::normalize(...), array_slice($actions, 0, self::MAX_ACTIONS))));
    }

    /** @return array<string, mixed> */
    protected static function parseShort(string $definition): array
    {
        $parts = array_map('trim', str_getcsv($definition, ',', '"', '\\'));
        $action = ['action' => strtolower($parts[0] ?? ''), 'label' => $parts[1] ?? '', 'url' => $parts[2] ?? ''];

        foreach (array_slice($parts, 3) as $option) {
            if (! str_contains($option, '=')) {
                continue;
            }

            [$key, $optionValue] = array_map('trim', explode('=', $option, 2));

            if (str_starts_with($key, 'headers.')) {
                $action['headers'][substr($key, 8)] = $optionValue;
            } else {
                $action[$key] = $optionValue;
            }
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    protected static function normalize(array $action): ?array
    {
        $type = strtolower((string) ($action['action'] ?? ''));
        $label = trim((string) ($action['label'] ?? ''));
        $url = trim((string) ($action['url'] ?? ''));

        if (! in_array($type, ['view', 'http'], true) || $label === '' || ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $normalized = [
            'action' => $type,
            'label' => mb_substr($label, 0, 40),
            'url' => $url,
            'clear' => filter_var($action['clear'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        if ($type === 'http') {
            $normalized['method'] = strtoupper((string) ($action['method'] ?? 'POST'));
            $headers = array_map('strval', (array) ($action['headers'] ?? []));
            if ($headers !== []) {
                $normalized['headers'] = $headers;
            }
            if (($body = mb_substr((string) ($action['body'] ?? ''), 0, 1000)) !== '') {
                $normalized['body'] = $body;
            }
        }

        return $normalized;
    }
}
