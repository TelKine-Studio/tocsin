<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\TopicVisibility;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Turns an audit log entry into something a person reads: a sentence
 * ("Ada created the topic alerts"), an icon, a tone and labelled details.
 */
class AuditEntry
{
    /** Actions shown in red: something was removed, weakened or refused. */
    public const DANGER = [
        'auth.login_failed', 'auth.2fa_failed', 'auth.2fa_disabled', 'user.deleted',
        'topic.deleted', 'topic.member_removed', 'token.revoked', 'webhook.deleted',
        'relay.disconnected',
    ];

    /** Metadata keys already used in the sentence, or of no use to a reader. */
    protected const HIDDEN = ['token', 'api'];

    public function __construct(public AuditLog $log) {}

    /** Filters of the list, by first segment of the action. */
    public static function groups(): array
    {
        return [
            '' => __('All events'),
            'auth' => __('Sign-ins'),
            'user' => __('Users'),
            'topic' => __('Topics'),
            'token' => __('Access tokens'),
            'webhook' => __('Webhooks'),
            'settings' => __('Instance settings'),
            'relay' => __('Relay'),
        ];
    }

    public function icon(): string
    {
        return match (Str::before($this->log->action, '.')) {
            'auth' => match ($this->log->action) {
                'auth.logout' => 'logout',
                'auth.2fa_enabled', 'auth.2fa_disabled', 'auth.2fa_failed' => 'shield',
                default => 'lock',
            },
            'user' => $this->log->action === 'user.invited' ? 'user-plus' : 'user',
            'topic' => str_starts_with($this->log->action, 'topic.member') ? 'users' : 'hash',
            'token' => 'key',
            'webhook' => 'link',
            'settings' => 'settings',
            'relay' => 'globe',
            default => 'activity',
        };
    }

    public function danger(): bool
    {
        return in_array($this->log->action, self::DANGER, true);
    }

    /** The sentence, with names in bold. */
    public function sentence(): HtmlString
    {
        $pattern = match ($this->log->action) {
            'auth.login' => 'Sign-in by :actor',
            'auth.sso_login' => 'Single sign-on by :actor',
            'auth.login_failed' => 'Failed sign-in for :subject',
            'auth.logout' => 'Sign-out by :actor',
            'auth.password_reset' => 'Password reset by :subject',
            'auth.2fa_enabled' => 'Two-factor authentication enabled by :actor',
            'auth.2fa_disabled' => 'Two-factor authentication disabled by :actor',
            'auth.2fa_failed' => 'Wrong two-factor code for :subject',
            'user.invited' => ':actor invited :subject',
            'user.activated' => ':subject activated their account',
            'user.updated' => ':actor updated the account of :subject',
            'user.deleted' => ':actor deleted the account of :subject',
            'user.plan_changed' => ':actor changed the plan of :subject',
            'topic.created' => ':actor created the topic :subject',
            'topic.updated' => ':actor updated the topic :subject',
            'topic.deleted' => ':actor deleted the topic :subject',
            'topic.member_added' => ':actor added :member to the topic :subject',
            'topic.member_updated' => ':actor changed the permissions of :member on the topic :subject',
            'topic.member_removed' => ':actor removed :member from the topic :subject',
            'token.created' => ':actor created the access token :item',
            'token.revoked' => ':actor revoked the access token :item',
            'webhook.created' => ':actor created the webhook :item on the topic :subject',
            'webhook.deleted' => ':actor deleted the webhook :item from the topic :subject',
            'settings.updated' => ':actor changed the instance settings',
            'relay.connected' => ':actor connected the instance to the relay',
            'relay.disconnected' => ':actor disconnected the instance from the relay',
            default => null,
        };

        if (! $pattern) {
            return new HtmlString(e(Audit::label($this->log->action)));
        }

        $bold = fn (?string $value) => '<strong class="font-semibold text-zinc-900 dark:text-white">'.e($value ?: __('unknown')).'</strong>';

        $sentence = __($pattern, [
            'actor' => $this->log->user ? $bold($this->log->user->name) : e(__('someone')),
            'subject' => $bold($this->subject()),
            'member' => $bold($this->metadata('user') ?? $this->metadata('member')),
            'item' => $bold($this->metadata('name')),
        ]);

        // "someone" may open the sentence.
        return new HtmlString(Str::ucfirst($sentence));
    }

    /** Name of what the action was about, even once it has been deleted. */
    public function subject(): ?string
    {
        return $this->log->description
            ?? $this->metadata('name')
            ?? $this->metadata('email');
    }

    /** @return array<int, array{label: string, value: string, mono?: bool}> */
    public function details(): array
    {
        $used = match ($this->log->action) {
            'auth.login_failed' => ['email'],
            'token.created', 'token.revoked', 'webhook.created', 'webhook.deleted' => ['name'],
            'topic.member_added', 'topic.member_updated', 'topic.member_removed' => ['member'],
            default => [],
        };

        $rows = [];

        foreach ((array) $this->log->metadata as $key => $value) {
            if (in_array($key, [...self::HIDDEN, ...$used], true) || $value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = $this->detail((string) $key, $value);
        }

        return $rows;
    }

    /** Device, network and exact time of the action. */
    public function context(): array
    {
        return array_values(array_filter([
            ['label' => __('Date'), 'value' => $this->log->created_at->isoFormat('LLLL')],
            $this->log->ip_address ? ['label' => __('IP address'), 'value' => $this->log->ip_address, 'mono' => true] : null,
            $this->log->user_agent ? ['label' => __('Device'), 'value' => UserAgent::describe($this->log->user_agent)] : null,
            $this->log->user_agent ? ['label' => __('Full user agent'), 'value' => $this->log->user_agent, 'mono' => true, 'small' => true] : null,
            $this->log->user ? ['label' => __('Account'), 'value' => $this->log->user->email] : null,
        ]));
    }

    public function device(): ?string
    {
        return UserAgent::describe($this->log->user_agent);
    }

    protected function detail(string $key, mixed $value): array
    {
        $yes = fn ($v) => $v ? __('Yes') : __('No');

        return match ($key) {
            'role' => ['label' => __('Role'), 'value' => Role::tryFrom((string) $value)?->label() ?? (string) $value],
            'visibility' => ['label' => __('Visibility'), 'value' => TopicVisibility::tryFrom((string) $value)?->label() ?? (string) $value],
            'anonymous_publish' => ['label' => __('Anonymous publishing'), 'value' => $yes($value)],
            'can_write' => ['label' => __('Can publish'), 'value' => $yes($value)],
            'topics' => ['label' => __('Topics'), 'value' => $value === 'all' ? __('All topics') : $this->format($value)],
            'expires_at' => ['label' => __('Expires'), 'value' => $this->format($value)],
            'source' => ['label' => __('Source'), 'value' => Str::headline((string) $value)],
            'section' => ['label' => __('Section'), 'value' => match ($value) {
                'general' => __('General'), 'security' => __('Security'), 'mail' => __('Email'), 'push' => __('Push notifications'), 'relay' => __('Relay'), default => Str::headline((string) $value),
            }],
            'mode' => ['label' => __('Delivery'), 'value' => $value === 'direct' ? __('Own Apple and Firebase keys') : __('Through the relay')],
            'fcm_project' => ['label' => __('Firebase project'), 'value' => (string) $value, 'mono' => true],
            'confidential' => ['label' => __('Confidential notifications'), 'value' => $yes($value)],
            'relay' => ['label' => __('Relay'), 'value' => (string) $value, 'mono' => true],
            'plan' => ['label' => __('Plan'), 'value' => Str::headline((string) $value)],
            'daily_limit' => ['label' => __('Daily limit'), 'value' => number_format((int) $value, 0, ',', ' ')],
            'until' => ['label' => __('Until'), 'value' => Carbon::parse($value)->isoFormat('LL')],
            'device' => ['label' => __('Device'), 'value' => (string) $value],
            'qr_code' => ['label' => __('Signed in with a QR code'), 'value' => $yes($value)],
            'changed' => ['label' => __('Changed'), 'value' => collect((array) $value)->map(fn ($field) => match ($field) {
                'name' => __('Name'), 'email' => __('Email address'), 'role' => __('Role'), 'is_active' => __('Status'), 'password' => __('Password'), default => Str::headline((string) $field),
            })->implode(', ')],
            'member', 'user' => ['label' => __('Member'), 'value' => (string) $value],
            'active' => ['label' => __('Status'), 'value' => $value ? __('Active') : __('Suspended')],
            'two_factor' => ['label' => __('Two-factor authentication'), 'value' => $yes($value)],
            'sso' => ['label' => __('Single sign-on'), 'value' => $yes($value)],
            'require_2fa_admins' => ['label' => __('Two-factor required for administrators'), 'value' => $yes($value)],
            'email' => ['label' => __('Email address'), 'value' => (string) $value],
            'name' => ['label' => __('Name'), 'value' => (string) $value],
            default => ['label' => Str::headline($key), 'value' => $this->format($value)],
        };
    }

    protected function format(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => collect($value)->map(fn ($v) => $this->format($v))->implode(', '),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1 => Carbon::parse($value)->isoFormat('LLL'),
            default => (string) $value,
        };
    }

    protected function metadata(string $key): mixed
    {
        return $this->log->metadata[$key] ?? null;
    }
}
