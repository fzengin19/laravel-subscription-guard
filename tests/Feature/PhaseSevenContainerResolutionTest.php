<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

it('resolves phase seven collaborators through the container', function (): void {
    expect(app(IyzicoProvider::class))->toBeInstanceOf(IyzicoProvider::class)
        ->and(app(SubscriptionService::class))->toBeInstanceOf(SubscriptionService::class)
        ->and(app(PaymentManager::class)->provider('iyzico'))->toBeInstanceOf(IyzicoProvider::class);
});
