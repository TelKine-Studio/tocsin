<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Signs private channel subscriptions for token-authenticated clients.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}
