<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\BillingProfile;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseActivation;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\PaymentMethod;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

it('does not keep any package model fully unguarded', function (): void {
    $models = [
        BillingProfile::class,
        Coupon::class,
        Discount::class,
        Invoice::class,
        License::class,
        LicenseActivation::class,
        LicenseUsage::class,
        PaymentMethod::class,
        Plan::class,
        ScheduledPlanChange::class,
        Subscription::class,
        SubscriptionItem::class,
        Transaction::class,
        WebhookCall::class,
    ];

    foreach ($models as $modelClass) {
        $model = new $modelClass;

        expect($model->getGuarded(), $modelClass.' must not use guarded=[]')->not->toBe([]);
    }
});

it('blocks mass assignment of critical subscription and license lifecycle fields', function (): void {
    Model::preventSilentlyDiscardingAttributes(true);

    try {
        expect(static fn (): Subscription => Subscription::query()->create([
            'status' => 'active',
        ]))->toThrow(MassAssignmentException::class);

        expect(static fn (): License => License::query()->create([
            'expires_at' => now()->addYear(),
        ]))->toThrow(MassAssignmentException::class);
    } finally {
        Model::preventSilentlyDiscardingAttributes(false);
    }
});

it('blocks mass assignment of critical payment and transaction fields', function (): void {
    Model::preventSilentlyDiscardingAttributes(true);

    try {
        expect(static fn (): Transaction => Transaction::query()->create([
            'amount' => 9999,
        ]))->toThrow(MassAssignmentException::class);

        expect(static fn (): PaymentMethod => PaymentMethod::query()->create([
            'provider_card_token' => 'token-overwrite',
        ]))->toThrow(MassAssignmentException::class);
    } finally {
        Model::preventSilentlyDiscardingAttributes(false);
    }
});
