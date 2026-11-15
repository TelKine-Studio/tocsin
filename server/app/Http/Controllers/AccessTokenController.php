<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Personal access tokens, used to publish from scripts and by the mobile app.
 */
class AccessTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'topics' => ['nullable', 'array', 'max:50'],
            'topics.*' => ['string', 'exists:topics,name'],
            'expires' => ['nullable', 'in:never,30,90,365'],
        ]);

        $abilities = empty($data['topics']) ? ['*'] : array_map(fn ($name) => 'topic:'.$name, $data['topics']);
        $expiresAt = in_array($data['expires'] ?? 'never', ['30', '90', '365'], true) ? now()->addDays((int) $data['expires']) : null;

        $token = $request->user()->createToken($data['name'], $abilities, $expiresAt);
        Audit::log('token.created', $request->user(), ['name' => $data['name'], 'topics' => $data['topics'] ?? 'all', 'expires_at' => $expiresAt?->toIso8601String()]);

        return back()
            ->with('status', __('Token created. Copy it now, it will not be shown again.'))
            ->with('plain_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();
        Audit::log('token.revoked', $request->user(), ['token' => $token]);

        return back()->with('status', __('Token revoked.'));
    }
}
