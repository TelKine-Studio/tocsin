<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** Languages shipped with Tocsin. */
    public const AVAILABLE = ['en' => 'English', 'fr' => 'Français'];

    public function __construct(protected Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->apply($request);

        return $next($request);
    }

    /**
     * Also used by error pages, which may render before this middleware and
     * before the session (unknown user): they then follow the browser.
     */
    public function apply(Request $request, bool $browserFallback = false): void
    {
        $locale = $request->user()?->locale;

        if (! $locale && $browserFallback && $request->headers->has('Accept-Language')) {
            $locale = $request->getPreferredLanguage(array_keys(self::AVAILABLE));
        }

        $locale = $locale ?: $this->settings->string('app.locale', 'en');

        if (array_key_exists($locale, self::AVAILABLE)) {
            app()->setLocale($locale);
        }
    }
}
