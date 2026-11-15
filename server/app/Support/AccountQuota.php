<?php

namespace App\Support;

use App\Exceptions\QuotaExceededException;
use App\Models\AccountUsage;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\QuotaWarningNotification;
use Illuminate\Support\Facades\DB;

/**
 * Daily message quota of accounts in hosted mode (TOCSIN_HOSTED).
 *
 * A message counts against the account that owns its topic, whoever
 * publishes it (token, webhook, anonymous). Grouped repeats do not count:
 * they send no notification. Administrators are not limited.
 */
class AccountQuota
{
    /** Share of the quota that triggers the warning email. */
    public const WARNING_RATIO = 0.8;

    public static function enabled(): bool
    {
        return (bool) config('tocsin.hosted');
    }

    /** The account a message of this topic counts against, if any. */
    public function payer(Topic $topic): ?User
    {
        if (! self::enabled()) {
            return null;
        }

        $owner = $topic->relationLoaded('owner') ? $topic->owner : $topic->owner()->first();

        return $owner && ! $owner->isAdmin() ? $owner : null;
    }

    /** @throws QuotaExceededException */
    public function ensureCanPublish(Topic $topic): void
    {
        $owner = $this->payer($topic);

        if (! $owner) {
            return;
        }

        if (! $owner->hasVerifiedEmail()) {
            throw new QuotaExceededException(__('Confirm your email address to start publishing: we sent you a link.'), 'unverified');
        }

        $limit = $owner->effectiveDailyLimit();

        if ($owner->messagesToday() >= $limit) {
            $usage = AccountUsage::today($owner);
            AccountUsage::query()->whereKey($usage->id)->update(['rejected' => DB::raw('rejected + 1')]);

            throw new QuotaExceededException(__('Daily limit of :limit messages reached for this account. It resets at midnight, or upgrade to continue now: :url', [
                'limit' => number_format($limit, 0, ',', ' '),
                'url' => route('account.plan'),
            ]), 'quota', $limit);
        }
    }

    /** Counts a published message and warns the owner near the limit. */
    public function record(Topic $topic): void
    {
        $owner = $this->payer($topic);

        if (! $owner) {
            return;
        }

        $usage = AccountUsage::today($owner);
        AccountUsage::query()->whereKey($usage->id)->update(['messages' => DB::raw('messages + 1')]);

        $limit = $owner->effectiveDailyLimit();
        $used = $usage->messages + 1;

        // Once a day, the first time 80 % of the quota is used.
        if (! $usage->warned && $limit > 0 && $used >= (int) ceil($limit * self::WARNING_RATIO)) {
            $claimed = AccountUsage::query()->whereKey($usage->id)->where('warned', false)->update(['warned' => true]);

            if ($claimed) {
                $owner->notify(new QuotaWarningNotification($used, $limit));
            }
        }
    }
}
