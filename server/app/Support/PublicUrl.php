<?php

namespace App\Support;

/**
 * Links sent by email are built on the address configured for the
 * instance, never on the Host header of the request: a forged header must
 * not make a password-reset link point to another site.
 */
final class PublicUrl
{
    public static function route(string $name, mixed $parameters = []): string
    {
        $path = route($name, $parameters, false);
        $base = rtrim((string) app(Settings::class)->get('app.url'), '/');

        return ($base !== '' ? $base : rtrim(url('/'), '/')).$path;
    }
}
