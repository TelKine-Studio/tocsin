<?php

namespace App\Push;

/**
 * Browser push subscriptions point to the push service of the browser:
 * any other address would make Tocsin send requests wherever a user asks
 * (internal services, cloud metadata...).
 */
final class WebPushEndpoint
{
    /** Hosts of the push services of Chrome / Edge / Opera, Firefox, Windows and Safari. */
    public const HOSTS = [
        'fcm.googleapis.com',
        '*.googleapis.com',
        'updates.push.services.mozilla.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        '*.push.apple.com',
    ];

    public static function allowed(?string $url): bool
    {
        if (! is_string($url) || strlen($url) > 2000) {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach (self::HOSTS as $pattern) {
            if (str_starts_with($pattern, '*.') ? str_ends_with($host, substr($pattern, 1)) : $host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
