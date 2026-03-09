<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoSupport;

it('prefers item transaction ids for refundable payment references', function (): void {
    $support = new IyzicoSupport;

    $transactionId = $support->refundableTransactionId([
        'paymentId' => 'pay_123',
        'itemTransactions' => [
            ['paymentTransactionId' => 'txn_456'],
            ['paymentTransactionId' => 'txn_789'],
        ],
    ], 'pay_123');

    expect($transactionId)->toBe('txn_456');
});

it('supports legacy payment item transaction ids for refundable payment references', function (): void {
    $support = new IyzicoSupport;

    $transactionId = $support->refundableTransactionId([
        'paymentId' => 'pay_123',
        'paymentItems' => [
            ['paymentTransactionId' => 'txn_456'],
        ],
    ], 'pay_123');

    expect($transactionId)->toBe('txn_456');
});

it('falls back to payment id when refundable item transaction id is missing', function (): void {
    $support = new IyzicoSupport;

    $transactionId = $support->refundableTransactionId([
        'paymentId' => 'pay_123',
        'paymentItems' => [
            ['itemId' => 'item_1'],
        ],
    ], 'pay_123');

    expect($transactionId)->toBe('pay_123');
});

it('omits recurrence count when the configured value is non positive', function (): void {
    $support = new IyzicoSupport;

    expect($support->pricingPlanRecurrenceCount(0))->toBeNull()
        ->and($support->pricingPlanRecurrenceCount(-2))->toBeNull();
});

it('preserves positive recurrence count values for remote pricing plans', function (): void {
    $support = new IyzicoSupport;

    expect($support->pricingPlanRecurrenceCount(12))->toBe(12);
});
