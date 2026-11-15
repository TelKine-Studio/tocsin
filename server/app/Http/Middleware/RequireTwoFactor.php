<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When required in Settings → Security, administrators must enable
 * two-factor authentication before using the administration.
 */
class RequireTwoFactor
{
    public function __construct(protected Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin() && ! $user->hasTwoFactor() && $this->settings->boolean('security.require_2fa_admins')) {
            return redirect()->to(route('profile.edit').'#security')
                ->withErrors(['alert' => __('Enable two-factor authentication to access the administration.')]);
        }

        return $next($request);
    }
}
