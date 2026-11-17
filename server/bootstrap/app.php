<?php

use App\Http\Middleware\CloudflareClientIp;
use App\Http\Middleware\EnsureApplicationIsInstalled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // ntfy-compatible API, after every other route.
            Route::middleware('api')->group(base_path('routes/ntfy.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tocsin is meant to run behind a reverse proxy (Coolify / Traefik, ...),
        // on the same machine or network: only private addresses are trusted,
        // and never for the host name (links are built on the configured URL).
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX,
        );
        $middleware->append(CloudflareClientIp::class);

        $middleware->web(append: [
            EnsureApplicationIsInstalled::class,
            EnsureUserIsActive::class,
            SetLocale::class,
        ]);

        $middleware->api(append: [SetLocale::class]);

        // Send visitors to the first-run setup before asking them to log in.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureApplicationIsInstalled::class,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// All persistent data (SQLite database, logs, sessions, ...) can be moved to a
// single directory, e.g. a Docker volume mounted on /data.
if ($storage = $_SERVER['TOCSIN_STORAGE_PATH'] ?? $_ENV['TOCSIN_STORAGE_PATH'] ?? getenv('TOCSIN_STORAGE_PATH')) {
    $app->useStoragePath($storage);
}

return $app;
