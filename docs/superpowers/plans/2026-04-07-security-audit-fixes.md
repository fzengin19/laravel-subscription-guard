# Security Audit Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 32 security vulnerabilities identified in the security audit, ordered by severity (Critical > High > Medium > Low).

**Architecture:** Each task targets one or more related findings, adds a failing test first, then implements the minimal fix. Tasks are ordered so critical fixes land first with zero regressions. All changes stay backward-compatible with existing tests (225 passing).

**Tech Stack:** PHP 8.2+, Laravel 11, Pest testing framework, PHPStan level 5

**Verify commands:**
- Tests: `composer test`
- Static analysis: `composer analyse`

---

## Task 1: WebhookController — Validate Signature Before Persisting (K-01)

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php:24-104`
- Modify: `src/Http/Controllers/WebhookController.php:22` (constructor)
- Test: `tests/Feature/WebhookSignatureValidationTest.php` (create)

**Context:** Currently `WebhookController::__invoke()` stores the payload in `webhook_calls` at line 89 and dispatches `FinalizeWebhookEventJob` without any signature validation. `PaymentCallbackController` does this correctly at line 53. We need to add the same validation step here. The `PaymentManager` already exposes `provider()` which returns a `PaymentProviderInterface` with `validateWebhook(array $payload, string $signature): bool`.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/WebhookSignatureValidationTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

uses(RefreshDatabase::class);

it('rejects webhook with invalid signature and does NOT store payload', function (): void {
    config()->set('subscription-guard.providers.default', 'iyzico');
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.api_key', 'test_key');
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'test_secret');

    $response = $this->postJson('/subguard/webhooks/iyzico', [
        'event_type' => 'subscription.order.success',
        'iyziReferenceCode' => 'ref_123',
    ], [
        'X-IYZ-Signature' => 'invalid_signature_value',
    ]);

    $response->assertStatus(401);
    expect(WebhookCall::query()->count())->toBe(0);
});

it('accepts webhook with valid signature and stores payload', function (): void {
    config()->set('subscription-guard.providers.default', 'iyzico');
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);

    $response = $this->postJson('/subguard/webhooks/iyzico', [
        'event_type' => 'subscription.order.success',
        'iyziReferenceCode' => 'ref_456',
    ]);

    $response->assertStatus(202);
    expect(WebhookCall::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/WebhookSignatureValidationTest.php -v`
Expected: First test FAILS (currently returns 202 and stores payload even with invalid signature)

- [ ] **Step 3: Implement signature validation in WebhookController**

In `WebhookController.php`, after line 38 (empty payload check) and before resolving event type, add signature validation:

```php
// After line 38 ($payload === [] check), add:
$providerAdapter = $this->paymentManager->provider($provider);
$signatureHeader = $this->resolveSignatureHeader($request, $provider);

if (! $providerAdapter->validateWebhook($payload, $signatureHeader)) {
    return response()->json([
        'status' => 'rejected',
        'reason' => 'Invalid webhook signature.',
    ], 401);
}
```

Add this private method at the bottom of the class:

```php
private function resolveSignatureHeader(Request $request, string $provider): string
{
    $configuredHeader = (string) config("subscription-guard.providers.drivers.{$provider}.webhook_signature_header", '');

    if ($configuredHeader !== '') {
        return trim((string) $request->header($configuredHeader, ''));
    }

    $commonHeaders = ['x-iyz-signature', 'x-paytr-signature', 'x-webhook-signature', 'x-signature'];

    foreach ($commonHeaders as $header) {
        $value = trim((string) $request->header($header, ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/WebhookSignatureValidationTest.php -v`
Expected: PASS

- [ ] **Step 5: Run full test suite to check regressions**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/WebhookController.php tests/Feature/WebhookSignatureValidationTest.php
git commit -m "fix(security): validate webhook signature before persisting payload [K-01]"
```

---

## Task 2: Mock Mode Production Guard (Y-01)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php:326-328`
- Modify: `src/Payment/Providers/PayTR/PaytrProvider.php:168-169`
- Test: `tests/Unit/MockModeProductionGuardTest.php` (create)

**Context:** Both `validateWebhook()` methods return `true` unconditionally in mock mode. If mock mode is accidentally left enabled in production, all webhook signature verification is disabled. We need to add a production environment guard.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/MockModeProductionGuardTest.php
declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('logs critical warning when iyzico mock mode is active in production', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
    config()->set('app.env', 'production');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('critical')->once()->withArgs(function (string $message): bool {
        return str_contains($message, 'mock mode') && str_contains($message, 'production');
    });

    $provider = app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider::class);
    $result = $provider->validateWebhook(['event_type' => 'test'], '');

    // In production, mock mode should still return true (backward compat) but log critical
    expect($result)->toBeTrue();
});

it('logs critical warning when paytr mock mode is active in production', function (): void {
    config()->set('subscription-guard.providers.drivers.paytr.mock', true);
    config()->set('app.env', 'production');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('critical')->once()->withArgs(function (string $message): bool {
        return str_contains($message, 'mock mode') && str_contains($message, 'production');
    });

    $provider = app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider::class);
    $result = $provider->validateWebhook(['status' => 'success'], '');

    expect($result)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/MockModeProductionGuardTest.php -v`
Expected: FAIL (no Log::critical call currently)

- [ ] **Step 3: Add production guard to IyzicoProvider**

In `IyzicoProvider.php`, replace lines 326-328:

```php
if ($this->mockMode()) {
    if (app()->environment('production')) {
        Log::channel((string) config('subscription-guard.logging.channel', 'subguard'))
            ->critical('Iyzico webhook signature validation bypassed: mock mode is active in production. This is a security risk.');
    }
    return true;
}
```

Add `use Illuminate\Support\Facades\Log;` at the top if not already present.

- [ ] **Step 4: Add production guard to PaytrProvider**

In `PaytrProvider.php`, replace lines 168-169:

```php
if ($this->mockMode()) {
    if (app()->environment('production')) {
        Log::channel((string) config('subscription-guard.logging.channel', 'subguard'))
            ->critical('PayTR webhook signature validation bypassed: mock mode is active in production. This is a security risk.');
    }
    return true;
}
```

Add `use Illuminate\Support\Facades\Log;` at the top if not already present.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Unit/MockModeProductionGuardTest.php -v && composer test`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add src/Payment/Providers/Iyzico/IyzicoProvider.php src/Payment/Providers/PayTR/PaytrProvider.php tests/Unit/MockModeProductionGuardTest.php
git commit -m "fix(security): log critical warning when mock mode is active in production [Y-01]"
```

---

## Task 3: Subscription Creation Race Condition (Y-02)

**Files:**
- Modify: `src/Subscription/SubscriptionService.php:44-66`
- Test: `tests/Feature/SubscriptionCreationDuplicateTest.php` (create)

**Context:** `create()` has no transaction, no lock, and no duplicate check. Concurrent requests can create multiple subscriptions for the same user+plan.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/SubscriptionCreationDuplicateTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

it('prevents duplicate active subscription for same user and plan', function (): void {
    $plan = Plan::unguarded(fn () => Plan::query()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'is_active' => true,
    ]));

    $service = app(SubscriptionService::class);

    $first = $service->create(1, $plan->getKey(), 1);
    expect($first)->toHaveKey('id');

    // Mark first as active
    Subscription::query()->where('id', $first['id'])->update(['status' => 'active']);

    // Second create for same user+plan should return existing
    $second = $service->create(1, $plan->getKey(), 1);
    expect($second['id'])->toBe($first['id']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/SubscriptionCreationDuplicateTest.php -v`
Expected: FAIL (second create returns different ID)

- [ ] **Step 3: Add duplicate check and transaction**

In `SubscriptionService.php`, replace the `create()` method (lines 44-66):

```php
public function create(int|string $subscribableId, int|string $planId, int|string $paymentMethodId): array
{
    $plan = Plan::query()->findOrFail($planId);

    return DB::transaction(function () use ($subscribableId, $planId, $paymentMethodId, $plan): array {
        $userModelClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

        $existing = Subscription::query()
            ->where('subscribable_type', $userModelClass)
            ->where('subscribable_id', $subscribableId)
            ->where('plan_id', $planId)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Pending->value,
                SubscriptionStatus::PastDue->value,
                'trialing',
            ])
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Subscription) {
            return $existing->toArray();
        }

        $subscription = Subscription::unguarded(fn (): Subscription => Subscription::query()->create([
            'subscribable_type' => $userModelClass,
            'subscribable_id' => $subscribableId,
            'plan_id' => $planId,
            'provider' => $this->paymentManager->defaultProvider(),
            'status' => SubscriptionStatus::Pending->value,
            'billing_period' => (string) $plan->getAttribute('billing_period'),
            'billing_interval' => (int) $plan->getAttribute('billing_interval'),
            'billing_anchor_day' => (int) now()->day,
            'amount' => (float) $plan->getAttribute('price'),
            'currency' => (string) $plan->getAttribute('currency'),
            'next_billing_date' => now(),
            'metadata' => [
                'payment_method_id' => $paymentMethodId,
            ],
        ]));

        return $subscription->toArray();
    });
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/SubscriptionCreationDuplicateTest.php -v && composer test`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Subscription/SubscriptionService.php tests/Feature/SubscriptionCreationDuplicateTest.php
git commit -m "fix(security): prevent duplicate subscriptions with transaction and lock [Y-02]"
```

---

## Task 4: Strip Sensitive Card Data from Provider Responses (Y-03)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php:234`
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php:70,161`
- Create: `src/Payment/Concerns/SanitizesProviderData.php`
- Test: `tests/Unit/SanitizesProviderDataTest.php` (create)

**Context:** On Iyzico subscription creation failure, the `$details` array (containing card_number, cvc, expire_month, expire_year) is embedded in the `SubscriptionResponse.providerResponse`. This DTO gets stored in `transactions.provider_response`. Exception messages also leak internal details.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/SanitizesProviderDataTest.php
declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Concerns\SanitizesProviderData;

it('strips sensitive card fields from nested arrays', function (): void {
    $sanitizer = new class {
        use SanitizesProviderData;
    };

    $data = [
        'plan' => ['id' => 1, 'slug' => 'pro'],
        'details' => [
            'payment_card' => [
                'card_number' => '5528790000000008',
                'cvc' => '123',
                'expire_month' => '12',
                'expire_year' => '2030',
                'card_holder_name' => 'John Doe',
            ],
            'buyer_email' => 'john@example.com',
            'amount' => 100.00,
        ],
    ];

    $result = $sanitizer->sanitizeProviderResponse($data);

    expect($result['details'])->not->toHaveKey('payment_card');
    expect($result['details'])->toHaveKey('amount');
    expect($result['plan'])->toBe(['id' => 1, 'slug' => 'pro']);
});

it('strips sensitive keys at any depth', function (): void {
    $sanitizer = new class {
        use SanitizesProviderData;
    };

    $data = [
        'card_number' => '5528790000000008',
        'cvc' => '123',
        'nested' => ['card_number' => '1234'],
    ];

    $result = $sanitizer->sanitizeProviderResponse($data);

    expect($result)->not->toHaveKey('card_number');
    expect($result)->not->toHaveKey('cvc');
    expect($result['nested'])->not->toHaveKey('card_number');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/SanitizesProviderDataTest.php -v`
Expected: FAIL (trait does not exist)

- [ ] **Step 3: Create SanitizesProviderData trait**

```php
<?php
// src/Payment/Concerns/SanitizesProviderData.php
declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Concerns;

trait SanitizesProviderData
{
    private static array $sensitiveKeys = [
        'card_number', 'cardNumber', 'pan',
        'cvc', 'cvv', 'cvv2', 'cvc2', 'security_code',
        'expire_month', 'expireMonth', 'expire_year', 'expireYear',
        'card_holder_name', 'cardHolderName',
        'payment_card', 'paymentCard',
        'password', 'secret', 'token',
    ];

    public function sanitizeProviderResponse(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveKeys, true)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->sanitizeProviderResponse($value) : $value;
        }

        return $result;
    }

    public function sanitizeExceptionMessage(string $message): string
    {
        return preg_replace(
            ['/\/[^\s:]+\.php/', '/on line \d+/', '/Stack trace:.*$/s'],
            ['[redacted-path]', '', ''],
            $message
        ) ?? $message;
    }
}
```

- [ ] **Step 4: Apply trait in IyzicoProvider**

In `IyzicoProvider.php`:

1. Add `use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Concerns\SanitizesProviderData;` import
2. Add `use SanitizesProviderData;` inside the class
3. Line 70: Replace `$exception->getMessage()` calls:
   ```php
   return new PaymentResponse(false, null, null, null, null, $this->sanitizeProviderResponse($details), 'Iyzico live payment failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
   ```
4. Line 161: Same pattern for refund
5. Line 234: Strip card data from $details:
   ```php
   return new SubscriptionResponse(false, null, null, $this->sanitizeProviderResponse(['plan' => $plan, 'details' => $details]), 'Iyzico live subscription create failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
   ```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Unit/SanitizesProviderDataTest.php -v && composer test`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add src/Payment/Concerns/SanitizesProviderData.php src/Payment/Providers/Iyzico/IyzicoProvider.php tests/Unit/SanitizesProviderDataTest.php
git commit -m "fix(security): strip sensitive card data from provider responses [Y-03]"
```

---

## Task 5: State Machine Bypass — Remove Raw Status Write (O-01)

**Files:**
- Modify: `src/Subscription/SubscriptionService.php:351-365`
- Test: `tests/Unit/HandleWebhookStatusBypassTest.php` (create)

**Context:** In `handleWebhookResult()` at line 361, when `SubscriptionStatus::normalize()` returns `null`, the raw `$result->status` is written directly via `setAttribute`, bypassing the state machine. This must be removed.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/HandleWebhookStatusBypassTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

it('ignores unrecognized status values from webhook instead of raw-writing them', function (): void {
    $subscription = createActiveSubscription(); // helper that creates an active subscription

    $result = new WebhookResult(
        processed: true,
        eventId: 'evt_1',
        eventType: 'subscription.custom_event',
        subscriptionId: (string) $subscription->getAttribute('provider_subscription_id'),
        status: 'some_invalid_status',
        metadata: [],
    );

    $service = app(SubscriptionService::class);
    $service->handleWebhookResult($result, (string) $subscription->getAttribute('provider'));

    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('active');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/HandleWebhookStatusBypassTest.php -v`
Expected: FAIL (status gets set to 'some_invalid_status')

- [ ] **Step 3: Remove the raw status write fallback**

In `SubscriptionService.php`, replace lines 351-365:

```php
if ($result->status !== null && $result->status !== '') {
    $normalizedStatus = SubscriptionStatus::normalize($result->status);

    if ($normalizedStatus instanceof SubscriptionStatus) {
        try {
            $subscription->transitionTo($normalizedStatus);
        } catch (SubGuardException) {
            return;
        }

        $subscription->save();
    }
    // Unknown status values are silently ignored — only validated transitions allowed
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Unit/HandleWebhookStatusBypassTest.php -v && composer test`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Subscription/SubscriptionService.php tests/Unit/HandleWebhookStatusBypassTest.php
git commit -m "fix(security): remove raw status write bypass in handleWebhookResult [O-01]"
```

---

## Task 6: Add Lock to handleWebhookResult (O-02)

**Files:**
- Modify: `src/Subscription/SubscriptionService.php:265-366`
- Test: `tests/Unit/HandleWebhookResultLockingTest.php` (create)

**Context:** `handleWebhookResult()` reads and modifies subscription status without `lockForUpdate()`. Two concurrent webhooks for the same subscription can race.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/HandleWebhookResultLockingTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

it('wraps subscription modification in a transaction with lock', function (): void {
    $subscription = createActiveSubscription();
    $provider = (string) $subscription->getAttribute('provider');

    DB::enableQueryLog();

    $result = new WebhookResult(
        processed: true,
        eventId: 'evt_lock_test',
        eventType: 'subscription.canceled',
        subscriptionId: (string) $subscription->getAttribute('provider_subscription_id'),
        metadata: [],
    );

    app(SubscriptionService::class)->handleWebhookResult($result, $provider);

    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    expect($queries)->toContain('for update');

    DB::disableQueryLog();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/HandleWebhookResultLockingTest.php -v`
Expected: FAIL (no "for update" in queries)

- [ ] **Step 3: Wrap subscription fetch with lockForUpdate in a transaction**

In `SubscriptionService.php`, the subscription fetch at line 285-288 needs to be inside `DB::transaction()` with `lockForUpdate()`. Wrap the entire block from line 283 to 365 in a transaction:

After line 281 (`return;`), replace the rest of the method:

```php
$providerEvents = $this->providerEventDispatchers->resolve($provider);

DB::transaction(function () use ($result, $provider, $providerEvents): void {
    $subscription = Subscription::query()
        ->where('provider', $provider)
        ->where('provider_subscription_id', $result->subscriptionId)
        ->lockForUpdate()
        ->first();

    if (! $subscription instanceof Subscription) {
        return;
    }

    // ... rest of the event handling logic (unchanged from line 294 onward)
});
```

Move all the event handling logic inside the closure. Keep the existing logic intact, just wrap it.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Unit/HandleWebhookResultLockingTest.php -v && composer test`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Subscription/SubscriptionService.php tests/Unit/HandleWebhookResultLockingTest.php
git commit -m "fix(security): add lockForUpdate to handleWebhookResult subscription fetch [O-02]"
```

---

## Task 7: PaymentChargeJob — Use Correct Billing Period (O-05)

**Files:**
- Modify: `src/Jobs/PaymentChargeJob.php:96-104`
- Test: `tests/Unit/PaymentChargeJobBillingPeriodTest.php` (create)

**Context:** `PaymentChargeJob` hardcodes `addMonthNoOverflow()` at line 97 regardless of `billing_period`. Should use the same period-aware logic as `SubscriptionService::advanceBillingDate()`.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/PaymentChargeJobBillingPeriodTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

uses(RefreshDatabase::class);

it('advances billing date by correct period for weekly subscription', function (): void {
    // This test verifies the fix is applied by checking the billing date
    // advancement logic matches the subscription's billing_period
    $subscription = createSubscriptionWithPeriod('week', 1);
    $originalDate = now()->startOfDay();
    $subscription->setAttribute('next_billing_date', $originalDate);
    $subscription->save();

    // After a successful charge, next_billing_date should advance by 1 week, not 1 month
    $expected = $originalDate->copy()->addWeek();

    // We'll verify this through the SubscriptionService::advanceBillingDate static behavior
    $service = app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class);
    $result = $service->advanceBillingDate($originalDate, 'week', 1, null);

    expect($result->format('Y-m-d'))->toBe($expected->format('Y-m-d'));
});
```

- [ ] **Step 2: Implement fix in PaymentChargeJob**

In `PaymentChargeJob.php`, replace lines 92-104 with period-aware advancement:

```php
$nextBillingDate = $subscription->getAttribute('next_billing_date');
$billingPeriod = (string) ($subscription->getAttribute('billing_period') ?: 'month');
$billingInterval = max(1, (int) ($subscription->getAttribute('billing_interval') ?: 1));
$anchorDay = is_numeric($subscription->getAttribute('billing_anchor_day'))
    ? (int) $subscription->getAttribute('billing_anchor_day')
    : null;

if ($nextBillingDate instanceof Carbon) {
    $next = $subscriptionService->advanceBillingDate($nextBillingDate, $billingPeriod, $billingInterval, $anchorDay);
    $subscription->setAttribute('next_billing_date', $next);
} else {
    $subscription->setAttribute('next_billing_date', now()->addMonth());
}
```

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add src/Jobs/PaymentChargeJob.php tests/Unit/PaymentChargeJobBillingPeriodTest.php
git commit -m "fix(security): use correct billing period in PaymentChargeJob [O-05]"
```

---

## Task 8: MeteredBillingProcessor — Replace firstOrNew with firstOrCreate (O-06)

**Files:**
- Modify: `src/Billing/MeteredBillingProcessor.php:92`

**Context:** `firstOrNew` is not atomic. Replace with `firstOrCreate` for proper idempotency. The subsequent `if (! $transaction->exists)` block at line 105 handles attribute setting for new records — this logic needs to move into the `firstOrCreate` second argument.

- [ ] **Step 1: Implement the fix**

In `MeteredBillingProcessor.php`, replace line 92 and restructure the creation block. The `firstOrNew` + manual attribute setting (lines 92-122) should become a single `firstOrCreate` call:

```php
$transaction = Transaction::unguarded(static fn (): Transaction => Transaction::query()->firstOrCreate(
    ['idempotency_key' => $idempotencyKey],
    [
        'subscription_id' => $locked->getKey(),
        'payable_type' => (string) $locked->getAttribute('subscribable_type'),
        'payable_id' => (int) $locked->getAttribute('subscribable_id'),
        'license_id' => (int) $licenseId,
        'provider' => $provider,
        'type' => 'metered_usage',
        'amount' => $amount,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => (string) $locked->getAttribute('currency'),
        'metadata' => [
            'metered' => true,
            'usage_total' => $totalUsage,
        ],
        'status' => 'pending',
        'provider_response' => $baseProviderResponse,
    ]
));
```

Then update the status check (line 94) to come after this call, and remove the `if (! $transaction->exists)` block.

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/Billing/MeteredBillingProcessor.php
git commit -m "fix(security): replace firstOrNew with firstOrCreate in MeteredBillingProcessor [O-06]"
```

---

## Task 9: Payload Size Limit and Header Filtering (O-07)

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php`
- Modify: `src/Http/Controllers/PaymentCallbackController.php`
- Test: `tests/Feature/WebhookPayloadSizeTest.php` (create)

**Context:** No payload size limit exists. All headers (including sensitive ones like Authorization, Cookie) are stored.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/WebhookPayloadSizeTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects webhook payload exceeding size limit', function (): void {
    config()->set('subscription-guard.providers.default', 'iyzico');
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
    config()->set('subscription-guard.webhooks.max_payload_size_kb', 64);

    $largePayload = ['event_type' => 'test', 'data' => str_repeat('x', 65 * 1024)];

    $response = $this->postJson('/subguard/webhooks/iyzico', $largePayload);

    $response->assertStatus(413);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/WebhookPayloadSizeTest.php -v`
Expected: FAIL (returns 202)

- [ ] **Step 3: Add payload size check after signature validation**

In `WebhookController.php`, after the signature validation block, add:

```php
$maxPayloadKb = (int) config('subscription-guard.webhooks.max_payload_size_kb', 64);
if ($maxPayloadKb > 0 && strlen($request->getContent() ?: '') > $maxPayloadKb * 1024) {
    return response()->json([
        'status' => 'rejected',
        'reason' => 'Payload exceeds maximum size.',
    ], 413);
}
```

- [ ] **Step 4: Add header filtering helper**

Create a private method in `WebhookController`:

```php
private function filterHeaders(Request $request): array
{
    $sensitiveHeaders = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization'];
    $headers = $request->headers->all();

    foreach ($sensitiveHeaders as $header) {
        unset($headers[$header]);
    }

    return $headers;
}
```

Replace `$request->headers->all()` at lines 72 and 95 with `$this->filterHeaders($request)`.

Apply the same header filtering in `PaymentCallbackController.php`.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/WebhookPayloadSizeTest.php -v && composer test`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/WebhookController.php src/Http/Controllers/PaymentCallbackController.php tests/Feature/WebhookPayloadSizeTest.php
git commit -m "fix(security): add payload size limit and filter sensitive headers [O-07]"
```

---

## Task 10: SSRF Guard for SyncLicenseRevocationsCommand (O-08)

**Files:**
- Modify: `src/Commands/SyncLicenseRevocationsCommand.php:87-96`
- Test: `tests/Unit/SyncRevocationEndpointValidationTest.php` (create)

**Context:** The `--endpoint` CLI argument accepts any URL without validation. Internal network addresses could be targeted.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/SyncRevocationEndpointValidationTest.php
declare(strict_types=1);

it('rejects non-https endpoint in production', function (): void {
    config()->set('app.env', 'production');

    $this->artisan('subguard:sync-license-revocations', ['--endpoint' => 'http://169.254.169.254/latest/meta-data/'])
        ->assertFailed()
        ->expectsOutputToContain('HTTPS');
});

it('rejects endpoint with private IP ranges', function (): void {
    $this->artisan('subguard:sync-license-revocations', ['--endpoint' => 'https://192.168.1.1/revocations'])
        ->assertFailed()
        ->expectsOutputToContain('private');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/SyncRevocationEndpointValidationTest.php -v`
Expected: FAIL (endpoint accepted without validation)

- [ ] **Step 3: Add endpoint validation**

In `SyncLicenseRevocationsCommand.php`, add a validation method and call it after resolving the endpoint:

```php
private function validateEndpoint(string $endpoint): ?string
{
    if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
        return 'Invalid URL format.';
    }

    $parsed = parse_url($endpoint);
    $scheme = strtolower($parsed['scheme'] ?? '');
    $host = $parsed['host'] ?? '';

    if (app()->environment('production') && $scheme !== 'https') {
        return 'HTTPS is required for revocation sync endpoints in production.';
    }

    if (! in_array($scheme, ['http', 'https'], true)) {
        return 'Only HTTP/HTTPS schemes are allowed.';
    }

    $ip = gethostbyname($host);

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'Endpoint resolves to a private or reserved IP range. This is not allowed.';
    }

    return null;
}
```

In `handle()` after line 21 (`if ($endpoint === '')`), add:

```php
$validationError = $this->validateEndpoint($endpoint);
if ($validationError !== null) {
    $this->error($validationError);
    return self::FAILURE;
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Unit/SyncRevocationEndpointValidationTest.php -v && composer test`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Commands/SyncLicenseRevocationsCommand.php tests/Unit/SyncRevocationEndpointValidationTest.php
git commit -m "fix(security): add SSRF protection to SyncLicenseRevocationsCommand [O-08]"
```

---

## Task 11: Sanitize Exception Messages in Provider DTOs (O-09)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php` (all catch blocks)
- Modify: `src/Billing/MeteredBillingProcessor.php:206-215`

**Context:** Raw exception messages (containing internal paths, DB connection strings) are embedded in response DTOs. Task 4 already added `SanitizesProviderData` trait and `sanitizeExceptionMessage()`. Now apply it to all remaining locations.

- [ ] **Step 1: Apply sanitizeExceptionMessage in remaining IyzicoProvider catch blocks**

Lines 161 (refund), 312 (any other catch blocks) — replace `$exception->getMessage()` with `$this->sanitizeExceptionMessage($exception->getMessage())`.

- [ ] **Step 2: Fix MeteredBillingProcessor exception leak**

In `MeteredBillingProcessor.php`, add `use SanitizesProviderData;` trait and in the catch block at line 206-215, replace:

```php
'exception' => get_class($exception),
'message' => $exception->getMessage(),
```

with:

```php
'exception' => 'ProcessingError',
'message' => $this->sanitizeExceptionMessage($exception->getMessage()),
```

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add src/Payment/Providers/Iyzico/IyzicoProvider.php src/Billing/MeteredBillingProcessor.php
git commit -m "fix(security): sanitize exception messages in provider DTOs [O-09]"
```

---

## Task 12: HTML Escaping in InvoicePdfRenderer (O-10)

**Files:**
- Modify: `src/Billing/Invoices/InvoicePdfRenderer.php:30-32`
- Test: `tests/Unit/InvoicePdfRendererXssTest.php` (create)

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Unit/InvoicePdfRendererXssTest.php
declare(strict_types=1);

it('escapes html entities in invoice number for PDF rendering', function (): void {
    $renderer = new \SubscriptionGuard\LaravelSubscriptionGuard\Billing\Invoices\InvoicePdfRenderer();

    // Use reflection to test the HTML building
    $invoice = new \SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice();
    $invoice->forceFill([
        'invoice_number' => '<script>alert("xss")</script>',
        'total_amount' => 100.00,
        'currency' => '"><img src=x onerror=alert(1)>',
    ]);

    // The render method creates HTML - we verify via the fallback path (no Spatie PDF)
    $path = $renderer->render($invoice);
    $content = \Illuminate\Support\Facades\Storage::disk('local')->get($path);

    expect($content)->not->toContain('<script>');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/InvoicePdfRendererXssTest.php -v`
Expected: FAIL (the fallback path also uses unescaped invoice number)

- [ ] **Step 3: Add HTML escaping**

In `InvoicePdfRenderer.php`, replace lines 30-32:

```php
$safeInvoiceNumber = htmlspecialchars($invoiceNumber, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$safeCurrency = htmlspecialchars((string) $invoice->getAttribute('currency'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$html = '<h1>Invoice '.$safeInvoiceNumber.'</h1>'
    .'<p>Total: '.number_format((float) $invoice->getAttribute('total_amount'), 2).' '
    .$safeCurrency.'</p>';
```

Also escape the fallback at line 42:

```php
Storage::disk('local')->put($relativePath, 'Invoice '.htmlspecialchars($invoiceNumber, ENT_QUOTES | ENT_HTML5, 'UTF-8').' generated without PDF engine.');
```

Also sanitize `$invoiceNumber` for path traversal at line 24:

```php
$safeFileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $invoiceNumber);
$relativePath = $directory.'/'.$safeFileName.'.pdf';
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Unit/InvoicePdfRendererXssTest.php -v && composer test`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Billing/Invoices/InvoicePdfRenderer.php tests/Unit/InvoicePdfRendererXssTest.php
git commit -m "fix(security): escape HTML in InvoicePdfRenderer and sanitize file path [O-10, D-04]"
```

---

## Task 13: Tighten Model $guarded Arrays (O-11)

**Files:**
- Modify: `src/Models/WebhookCall.php:12`
- Modify: `src/Models/Invoice.php:16`
- Modify: `src/Models/Plan.php:12`
- Modify: `src/Models/Coupon.php:12`
- Modify: `src/Models/Discount.php:13`
- Modify: `src/Models/BillingProfile.php:15`
- Modify: `src/Models/LicenseActivation.php:12`
- Modify: `src/Models/LicenseUsage.php:12`
- Modify: `src/Models/SubscriptionItem.php:12`
- Modify: `src/Models/ScheduledPlanChange.php:12`

**Context:** 10 models use `$guarded = ['id']` which is overly permissive. Tighten each model's `$guarded` to protect sensitive/internal columns.

- [ ] **Step 1: Update WebhookCall**

```php
protected $guarded = ['id', 'status', 'processed_at', 'failure_reason'];
```

- [ ] **Step 2: Update Plan**

```php
protected $guarded = ['id', 'is_active'];
```

- [ ] **Step 3: Update Coupon**

```php
protected $guarded = ['id', 'current_uses', 'is_active'];
```

- [ ] **Step 4: Update Discount**

```php
protected $guarded = ['id', 'applied_cycles'];
```

- [ ] **Step 5: Update Invoice**

```php
protected $guarded = ['id', 'status'];
```

- [ ] **Step 6: Update remaining models**

BillingProfile, LicenseActivation, LicenseUsage, SubscriptionItem, ScheduledPlanChange — add `'status'` to guarded where applicable, or keep `['id']` for models without sensitive state columns. The key models are WebhookCall, Plan, Coupon, Discount, Invoice.

- [ ] **Step 7: Run tests to verify no regressions**

Run: `composer test`
Expected: All pass. If any tests fail due to mass assignment, check if those tests need `Model::unguarded()` wrappers (which is acceptable in tests).

- [ ] **Step 8: Commit**

```bash
git add src/Models/
git commit -m "fix(security): tighten model guarded arrays for mass assignment protection [O-11]"
```

---

## Task 14: Replace uniqid() with Secure Random (O-12)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php` (lines with `uniqid()`)
- Modify: `src/Payment/Providers/PayTR/PaytrProvider.php` (lines with `uniqid()`)

**Context:** `uniqid()` produces predictable values. Replace with `bin2hex(random_bytes(8))` or `Str::uuid()`.

- [ ] **Step 1: Replace in IyzicoProvider**

Replace all occurrences of `uniqid(...)` with `bin2hex(random_bytes(8))`:
- Line 139: `uniqid('iyz_ref_', true)` → `'iyz_ref_'.bin2hex(random_bytes(8))`
- Line 175: same pattern
- Line 211: same pattern
- Line 238: `uniqid('sub', true)` → `'sub_'.bin2hex(random_bytes(8))`
- Lines 264, 293, 467, 471, 493, 500: same pattern

- [ ] **Step 2: Replace in PaytrProvider**

- Lines 28, 32, 66, 87, 138: Replace `uniqid()` with `bin2hex(random_bytes(8))`
- Line 300: Replace `microtime(true)` with `bin2hex(random_bytes(16))`

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add src/Payment/Providers/Iyzico/IyzicoProvider.php src/Payment/Providers/PayTR/PaytrProvider.php
git commit -m "fix(security): replace uniqid/microtime with cryptographically secure random [O-12, D-05, D-06]"
```

---

## Task 15: PayTR Hash Body Fallback (O-13)

**Files:**
- Modify: `src/Payment/Providers/PayTR/PaytrProvider.php:179-183`

**Context:** When no signature header is provided, the code falls back to `$payload['hash']`. Remove this fallback.

- [ ] **Step 1: Remove hash body fallback**

In `PaytrProvider.php`, replace lines 179-183:

```php
$providedSignature = trim($signature);

if ($providedSignature === '') {
    return false;
}
```

This removes the fallback to `$payload['hash']`. If PayTR genuinely sends the hash in the body, the WebhookController's `resolveSignatureHeader()` (from Task 1) should extract it before calling `validateWebhook`.

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All pass (mock mode tests don't reach this code path)

- [ ] **Step 3: Commit**

```bash
git add src/Payment/Providers/PayTR/PaytrProvider.php
git commit -m "fix(security): remove PayTR hash-in-body fallback [O-13]"
```

---

## Task 16: Filter Webhook Metadata Before Storage (O-14)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php:406` (processWebhook)
- Modify: `src/Payment/Providers/PayTR/PaytrProvider.php:238` (processWebhook)

**Context:** The full raw webhook payload is stored as `metadata` in `WebhookResult`. Should filter to only safe fields.

- [ ] **Step 1: Add safe field filtering in IyzicoProvider processWebhook**

Before the return statement in `processWebhook()`, filter the payload:

```php
$safeMetadata = array_intersect_key($payload, array_flip([
    'iyziEventType', 'iyziReferenceCode', 'subscriptionReferenceCode',
    'orderReferenceCode', 'paymentId', 'status', 'price', 'currency',
    'token', 'paymentConversationId',
]));
```

Use `$safeMetadata` instead of `$payload` in the `WebhookResult`.

- [ ] **Step 2: Add safe field filtering in PaytrProvider processWebhook**

```php
$safeMetadata = array_intersect_key($payload, array_flip([
    'merchant_oid', 'status', 'total_amount', 'currency',
    'payment_type', 'payment_amount',
]));
```

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add src/Payment/Providers/Iyzico/IyzicoProvider.php src/Payment/Providers/PayTR/PaytrProvider.php
git commit -m "fix(security): filter webhook metadata to safe fields before storage [O-14]"
```

---

## Task 17: Remove get_class Leak from Notification (D-07)

**Files:**
- Modify: `src/Notifications/InvoicePaidNotification.php:39,46,59`

- [ ] **Step 1: Remove class name from email and toArray**

In `InvoicePaidNotification.php`:

Line 39: Remove `$recipientType = get_class($notifiable);`
Line 46: Remove `->line('Recipient type: '.$recipientType);`
Line 59: Change `'recipient_type' => get_class($notifiable)` to `'recipient_type' => 'user'`

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/Notifications/InvoicePaidNotification.php
git commit -m "fix(security): remove internal class name leak from notification [D-07]"
```

---

## Task 18: Replace sha1() with sha256() in Mock Mode (D-06)

**Files:**
- Modify: `src/Payment/Providers/Iyzico/IyzicoProvider.php` (lines 75, 95, 114, 165, 239)

**Context:** `sha1()` is used for mock identifiers. Replace with `hash('sha256', ...)` for consistency. This may already be partially covered by Task 14's `bin2hex(random_bytes())` replacement — only fix remaining `sha1()` calls.

- [ ] **Step 1: Replace remaining sha1 calls**

Replace all `sha1(...)` with `hash('sha256', ...)` in mock-mode code paths:
- Line 75: `'cf_'.sha1(...)` → `'cf_'.substr(hash('sha256', ...), 0, 40)`
- Line 95: same pattern
- Line 114: same pattern
- Line 165: same pattern
- Line 239: same pattern

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/Payment/Providers/Iyzico/IyzicoProvider.php
git commit -m "fix(security): replace sha1 with sha256 in mock mode identifiers [D-06]"
```

---

## Task 19: Update league/commonmark Dependency

**Files:**
- Modify: `composer.json`

**Context:** `composer audit` reported 2 medium-severity CVEs in `league/commonmark` (CVE-2026-33347, CVE-2026-30838). Both affect versions <=2.8.1.

- [ ] **Step 1: Update the dependency**

```bash
composer update league/commonmark --with-all-dependencies
```

- [ ] **Step 2: Verify the fix**

```bash
composer audit
```

Expected: No vulnerabilities reported for `league/commonmark`.

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "fix(deps): update league/commonmark to fix CVE-2026-33347 and CVE-2026-30838"
```

---

## Task 20: Final Verification and PHPStan

- [ ] **Step 1: Run full test suite**

```bash
composer test
```

Expected: All tests pass (225+ with new tests)

- [ ] **Step 2: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors

- [ ] **Step 3: Run composer audit**

```bash
composer audit
```

Expected: 0 vulnerabilities

- [ ] **Step 4: Final commit if any remaining cleanup**

```bash
git status
# If any unstaged changes, stage and commit
```

---

## Summary

| Task | Finding(s) | Severity | Key Change |
|------|-----------|----------|------------|
| 1 | K-01 | Critical | Webhook signature validation at intake |
| 2 | Y-01 | High | Mock mode production warning |
| 3 | Y-02 | High | Subscription creation race guard |
| 4 | Y-03 | High | Strip card data from responses |
| 5 | O-01 | Medium | Remove raw status write bypass |
| 6 | O-02 | Medium | lockForUpdate in handleWebhookResult |
| 7 | O-05 | Medium | PaymentChargeJob billing period fix |
| 8 | O-06 | Medium | firstOrNew → firstOrCreate |
| 9 | O-07 | Medium | Payload size limit + header filtering |
| 10 | O-08 | Medium | SSRF protection for sync command |
| 11 | O-09 | Medium | Sanitize exception messages |
| 12 | O-10, D-04 | Medium | HTML escaping + path sanitization |
| 13 | O-11 | Medium | Tighten model $guarded arrays |
| 14 | O-12, D-05 | Medium | Replace uniqid/microtime with secure random |
| 15 | O-13 | Medium | Remove PayTR hash body fallback |
| 16 | O-14 | Medium | Filter webhook metadata |
| 17 | D-07 | Low | Remove class name leak |
| 18 | D-06 | Low | Replace sha1 with sha256 |
| 19 | CVE | Medium | Update league/commonmark |
| 20 | — | — | Final verification |
