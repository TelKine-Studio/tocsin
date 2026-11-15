<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Hosted mode: the account that owns the topic reached its daily message
 * quota, or has not confirmed its email address yet.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'quota', public readonly int $limit = 0)
    {
        parent::__construct($message);
    }

    public function upgradeUrl(): string
    {
        return route('account.plan');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || ! $request->hasSession()) {
            return response()->json([
                'error' => $this->reason === 'quota' ? 'quota_exceeded' : 'email_not_verified',
                'message' => $this->getMessage(),
                'upgrade_url' => $this->upgradeUrl(),
            ], $this->reason === 'quota' ? 429 : 403);
        }

        return back()->withInput()->withErrors(['alert' => $this->getMessage()]);
    }
}
