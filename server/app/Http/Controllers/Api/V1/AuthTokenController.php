<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use App\Support\Pairing;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    /**
     * Exchange credentials for a personal access token (used by the mobile app).
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::query()->where('email', mb_strtolower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('This account has been disabled.')]);
        }

        if ($user->hasTwoFactor()) {
            $code = (string) ($credentials['code'] ?? '');
            $twoFactor = app(TwoFactor::class);

            if ($code === '') {
                throw ValidationException::withMessages(['code' => __('Enter the code from your authenticator app.')])->status(422);
            }

            if (! $twoFactor->verify((string) $user->two_factor_secret, $code) && ! $twoFactor->useRecoveryCode($user, $code)) {
                Audit::log('auth.2fa_failed', $user, user: $user);

                throw ValidationException::withMessages(['code' => __('This code is invalid.')]);
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, ['api' => true, 'device' => $credentials['device_name'] ?? null], $user);

        $token = $user->createToken($credentials['device_name'] ?? 'Mobile app');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Exchange the one-time code of a QR code for a personal access token.
     */
    public function pair(Request $request, Pairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $pairingData = $pairing->consume($data['code']);
        $user = $pairingData ? User::find($pairingData['user_id']) : null;

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['code' => __('This QR code has expired. Show a new one on the topic page.')]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, ['api' => true, 'qr_code' => true, 'device' => $data['device_name'] ?? null], $user);

        $token = $user->createToken($data['device_name'] ?? 'Mobile app');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
            'topic' => $pairingData['topic'],
        ], 201);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
