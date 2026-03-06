<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

function phaseSevenCreateTransaction(array $attributes = []): Transaction
{
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase7 Transaction User',
        'email' => sprintf('phase7-transaction-%s@example.test', str_replace('.', '-', uniqid('', true))),
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Transaction::unguarded(static fn (): Transaction => Transaction::query()->create(array_merge([
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'pending',
        'amount' => 199.00,
        'currency' => 'TRY',
        'idempotency_key' => sprintf('phase7:transaction:%s', str_replace('.', '-', uniqid('', true))),
    ], $attributes)));
}

it('marks a transaction as processing', function (): void {
    $transaction = phaseSevenCreateTransaction([
        'status' => 'failed',
        'failure_reason' => 'Old failure',
        'processed_at' => now(),
        'next_retry_at' => now()->addHour(),
    ]);

    $transaction->markProcessing();

    $fresh = $transaction->fresh();

    expect($fresh?->getAttribute('status'))->toBe('processing')
        ->and($fresh?->getAttribute('failure_reason'))->toBeNull()
        ->and($fresh?->getAttribute('processed_at'))->toBeNull()
        ->and($fresh?->getAttribute('next_retry_at'))->toBeNull()
        ->and($fresh?->getAttribute('last_retry_at'))->not->toBeNull();
});

it('marks a transaction as retrying', function (): void {
    $transaction = phaseSevenCreateTransaction([
        'status' => 'failed',
        'last_retry_at' => null,
    ]);

    $transaction->markRetrying();

    expect($transaction->fresh()?->getAttribute('status'))->toBe('retrying')
        ->and($transaction->fresh()?->getAttribute('last_retry_at'))->not->toBeNull();
});

it('marks a transaction as failed and can replace provider response with null', function (): void {
    $transaction = phaseSevenCreateTransaction([
        'status' => 'processing',
        'provider_response' => ['old' => 'payload'],
    ]);

    $transaction->markFailed(
        reason: 'Gateway timeout',
        retryCount: 2,
        nextRetryAt: now()->addHour(),
        providerResponse: null,
        replaceProviderResponse: true,
    );

    $fresh = $transaction->fresh();

    expect($fresh?->getAttribute('status'))->toBe('failed')
        ->and($fresh?->getAttribute('retry_count'))->toBe(2)
        ->and($fresh?->getAttribute('failure_reason'))->toBe('Gateway timeout')
        ->and($fresh?->getAttribute('processed_at'))->not->toBeNull()
        ->and($fresh?->getAttribute('last_retry_at'))->not->toBeNull()
        ->and($fresh?->getAttribute('next_retry_at'))->not->toBeNull()
        ->and($fresh?->getAttribute('provider_response'))->toBeNull();
});

it('marks a transaction as processed and preserves old provider response unless replacement is requested', function (): void {
    $transaction = phaseSevenCreateTransaction([
        'status' => 'processing',
        'provider_response' => ['old' => 'payload'],
        'failure_reason' => 'Old failure',
    ]);

    $transaction->markProcessed('tx-123');

    $fresh = $transaction->fresh();

    expect($fresh?->getAttribute('status'))->toBe('processed')
        ->and($fresh?->getAttribute('provider_transaction_id'))->toBe('tx-123')
        ->and($fresh?->getAttribute('failure_reason'))->toBeNull()
        ->and($fresh?->getAttribute('processed_at'))->not->toBeNull()
        ->and($fresh?->getAttribute('provider_response'))->toBe(['old' => 'payload']);

    $transaction->markProcessed('tx-456', null, true);

    $fresh = $transaction->fresh();

    expect($fresh?->getAttribute('provider_transaction_id'))->toBe('tx-456')
        ->and($fresh?->getAttribute('provider_response'))->toBeNull();
});

it('allows processed transactions without a provider transaction id', function (): void {
    $transaction = phaseSevenCreateTransaction([
        'status' => 'processing',
    ]);

    $transaction->markProcessed(null, ['charge_success' => true], true);

    $fresh = $transaction->fresh();

    expect($fresh?->getAttribute('status'))->toBe('processed')
        ->and($fresh?->getAttribute('provider_transaction_id'))->toBeNull()
        ->and($fresh?->getAttribute('provider_response'))->toBe(['charge_success' => true]);
});
