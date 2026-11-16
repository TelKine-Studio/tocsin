<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records security-relevant actions (logins, permissions, settings, tokens…).
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function log(string $action, ?Model $subject = null, array $metadata = [], ?User $user = null): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        try {
            AuditLog::create([
                'user_id' => ($user ?? $request?->user())?->getKey(),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'description' => $subject ? self::describe($subject) : null,
                'metadata' => $metadata ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected static function describe(Model $subject): string
    {
        return (string) ($subject->getAttribute('email') ?? $subject->getAttribute('name') ?? class_basename($subject).' #'.$subject->getKey());
    }

    /** Human readable label of an action. */
    public static function label(string $action): string
    {
        return match ($action) {
            'auth.login' => __('Logged in'),
            'auth.login_failed' => __('Failed login'),
            'auth.logout' => __('Logged out'),
            'auth.sso_login' => __('Logged in with SSO'),
            'auth.password_reset' => __('Password reset'),
            'auth.2fa_enabled' => __('Two-factor authentication enabled'),
            'auth.2fa_disabled' => __('Two-factor authentication disabled'),
            'auth.2fa_failed' => __('Invalid two-factor code'),
            'user.invited' => __('User added'),
            'user.activated' => __('Account activated'),
            'user.updated' => __('User updated'),
            'user.deleted' => __('User deleted'),
            'token.created' => __('Access token created'),
            'token.revoked' => __('Access token revoked'),
            'topic.created' => __('Topic created'),
            'topic.updated' => __('Topic updated'),
            'topic.deleted' => __('Topic deleted'),
            'topic.member_added' => __('Member added'),
            'topic.member_updated' => __('Member permissions changed'),
            'topic.member_removed' => __('Member removed'),
            'webhook.created' => __('Webhook created'),
            'webhook.deleted' => __('Webhook deleted'),
            'settings.updated' => __('Settings changed'),
            'relay.connected' => __('Connected to the relay'),
            'relay.disconnected' => __('Disconnected from the relay'),
            default => $action,
        };
    }
}
