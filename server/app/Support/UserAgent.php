<?php

namespace App\Support;

/**
 * Short, readable description of a User-Agent ("Chrome on macOS").
 * Good enough for an audit log; not meant to detect every device.
 */
class UserAgent
{
    public static function describe(?string $agent): ?string
    {
        $agent = trim((string) $agent);

        if ($agent === '') {
            return null;
        }

        foreach (['curl', 'Wget', 'PostmanRuntime', 'insomnia', 'HTTPie', 'GuzzleHttp', 'python-requests', 'Go-http-client', 'okhttp'] as $tool) {
            if (stripos($agent, $tool) !== false) {
                return __('API client (:name)', ['name' => $tool === 'GuzzleHttp' ? 'PHP' : $tool]);
            }
        }

        if (str_starts_with($agent, 'Dart/')) {
            return __('Mobile app');
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $system => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
            (bool) $browser => $browser,
            (bool) $system => $system,
            default => __('Unknown device'),
        };
    }
}
