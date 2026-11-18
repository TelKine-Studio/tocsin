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
 * redirects are https too, and the request is treated as https so that
 * signed links (acknowledgement buttons) are built and checked on https.
 * It runs for every request, the ntfy API included.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $url = (string) app(Settings::class)->get('app.url');
        $https = $request->isSecure() || (str_starts_with($url, 'https://') && strcasecmp((string) parse_url($url, PHP_URL_HOST), $request->getHost()) === 0);
        if ($https && ! $request->isSecure()) {
            $request->server->set('HTTPS', 'on');
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->server->set('SERVER_PORT', 443);
            $request->headers->remove('X-Forwarded-Port');
        }
        URL::forceScheme($https ? 'https' : null);

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
