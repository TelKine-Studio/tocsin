<?php

namespace App\Http\Middleware;

use App\Models\RelayClient;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an instance calling the relay with its relay key.
 */
class AuthenticateRelayClient
{
    public function __construct(protected Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->relayServerActive(), 404);

        $client = $request->bearerToken() ? RelayClient::findByKey($request->bearerToken()) : null;

        if (! $client) {
            return response()->json(['error' => 'invalid_key', 'message' => 'Invalid relay key.'], 401);
        }

        if (! $client->is_active) {
            return response()->json(['error' => 'disabled', 'message' => 'This instance has been disabled on the relay.'], 403);
        }

        $request->attributes->set('relay_client', $client);

        return $next($request);
    }
}
