<?php

use Illuminate\Support\Facades\Route;
use JensS\Newsletter\Http\Controllers\ConfirmController;
use JensS\Newsletter\Http\Controllers\GenericWebhookController;
use JensS\Newsletter\Http\Controllers\PreferencesController;
use JensS\Newsletter\Http\Controllers\PreviewController;
use JensS\Newsletter\Http\Controllers\ResendWebhookController;
use JensS\Newsletter\Http\Controllers\SubscribeController;
use JensS\Newsletter\Http\Controllers\TickController;
use JensS\Newsletter\Http\Controllers\UnsubscribeController;

$config = config('newsletter.routes');

Route::group([
    'prefix' => $config['prefix'] ?? 'newsletter',
    'domain' => $config['domain'] ?? null,
    'as' => 'newsletter.',
], function () use ($config) {
    // Browser routes (session + CSRF).
    Route::middleware($config['middleware'] ?? ['web'])->group(function () use ($config) {
        Route::post('subscribe', SubscribeController::class)
            ->middleware(array_merge(
                ['throttle:'.($config['signup_throttle'] ?? '6,1')],
                (array) ($config['signup_middleware'] ?? []),
            ))
            ->name('subscribe');

        Route::get('confirm/{token}', [ConfirmController::class, 'show'])->name('confirm');
        Route::post('confirm/{token}', [ConfirmController::class, 'store'])->name('confirm.store');

        Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe');
        Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
        Route::get('unsubscribe/{token}/one-click', [UnsubscribeController::class, 'oneClickBrowser']);

        Route::get('preferences/{token}', [PreferencesController::class, 'show'])->name('preferences');
        Route::post('preferences/{token}', [PreferencesController::class, 'update'])->name('preferences.update');

        Route::get('campaigns/{campaign}/preview', PreviewController::class)
            ->middleware('auth')
            ->name('preview');
    });

    // Machine routes (no session, no CSRF).
    Route::middleware($config['webhook_middleware'] ?? [])->group(function () {
        Route::post('unsubscribe/{token}/one-click', [UnsubscribeController::class, 'oneClick'])
            ->middleware('throttle:60,1')
            ->name('unsubscribe.one-click');

        Route::match(['get', 'post'], 'tick', TickController::class)
            ->middleware('throttle:10,1')
            ->name('tick');

        Route::post('webhooks/resend', ResendWebhookController::class)->name('webhooks.resend');
        Route::post('webhooks/generic', GenericWebhookController::class)->name('webhooks.generic');
    });
});
