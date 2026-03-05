<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers\PaymentCallbackController;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers\WebhookController;

Route::post('/{provider}', WebhookController::class)
    ->whereAlphaNumeric('provider')
    ->name('subguard.webhooks.handle');

Route::post('/{provider}/3ds/callback', [PaymentCallbackController::class, 'threeDs'])
    ->whereAlphaNumeric('provider')
    ->name('subguard.webhooks.3ds-callback');

Route::post('/{provider}/checkout/callback', [PaymentCallbackController::class, 'checkout'])
    ->whereAlphaNumeric('provider')
    ->name('subguard.webhooks.checkout-callback');
