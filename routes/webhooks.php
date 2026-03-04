<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers\WebhookController;

Route::post('/{provider}', WebhookController::class)
    ->whereAlphaNumeric('provider')
    ->name('subguard.webhooks.handle');
