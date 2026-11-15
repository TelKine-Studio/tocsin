<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relay operator screens and API only exist when TOCSIN_RELAY_OPERATOR is
 * set (the instance operated by Telkine): elsewhere they answer 404.
 */
class EnsureRelayOperator
{
    /**
     * With "billing", hosted mode (TOCSIN_HOSTED) is enough too: payment
     * notifications serve relay instances and hosted accounts alike.
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $allowed = config('tocsin.relay_operator') || ($scope === 'billing' && config('tocsin.hosted'));

        abort_unless($allowed, 404);

        return $next($request);
    }
}
