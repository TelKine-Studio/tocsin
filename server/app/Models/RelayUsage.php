<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['relay_client_id', 'date', 'sent', 'failed', 'rejected'])]
class RelayUsage extends Model
{
    public $timestamps = false;

    // "date" is kept as a plain Y-m-d string so that lookups by day match.

    /** @return BelongsTo<RelayClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(RelayClient::class, 'relay_client_id');
    }

    /** Atomically adds today's counters for a client. */
    public static function record(RelayClient $client, int $sent, int $failed, int $rejected): void
    {
        $usage = static::query()->firstOrCreate(['relay_client_id' => $client->id, 'date' => now()->toDateString()]);

        static::query()->whereKey($usage->id)->update([
            'sent' => DB::raw('sent + '.$sent),
            'failed' => DB::raw('failed + '.$failed),
            'rejected' => DB::raw('rejected + '.$rejected),
        ]);
    }
}
