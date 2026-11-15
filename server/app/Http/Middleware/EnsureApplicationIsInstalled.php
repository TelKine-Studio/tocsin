<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects every page to the first-run setup until the first administrator
 * has been created, and closes the setup afterwards.
 */
class EnsureApplicationIsInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $onSetup = $request->routeIs('setup.*');

        if (! Installation::isComplete()) {
            return $onSetup ? $next($request) : redirect()->route('setup.show');
        }

        if ($onSetup) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
