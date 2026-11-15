<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional API authentication used by the publish / subscribe endpoints.
 *
 * Accepts either a personal access token ("Authorization: Bearer <token>"
 * or "curl -u :<token>") or HTTP Basic credentials ("curl -u email:password"), so that scripts can
 * publish exactly like with ntfy. Requests without credentials continue as
 * guests; invalid credentials are rejected.
 */
class AuthenticateApi
{
    public function handle(Request $request, Closure $next): Response
    {
        // ntfy clients may pass the Authorization header base64 encoded in ?auth=.
        if (! $request->hasHeader('Authorization') && is_string($request->query('auth'))) {
            $decoded = base64_decode(strtr($request->query('auth'), '-_', '+/'), true);
            if ($decoded !== false && preg_match('/^(Basic|Bearer) /', $decoded)) {
                $request->headers->set('Authorization', $decoded);
                if (str_starts_with($decoded, 'Basic ')) {
                    [$user, $password] = array_pad(explode(':', (string) base64_decode(substr($decoded, 6)), 2), 2, '');
                    $request->headers->set('PHP_AUTH_USER', $user);
                    $request->headers->set('PHP_AUTH_PW', $password);
                    $request->server->set('PHP_AUTH_USER', $user);
                    $request->server->set('PHP_AUTH_PW', $password);
                }
            }
        }

        $header = (string) $request->header('Authorization');

        if ($header === '') {
            return $next($request);
        }

        $user = match (true) {
            str_starts_with($header, 'Bearer ') => Auth::guard('sanctum')->user(),
            str_starts_with($header, 'Basic ') => $this->basicUser($request),
            default => null,
        };

        if (! $user instanceof User) {
            throw new AuthenticationException(__('Invalid credentials.'));
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    protected function basicUser(Request $request): ?User
    {
        $email = $request->getUser();
        $password = $request->getPassword();

        if ($password === null || $password === '') {
            return null;
        }

        // "curl -u :<token>" — an access token given as the Basic password.
        if (! $email) {
            $token = PersonalAccessToken::findToken($password);
            $user = $token?->tokenable;

            if ($user instanceof User && ! ($token->expires_at && $token->expires_at->isPast())) {
                $token->forceFill(['last_used_at' => now()])->save();

                return $user->withAccessToken($token);
            }

            return null;
        }

        $user = User::query()->where('email', mb_strtolower($email))->first();

        return $user && Hash::check($password, $user->password) ? $user : null;
    }
}
