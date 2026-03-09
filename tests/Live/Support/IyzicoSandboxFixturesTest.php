<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

it('returns named sandbox card fixtures with valid future expiry and cvc', function (): void {
    $card = IyzicoSandboxFixtures::card('success_debit_tr');

    expect($card['card_number'])->toBe('5890040000000016')
        ->and($card['card_holder_name'])->toBe('Phase Eight Sandbox')
        ->and($card['cvc'])->toHaveLength(3)
        ->and((int) $card['expire_month'])->toBeGreaterThanOrEqual(1)
        ->and((int) $card['expire_month'])->toBeLessThanOrEqual(12)
        ->and((int) $card['expire_year'])->toBeGreaterThanOrEqual((int) now()->format('Y'));
});

it('returns the configured foreign and failure cards', function (): void {
    expect(IyzicoSandboxFixtures::card('success_foreign_credit')['card_number'])->toBe('5400010000000004')
        ->and(IyzicoSandboxFixtures::card('fail_insufficient_funds')['card_number'])->toBe('4111111111111129')
        ->and(IyzicoSandboxFixtures::card('fail_3ds_initialize')['card_number'])->toBe('4151111111111112');
});

it('throws for unknown card fixtures', function (): void {
    IyzicoSandboxFixtures::card('does-not-exist');
})->throws(InvalidArgumentException::class, 'Unknown iyzico sandbox card fixture');

it('builds canonical payment payloads from named fixtures', function (): void {
    $context = IyzicoSandboxRunContext::create('payment-contracts');
    $payload = IyzicoSandboxFixtures::paymentPayload('success_debit_tr', $context);

    expect($payload['payment_card']['card_number'])->toBe('5890040000000016')
        ->and($payload['buyer']['id'])->toContain($context->runId())
        ->and($payload['buyer']['email'])->toEndWith('@example.com')
        ->and(strlen($payload['buyer']['email']))->toBeLessThan(80)
        ->and($payload['basket_items'][0]['item_type'])->toBe('VIRTUAL');
});

it('builds canonical subscription payloads from named fixtures', function (): void {
    $context = IyzicoSandboxRunContext::create('subscription-contracts');
    $payload = IyzicoSandboxFixtures::subscriptionPayload('success_debit_tr', $context, 77);

    expect($payload['payable_id'])->toBe(77)
        ->and($payload['payment_method']['card_last_four'])->toBe('0016')
        ->and($payload['customer']['email'])->toEndWith('@example.com')
        ->and(strlen($payload['customer']['email']))->toBeLessThan(80);
});
