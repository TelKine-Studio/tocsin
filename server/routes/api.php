<?php

use App\Http\Controllers\Api\Relay\BillingController;
use App\Http\Controllers\Api\Relay\RelayController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BroadcastAuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\InfoController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\TopicController;
use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\AuthenticateRelayClient;
use App\Http\Middleware\EnsureRelayOperator;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('info', InfoController::class)->name('info');

    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.token');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.logout');
        Route::get('me', [AuthTokenController::class, 'me'])->name('me');
        Route::post('broadcasting/auth', BroadcastAuthController::class)->name('broadcasting.auth');
    });

    Route::middleware([AuthenticateApi::class, EnsureUserIsActive::class, 'throttle:api'])->group(function () {
        Route::get('topics', [TopicController::class, 'index'])->name('topics.index');
        Route::get('topics/{topic}', [TopicController::class, 'show'])->name('topics.show');
        Route::get('topics/{topic}/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('topics/{topic}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:publish')
            ->name('messages.store');

        Route::put('devices', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');
    });
});

// Push relay, enabled on the instance operated by Telkine (Settings → Relay).
Route::prefix('relay/v1')->name('relay.')->middleware(EnsureRelayOperator::class)->group(function () {
    Route::post('instances', [RelayController::class, 'register'])
        ->middleware('throttle:relay-register')
        ->name('register');

    Route::post('payments/cinetpay', [BillingController::class, 'cinetpay'])->name('payments.cinetpay');
    Route::post('payments/stripe', [BillingController::class, 'stripe'])->name('payments.stripe');

    Route::middleware(AuthenticateRelayClient::class)->group(function () {
        Route::get('usage', [RelayController::class, 'usage'])->name('usage');
        Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
        Route::post('push', [RelayController::class, 'push'])->name('push');
    });
});
