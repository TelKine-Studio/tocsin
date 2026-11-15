<?php

use App\Http\Controllers\AccessTokenController;
use App\Http\Controllers\AccountPlanController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\RelayClientController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TopicMemberController;
use App\Http\Controllers\TopicMessageController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebPushController;
use App\Http\Middleware\EnsureRelayOperator;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// First-run setup (only reachable while no user exists).
Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:login')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    Route::get('auth/sso', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

    Route::get('two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:login');

    Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [AcceptInvitationController::class, 'store'])->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');

    // Hosted mode: the plan of the signed-in account.
    Route::get('account/plan', [AccountPlanController::class, 'show'])->name('account.plan');
    Route::post('account/plan/checkout', [AccountPlanController::class, 'checkout'])->middleware('throttle:10,1')->name('account.plan.checkout');

    Route::resource('topics', TopicController::class);
    Route::post('topics/{topic}/messages', [TopicMessageController::class, 'store'])->name('topics.messages.store');
    Route::delete('topics/{topic}/messages', [TopicMessageController::class, 'clear'])->name('topics.messages.clear');
    Route::get('topics/{topic}/messages/{message}', [TopicMessageController::class, 'show'])
        ->scopeBindings()
        ->name('topics.messages.show');
    Route::post('topics/{topic}/messages/{message}/acknowledge', [TopicMessageController::class, 'acknowledge'])
        ->scopeBindings()
        ->name('topics.messages.acknowledge');
    Route::delete('topics/{topic}/messages/{message}', [TopicMessageController::class, 'destroy'])
        ->scopeBindings()
        ->name('topics.messages.destroy');
    Route::put('topics/{topic}/web-push', [WebPushController::class, 'update'])->name('topics.web-push');
    Route::post('web-push/status', [WebPushController::class, 'status'])->name('web-push.status');
    Route::post('topics/{topic}/webhooks', [WebhookController::class, 'store'])->name('topics.webhooks.store');
    Route::delete('topics/{topic}/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('topics.webhooks.destroy');
    Route::post('topics/{topic}/members', [TopicMemberController::class, 'store'])->name('topics.members.store');
    Route::put('topics/{topic}/members/{user}', [TopicMemberController::class, 'update'])->name('topics.members.update');
    Route::delete('topics/{topic}/members/{user}', [TopicMemberController::class, 'destroy'])->name('topics.members.destroy');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('profile/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateCodes'])->name('two-factor.recovery-codes');
    Route::delete('profile/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    Route::delete('profile/devices/{device}', [ProfileController::class, 'destroyDevice'])->name('devices.destroy');
    Route::post('profile/tokens', [AccessTokenController::class, 'store'])->name('tokens.store');
    Route::delete('profile/tokens/{token}', [AccessTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::middleware(['can:admin', RequireTwoFactor::class])->prefix('admin')->name('admin.')->group(function () {
        Route::get('audit', AuditLogController::class)->name('audit');
        Route::put('settings/security', [SettingsController::class, 'updateSecurity'])->name('settings.security');
        Route::resource('users', UserController::class)->only(['index', 'edit', 'update', 'destroy']);
        Route::put('users/{user}/plan', [UserController::class, 'updatePlan'])->name('users.plan');
        Route::redirect('invitations', '/admin/users')->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

        Route::get('settings/{section?}', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general');
        Route::put('settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail');
        Route::put('settings/push', [SettingsController::class, 'updatePush'])->name('settings.push');
        Route::post('settings/push/test', [SettingsController::class, 'testPush'])->name('settings.push.test');
        Route::put('settings/push/fcm', [SettingsController::class, 'updateFcm'])->name('settings.push.fcm');
        Route::put('settings/push/confidential', [SettingsController::class, 'updateConfidential'])->name('settings.push.confidential');
        Route::put('settings/push/mode', [SettingsController::class, 'switchPushMode'])->name('settings.push.mode');
        Route::post('settings/push/relay', [SettingsController::class, 'connectRelay'])->name('settings.relay.connect');
        Route::put('settings/push/relay', [SettingsController::class, 'saveRelayKey'])->name('settings.relay.key');
        Route::delete('settings/push/relay', [SettingsController::class, 'disconnectRelay'])->name('settings.relay.disconnect');
        Route::post('settings/push/relay/checkout', [SettingsController::class, 'relayCheckout'])->name('settings.relay.checkout');
        Route::put('settings/relay', [SettingsController::class, 'updateRelayServer'])->name('settings.relay-server')->middleware(EnsureRelayOperator::class.':billing');

        Route::get('relay-clients', [RelayClientController::class, 'index'])->name('relay-clients.index')->middleware(EnsureRelayOperator::class);
        Route::put('relay-clients/{relayClient}', [RelayClientController::class, 'update'])->name('relay-clients.update')->middleware(EnsureRelayOperator::class);
        Route::post('relay-clients/{relayClient}/key', [RelayClientController::class, 'rotate'])->name('relay-clients.rotate')->middleware(EnsureRelayOperator::class);
        Route::delete('relay-clients/{relayClient}', [RelayClientController::class, 'destroy'])->name('relay-clients.destroy')->middleware(EnsureRelayOperator::class);
        Route::post('settings/mail/test', [SettingsController::class, 'testMail'])->name('settings.mail.test');
    });
});
