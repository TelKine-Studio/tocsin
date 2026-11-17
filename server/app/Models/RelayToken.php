<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A device token seen by the relay, and the instances that push to it.
 *
 * The official app can be connected to several Tocsin servers, so the same
 * token may legitimately come from a few instances; beyond that, an
 * instance that never pushed to a device cannot start pushing to it (a key
 * registered for free must not reach every install of the app).
 */
#[Fillable(['relay_client_id', 'token_hash', 'last_used_at', 'created_at'])]
class RelayToken extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /** Instances allowed to push to the same device. */
    public const MAX_CLIENTS_PER_TOKEN = 5;

    /** Bindings forgotten after this many days without a notification. */
    public const KEEP_DAYS = 90;

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public static function hash(string $platform, string $token): string
    {
        return hash('sha256', $platform.':'.($platform === 'ios' ? strtolower($token) : $token));
    }

    /**
     * Binds the tokens to the client (first use) and returns the hashes it
     * may not push to: bound to too many other instances already.
     *
     * @param  list<string>  $hashes
     * @return Collection<int, string>
     */
    public static function claim(RelayClient $client, array $hashes): Collection
    {
        $hashes = array_values(array_unique($hashes));
        if ($hashes === []) {
            return collect();
        }

        $rows = static::query()->whereIn('token_hash', $hashes)->get(['token_hash', 'relay_client_id'])->groupBy('token_hash');
        $refused = collect();
        $new = [];
        foreach ($hashes as $hash) {
            $clients = $rows->get($hash, collect())->pluck('relay_client_id');
            if ($clients->contains($client->id)) {
                continue;
            }
            if ($clients->count() >= self::MAX_CLIENTS_PER_TOKEN) {
                $refused->push($hash);

                continue;
            }
            $new[] = ['relay_client_id' => $client->id, 'token_hash' => $hash, 'created_at' => now(), 'last_used_at' => now()];
        }

        if ($new) {
            static::query()->insertOrIgnore($new);
        }
        static::query()->where('relay_client_id', $client->id)->whereIn('token_hash', array_diff($hashes, $refused->all()))->update(['last_used_at' => now()]);

        return $refused;
    }

    public function prunable(): Builder
    {
        return static::query()->where('last_used_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
