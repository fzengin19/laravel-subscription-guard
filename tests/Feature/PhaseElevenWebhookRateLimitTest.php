<?php

declare(strict_types=1);

it('applies rate limiting middleware to webhook routes', function () {
    $webhookMiddleware = config('subscription-guard.webhooks.middleware', ['api']);

    $hasThrottle = collect($webhookMiddleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:'));

    expect($hasThrottle)->toBeTrue('Webhook routes should have rate limiting middleware');
});
