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
        $locale = $request->user()?->locale ?: $this->settings->string('app.locale', 'en');

        if (array_key_exists($locale, self::AVAILABLE)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
