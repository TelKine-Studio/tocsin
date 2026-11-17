<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTPS only from the first visit on (HSTS), and a policy that forbids
 * plugins, foreign <base> tags and framing by other sites.
 *
 * Behind Cloudflare the proxy may reach Tocsin over plain HTTP: when the
 * instance URL is https and the request comes on its host, links and
 * redirects are https too.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $url = (string) app(Settings::class)->get('app.url');
        $https = $request->isSecure() || (str_starts_with($url, 'https://') && strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0);
        URL::forceScheme($https && ! $request->isSecure() ? 'https' : null);

        $response = $next($request);

        if ($https) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        }

        return $response;
    }
}
