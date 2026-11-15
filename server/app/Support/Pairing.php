<?php

namespace App\Support;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Links shown as a QR code to add a topic in the mobile app.
 *
 * For a private topic the link carries a one-time code, valid a few
 * minutes, that the app exchanges for an access token of the account
 * that generated it: scanning it signs the app in without typing.
 */
class Pairing
{
    public const TTL_MINUTES = 10;

    /** @return array{url: string, expires_at: ?Carbon, signs_in: bool} */
    public function link(Topic $topic, User $user): array
    {
        if ($topic->isPublic()) {
            return ['url' => route('pairing.show', $topic->name), 'expires_at' => null, 'signs_in' => false];
        }

        $code = Str::random(40);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        Cache::put($this->key($code), ['user_id' => $user->id, 'topic' => $topic->name], $expiresAt);

        return [
            'url' => route('pairing.show', ['topic' => $topic->name, 'code' => $code]),
            'expires_at' => $expiresAt,
            'signs_in' => true,
        ];
    }

    /**
     * Uses a code (once).
     *
     * @return array{user_id: int, topic: string}|null
     */
    public function consume(string $code): ?array
    {
        return Cache::pull($this->key($code));
    }

    protected function key(string $code): string
    {
        return 'pairing:'.hash('sha256', $code);
    }
}
