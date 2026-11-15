<?php

use App\Http\Controllers\Api\V1\AcknowledgementController;
use App\Http\Controllers\Ntfy\NtfyController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ntfy-compatible routes
|--------------------------------------------------------------------------
| Registered after the web and API routes, so /login, /dashboard, ... always
| take precedence. Topic names that collide with them are reserved.
*/

Route::post('hooks/{token}', [WebhookController::class, 'receive'])
    ->middleware('throttle:publish')
    ->name('webhooks.receive');

Route::get('v1/health', [NtfyController::class, 'health'])->name('ntfy.health');

Route::get('attachments/{message}/{name}', [NtfyController::class, 'attachment'])
    ->middleware('signed')
    ->name('attachments.show');

Route::get('v1/topics/{topic}/icon', [NtfyController::class, 'icon'])
    ->where('topic', '[-_a-z0-9]{1,64}')
    ->name('topics.icon');

Route::post('v1/messages/{message}/ack', AcknowledgementController::class)
    ->middleware(['signed', 'throttle:api', AuthenticateApi::class, EnsureUserIsActive::class])
    ->name('messages.acknowledge');

// A topic URL opened in a browser (ntfy shows its web page): go to the
// topic page. Publishing to the same URL is POST/PUT, below.
Route::get('{topic}', fn (string $topic) => redirect()->route('topics.show', $topic))
    ->where('topic', '[-_A-Za-z0-9]{1,64}')
    ->name('ntfy.topic');

Route::middleware([AuthenticateApi::class, EnsureUserIsActive::class])->group(function () {
    $topic = '[-_A-Za-z0-9]{1,64}';

    Route::post('/', [NtfyController::class, 'publishJson'])->middleware('throttle:publish')->name('ntfy.json');

    Route::match(['put', 'post'], '{topic}', [NtfyController::class, 'publish'])
        ->where('topic', $topic)->middleware('throttle:publish')->name('ntfy.publish');

    Route::get('{topic}/{action}', [NtfyController::class, 'publishFromQuery'])
        ->where(['topic' => $topic, 'action' => 'publish|send|trigger'])->middleware('throttle:publish')->name('ntfy.trigger');

    Route::get('{topic}/{format}', [NtfyController::class, 'poll'])
        ->where(['topic' => $topic, 'format' => 'json|sse|raw'])->middleware('throttle:api')->name('ntfy.poll');

    Route::get('{topic}/auth', [NtfyController::class, 'auth'])->where('topic', $topic)->name('ntfy.auth');
});
