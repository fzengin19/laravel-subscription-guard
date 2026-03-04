<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\LaravelSubscriptionGuardServiceProvider;

it('boots package service provider', function (): void {
    expect(app()->getProvider(LaravelSubscriptionGuardServiceProvider::class))->not->toBeNull();
    expect(config('subscription-guard'))->toBeArray();
});
