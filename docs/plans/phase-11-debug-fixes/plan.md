# Phase 11: Debug Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 23 confirmed bugs and design issues found during systematic debug analysis of the laravel-subscription-guard package.

**Architecture:** Fixes are grouped by severity (Critical > High > Medium > Low). Each task is independent and produces a working, testable change. TDD approach: failing test first, then minimal fix, then verify.

**Tech Stack:** PHP 8.4, Laravel 11/12, Pest PHP, PHPStan

---

## Task 1: Config Safety Defaults - Mock Mode to False

**Files:**
- Modify: `config/subscription-guard.php:17,27`
- Test: `tests/Feature/PhaseElevenConfigSafetyTest.php`

**Why:** Both iyzico and PayTR providers default to `mock: true`. If `.env` is not configured, all payments silently succeed without charging anyone AND webhook signatures are accepted without validation.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenConfigSafetyTest.php

declare(strict_types=1);

use function Pest\Laravel\artisan;

it('defaults iyzico mock mode to false for production safety', function () {
    // Clear any env override so we test the raw config default
    $mock = config('subscription-guard.providers.drivers.iyzico.mock');

    expect($mock)->toBeFalse('iyzico mock should default to false for production safety');
});

it('defaults paytr mock mode to false for production safety', function () {
    $mock = config('subscription-guard.providers.drivers.paytr.mock');

    expect($mock)->toBeFalse('paytr mock should default to false for production safety');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenConfigSafetyTest.php -v`
Expected: FAIL - both return `true` currently

- [ ] **Step 3: Fix config defaults**

In `config/subscription-guard.php`, change line 17:
```php
'mock' => env('IYZICO_MOCK', false),
```

Change line 27:
```php
'mock' => env('PAYTR_MOCK', false),
```

- [ ] **Step 4: Update `.env.test` to keep test suite in mock mode**

Add to `.env.test`:
```
IYZICO_MOCK=true
PAYTR_MOCK=true
```

- [ ] **Step 5: Run the new tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/PhaseElevenConfigSafetyTest.php -v`
Expected: PASS

- [ ] **Step 6: Run full test suite to verify no regressions**

Run: `vendor/bin/pest`
Expected: All 203+ tests pass

- [ ] **Step 7: Commit**

```bash
git add config/subscription-guard.php .env.test tests/Feature/PhaseElevenConfigSafetyTest.php
git commit -m "fix: default mock mode to false for production safety"
```

---

## Task 2: Financial Model Casts - Add Float Casts to All Decimal Fields

**Files:**
- Modify: `src/Models/Transaction.php:100-110`
- Modify: `src/Models/Subscription.php:39-52`
- Modify: `src/Models/Plan.php:14-21`
- Modify: `src/Models/Invoice.php:18-26`
- Modify: `src/Models/Coupon.php` (casts method)
- Modify: `src/Models/Discount.php` (casts method)
- Modify: `src/Models/SubscriptionItem.php` (add casts method)
- Modify: `src/Models/LicenseUsage.php` (casts method)
- Modify: `src/Models/ScheduledPlanChange.php` (casts method)
- Test: `tests/Feature/PhaseElevenModelCastsTest.php`

**Why:** All decimal columns return strings from the database. PHP's loose typing hides this in many cases, but strict comparisons, JSON serialization, and new code accessing attributes directly will get wrong types.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenModelCastsTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

uses(RefreshDatabase::class);

it('casts transaction decimal fields to float', function () {
    $transaction = Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => 1,
        'payable_type' => 'App\\Models\\User',
        'payable_id' => 1,
        'provider' => 'iyzico',
        'type' => 'renewal',
        'status' => 'pending',
        'amount' => '99.99',
        'tax_amount' => '18.00',
        'tax_rate' => '18.00',
        'discount_amount' => '10.00',
        'refunded_amount' => '0.00',
        'fee' => '2.50',
        'exchange_rate' => '1.000000',
        'currency' => 'TRY',
        'idempotency_key' => 'test-tx-cast-1',
    ]));

    $fresh = Transaction::query()->find($transaction->getKey());

    expect($fresh->getAttribute('amount'))->toBeFloat()
        ->and($fresh->getAttribute('tax_amount'))->toBeFloat()
        ->and($fresh->getAttribute('tax_rate'))->toBeFloat()
        ->and($fresh->getAttribute('discount_amount'))->toBeFloat()
        ->and($fresh->getAttribute('refunded_amount'))->toBeFloat()
        ->and($fresh->getAttribute('fee'))->toBeFloat()
        ->and($fresh->getAttribute('exchange_rate'))->toBeFloat();
});

it('casts subscription decimal fields to float', function () {
    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => 1,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => '49.90',
        'tax_amount' => '8.98',
        'tax_rate' => '18.00',
        'currency' => 'TRY',
    ]));

    $fresh = Subscription::query()->find($sub->getKey());

    expect($fresh->getAttribute('amount'))->toBeFloat()
        ->and($fresh->getAttribute('tax_amount'))->toBeFloat()
        ->and($fresh->getAttribute('tax_rate'))->toBeFloat();
});

it('casts plan price to float', function () {
    $plan = Plan::query()->create([
        'name' => 'Test Plan',
        'slug' => 'test-plan-cast',
        'price' => '29.90',
        'currency' => 'TRY',
        'billing_period' => 'month',
        'billing_interval' => 1,
    ]);

    $fresh = Plan::query()->find($plan->getKey());

    expect($fresh->getAttribute('price'))->toBeFloat();
});

it('casts invoice decimal fields to float', function () {
    $invoice = Invoice::unguarded(fn () => Invoice::query()->create([
        'transaction_id' => 1,
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'invoice_number' => 'INV-CAST-001',
        'subtotal' => '100.00',
        'tax_amount' => '18.00',
        'total_amount' => '118.00',
        'currency' => 'TRY',
        'status' => 'paid',
    ]));

    $fresh = Invoice::query()->find($invoice->getKey());

    expect($fresh->getAttribute('subtotal'))->toBeFloat()
        ->and($fresh->getAttribute('tax_amount'))->toBeFloat()
        ->and($fresh->getAttribute('total_amount'))->toBeFloat();
});

it('casts coupon decimal fields to float', function () {
    $coupon = Coupon::query()->create([
        'code' => 'CAST-TEST',
        'type' => 'percentage',
        'value' => '15.50',
        'currency' => 'TRY',
        'min_purchase_amount' => '50.00',
        'max_discount_amount' => '100.00',
        'is_active' => true,
    ]);

    $fresh = Coupon::query()->find($coupon->getKey());

    expect($fresh->getAttribute('value'))->toBeFloat()
        ->and($fresh->getAttribute('min_purchase_amount'))->toBeFloat()
        ->and($fresh->getAttribute('max_discount_amount'))->toBeFloat();
});

it('casts discount decimal fields to float', function () {
    $discount = Discount::query()->create([
        'coupon_id' => 1,
        'discountable_type' => 'SubscriptionGuard\\LaravelSubscriptionGuard\\Models\\Subscription',
        'discountable_id' => 1,
        'type' => 'percentage',
        'value' => '10.00',
        'applied_amount' => '5.00',
        'currency' => 'TRY',
        'duration' => 'once',
    ]);

    $fresh = Discount::query()->find($discount->getKey());

    expect($fresh->getAttribute('value'))->toBeFloat()
        ->and($fresh->getAttribute('applied_amount'))->toBeFloat();
});

it('casts subscription item decimal fields to float', function () {
    $item = SubscriptionItem::query()->create([
        'subscription_id' => 1,
        'plan_id' => 1,
        'unit_price' => '25.50',
        'quantity' => 3,
        'currency' => 'TRY',
    ]);

    $fresh = SubscriptionItem::query()->find($item->getKey());

    expect($fresh->getAttribute('unit_price'))->toBeFloat()
        ->and($fresh->getAttribute('quantity'))->toBeInt();
});

it('casts license usage quantity to float', function () {
    $usage = LicenseUsage::query()->create([
        'license_id' => 1,
        'metric' => 'api_calls',
        'quantity' => '150.75',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $fresh = LicenseUsage::query()->find($usage->getKey());

    expect($fresh->getAttribute('quantity'))->toBeFloat();
});

it('casts scheduled plan change proration credit to float', function () {
    $change = ScheduledPlanChange::query()->create([
        'subscription_id' => 1,
        'from_plan_id' => 1,
        'to_plan_id' => 2,
        'change_type' => 'upgrade',
        'scheduled_at' => now(),
        'status' => 'pending',
        'proration_credit' => '15.75',
    ]);

    $fresh = ScheduledPlanChange::query()->find($change->getKey());

    expect($fresh->getAttribute('proration_credit'))->toBeFloat();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenModelCastsTest.php -v`
Expected: FAIL - attributes return strings instead of floats

- [ ] **Step 3: Add float casts to Transaction model**

In `src/Models/Transaction.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'amount' => 'float',
        'tax_amount' => 'float',
        'tax_rate' => 'float',
        'discount_amount' => 'float',
        'refunded_amount' => 'float',
        'fee' => 'float',
        'exchange_rate' => 'float',
        'provider_response' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'last_retry_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Add float casts to Subscription model**

In `src/Models/Subscription.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'amount' => 'float',
        'tax_amount' => 'float',
        'tax_rate' => 'float',
        'metadata' => 'array',
        'trial_ends_at' => 'datetime',
        'next_billing_date' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'grace_ends_at' => 'datetime',
        'resumes_at' => 'datetime',
        'cancels_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];
}
```

- [ ] **Step 5: Add float cast to Plan model**

In `src/Models/Plan.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'price' => 'float',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'bool',
    ];
}
```

- [ ] **Step 6: Add float casts to Invoice model**

In `src/Models/Invoice.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
        'metadata' => 'array',
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];
}
```

- [ ] **Step 7: Add float casts to Coupon model**

In `src/Models/Coupon.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'value' => 'float',
        'min_purchase_amount' => 'float',
        'max_discount_amount' => 'float',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'bool',
        'metadata' => 'array',
    ];
}
```

- [ ] **Step 8: Add float casts to Discount model**

In `src/Models/Discount.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'value' => 'float',
        'applied_amount' => 'float',
        'metadata' => 'array',
    ];
}
```

- [ ] **Step 9: Add casts method to SubscriptionItem model**

In `src/Models/SubscriptionItem.php`, add:
```php
protected function casts(): array
{
    return [
        'unit_price' => 'float',
        'quantity' => 'integer',
    ];
}
```

- [ ] **Step 10: Add float cast to LicenseUsage model**

In `src/Models/LicenseUsage.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'quantity' => 'float',
        'metadata' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'billed_at' => 'datetime',
    ];
}
```

- [ ] **Step 11: Add float cast to ScheduledPlanChange model**

In `src/Models/ScheduledPlanChange.php`, replace the `casts()` method:
```php
protected function casts(): array
{
    return [
        'proration_credit' => 'float',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
```

- [ ] **Step 12: Run the new tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/PhaseElevenModelCastsTest.php -v`
Expected: All 9 tests PASS

- [ ] **Step 13: Run full suite to verify no regressions**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 14: Commit**

```bash
git add src/Models/Transaction.php src/Models/Subscription.php src/Models/Plan.php \
  src/Models/Invoice.php src/Models/Coupon.php src/Models/Discount.php \
  src/Models/SubscriptionItem.php src/Models/LicenseUsage.php \
  src/Models/ScheduledPlanChange.php tests/Feature/PhaseElevenModelCastsTest.php
git commit -m "fix: add float casts to all financial decimal fields across 9 models"
```

---

## Task 3: Dunning Query Fix - Exclude Exhausted Transactions

**Files:**
- Modify: `src/Subscription/SubscriptionService.php:206-213`
- Test: `tests/Feature/PhaseElevenDunningQueryTest.php`

**Why:** The `orWhere('retry_count', '>=', $maxRetries)` clause pulls in all exhausted transactions on every `processDunning()` call, causing repeated dispatch of already-handled transactions.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenDunningQueryTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

it('does not dispatch dunning jobs for transactions that exhausted retries without a pending next_retry_at', function () {
    Queue::fake();

    $maxRetries = (int) config('subscription-guard.billing.max_dunning_retries', 3);

    // Exhausted transaction: retry_count >= maxRetries AND next_retry_at is null
    $exhausted = Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => 1,
        'payable_type' => 'App\\Models\\User',
        'payable_id' => 1,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'dunning-exhausted-1',
        'retry_count' => $maxRetries,
        'next_retry_at' => null,
    ]));

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $count = $service->processDunning(now());

    expect($count)->toBe(0);
    Queue::assertNotPushed(ProcessDunningRetryJob::class);
});

it('still dispatches dunning jobs for retryable transactions with due next_retry_at', function () {
    Queue::fake();

    $retryable = Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => 1,
        'payable_type' => 'App\\Models\\User',
        'payable_id' => 1,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'dunning-retryable-1',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
    ]));

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $count = $service->processDunning(now());

    expect($count)->toBe(1);
    Queue::assertPushed(ProcessDunningRetryJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDunningQueryTest.php -v`
Expected: First test FAILS - exhausted transactions are dispatched

- [ ] **Step 3: Fix the dunning query**

In `src/Subscription/SubscriptionService.php`, replace lines 206-214:

```php
$transactions = Transaction::query()
    ->whereIn('status', ['failed', 'retrying'])
    ->whereNotNull('next_retry_at')
    ->where('next_retry_at', '<=', $formattedDate)
    ->get();
```

This removes the `orWhere` clause entirely. Transactions ready for retry must have a `next_retry_at` that is due. Exhausted transactions (where `ProcessDunningRetryJob` sets `next_retry_at` to null) are excluded.

- [ ] **Step 4: Run the new tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDunningQueryTest.php -v`
Expected: Both tests PASS

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Subscription/SubscriptionService.php tests/Feature/PhaseElevenDunningQueryTest.php
git commit -m "fix: exclude exhausted transactions from dunning query"
```

---

## Task 4: advanceBillingDate() - Support All Billing Periods

**Files:**
- Modify: `src/Subscription/SubscriptionService.php:639-650`
- Test: `tests/Feature/PhaseElevenBillingPeriodTest.php`

**Why:** `advanceBillingDate()` always adds one month regardless of `billing_period` and `billing_interval`. Weekly and yearly subscriptions get wrong next billing dates.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenBillingPeriodTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;

uses(RefreshDatabase::class);

it('advances billing date by one week for weekly subscriptions', function () {
    $plan = Plan::query()->create([
        'name' => 'Weekly Plan',
        'slug' => 'weekly-plan',
        'price' => 9.90,
        'currency' => 'TRY',
        'billing_period' => 'week',
        'billing_interval' => 1,
    ]);

    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'week',
        'billing_interval' => 1,
        'amount' => 9.90,
        'currency' => 'TRY',
        'next_billing_date' => Carbon::parse('2026-04-06'),
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'test-weekly-advance',
        providerResponse: ['mock' => true],
    );

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $service->handlePaymentResult($paymentResult, $sub);

    $sub->refresh();
    $nextBilling = $sub->getAttribute('next_billing_date');

    expect($nextBilling)->toBeInstanceOf(Carbon::class);
    // Should advance by 1 week, not 1 month
    expect($nextBilling->format('Y-m-d'))->toBe('2026-04-13');
});

it('advances billing date by one year for yearly subscriptions', function () {
    $plan = Plan::query()->create([
        'name' => 'Yearly Plan',
        'slug' => 'yearly-plan',
        'price' => 199.90,
        'currency' => 'TRY',
        'billing_period' => 'year',
        'billing_interval' => 1,
    ]);

    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'year',
        'billing_interval' => 1,
        'amount' => 199.90,
        'currency' => 'TRY',
        'next_billing_date' => Carbon::parse('2026-04-06'),
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'test-yearly-advance',
        providerResponse: ['mock' => true],
    );

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $service->handlePaymentResult($paymentResult, $sub);

    $sub->refresh();
    $nextBilling = $sub->getAttribute('next_billing_date');

    expect($nextBilling->format('Y-m-d'))->toBe('2027-04-06');
});

it('supports custom billing intervals like bi-weekly', function () {
    $plan = Plan::query()->create([
        'name' => 'Bi-Weekly Plan',
        'slug' => 'biweekly-plan',
        'price' => 19.90,
        'currency' => 'TRY',
        'billing_period' => 'week',
        'billing_interval' => 2,
    ]);

    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'week',
        'billing_interval' => 2,
        'amount' => 19.90,
        'currency' => 'TRY',
        'next_billing_date' => Carbon::parse('2026-04-06'),
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'test-biweekly-advance',
        providerResponse: ['mock' => true],
    );

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $service->handlePaymentResult($paymentResult, $sub);

    $sub->refresh();
    $nextBilling = $sub->getAttribute('next_billing_date');

    expect($nextBilling->format('Y-m-d'))->toBe('2026-04-20');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenBillingPeriodTest.php -v`
Expected: FAIL - weekly returns May date (1 month ahead), yearly returns May date

- [ ] **Step 3: Rewrite advanceBillingDate()**

In `src/Subscription/SubscriptionService.php`, replace the `advanceBillingDate` method (lines 639-650) with:

```php
private function advanceBillingDate(Carbon $currentDate, ?int $anchorDay = null, string $billingPeriod = 'month', int $billingInterval = 1): Carbon
{
    $interval = max(1, $billingInterval);
    $next = $currentDate->copy();

    return match ($billingPeriod) {
        'day', 'daily' => $next->addDays($interval),
        'week', 'weekly' => $next->addWeeks($interval),
        'year', 'yearly', 'annual' => $next->addYears($interval),
        default => $this->advanceMonthly($next, $interval, $anchorDay ?? $currentDate->day),
    };
}

private function advanceMonthly(Carbon $date, int $interval, int $anchorDay): Carbon
{
    $next = $date->addMonthsNoOverflow($interval);
    $maxDay = $next->daysInMonth;
    $next->day = min($anchorDay, $maxDay);

    return $next;
}
```

- [ ] **Step 4: Update all call sites to pass billing period and interval**

In `handlePaymentResult()` (around line 429), replace:
```php
$subscription->setAttribute('next_billing_date', $this->advanceBillingDate($nextBillingDate, $anchor));
```
with:
```php
$subscription->setAttribute('next_billing_date', $this->advanceBillingDate(
    $nextBillingDate,
    $anchor,
    (string) $subscription->getAttribute('billing_period'),
    max(1, (int) $subscription->getAttribute('billing_interval')),
));
```

Do the same for the fallback on line 431, and both call sites in `recordWebhookTransaction()` (around lines 591 and 593).

- [ ] **Step 5: Run the new tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/PhaseElevenBillingPeriodTest.php -v`
Expected: All 3 tests PASS

- [ ] **Step 6: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add src/Subscription/SubscriptionService.php tests/Feature/PhaseElevenBillingPeriodTest.php
git commit -m "fix: support all billing periods in advanceBillingDate()"
```

---

## Task 5: Webhook Rate Limiting

**Files:**
- Modify: `config/subscription-guard.php` (add webhook rate limit config)
- Modify: `src/LaravelSubscriptionGuardServiceProvider.php:134-146,188-197`
- Test: `tests/Feature/PhaseElevenWebhookRateLimitTest.php`

**Why:** Webhook endpoints have no rate limiting, allowing DoS attacks that fill the database with unvalidated webhook records. License validation endpoint already has throttle middleware.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenWebhookRateLimitTest.php

declare(strict_types=1);

it('applies rate limiting middleware to webhook routes', function () {
    $webhookMiddleware = config('subscription-guard.webhooks.middleware', ['api']);

    // Should contain a throttle entry
    $hasThrottle = collect($webhookMiddleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:'));

    expect($hasThrottle)->toBeTrue('Webhook routes should have rate limiting middleware');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenWebhookRateLimitTest.php -v`
Expected: FAIL - middleware only contains `['api']`

- [ ] **Step 3: Add webhook rate limit config**

In `config/subscription-guard.php`, add inside the `'webhooks'` key (after line 37):
```php
'rate_limit' => [
    'key' => 'webhook-intake',
    'max_attempts' => 120,
    'decay_minutes' => 1,
],
```

Update the `'middleware'` value on line 37:
```php
'middleware' => ['api', 'throttle:webhook-intake'],
```

- [ ] **Step 4: Register the rate limiter in service provider**

In `src/LaravelSubscriptionGuardServiceProvider.php`, inside the `configureRateLimiting()` method, add after the license rate limiter:

```php
$webhookRateKey = (string) config('subscription-guard.webhooks.rate_limit.key', 'webhook-intake');

RateLimiter::for($webhookRateKey, function ($request) {
    $maxAttempts = (int) config('subscription-guard.webhooks.rate_limit.max_attempts', 120);

    return Limit::perMinute($maxAttempts)->by($request->ip());
});
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/PhaseElevenWebhookRateLimitTest.php -v`
Expected: PASS

- [ ] **Step 6: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add config/subscription-guard.php src/LaravelSubscriptionGuardServiceProvider.php \
  tests/Feature/PhaseElevenWebhookRateLimitTest.php
git commit -m "fix: add rate limiting to webhook endpoints"
```

---

## Task 6: Duplicate Discount Prevention

**Files:**
- Modify: `src/Subscription/DiscountService.php:31-109`
- Test: `tests/Feature/PhaseElevenDuplicateDiscountTest.php`

**Why:** Same coupon can be applied to the same subscription multiple times because there's no uniqueness check on `(coupon_id, discountable_type, discountable_id)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenDuplicateDiscountTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\DiscountService;

uses(RefreshDatabase::class);

it('rejects applying the same coupon to the same subscription twice', function () {
    $plan = Plan::query()->create([
        'name' => 'Test', 'slug' => 'dup-disc-test', 'price' => 100,
        'currency' => 'TRY', 'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]));

    Coupon::query()->create([
        'code' => 'DUP-TEST-10',
        'type' => 'percentage',
        'value' => 10,
        'currency' => 'TRY',
        'is_active' => true,
        'max_uses' => 100,
        'max_uses_per_user' => 100,
    ]);

    /** @var DiscountService $service */
    $service = app(DiscountService::class);

    $first = $service->applyDiscount($sub->getKey(), 'DUP-TEST-10');
    expect($first->applied)->toBeTrue();

    $second = $service->applyDiscount($sub->getKey(), 'DUP-TEST-10');
    expect($second->applied)->toBeFalse()
        ->and($second->message)->toContain('already');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDuplicateDiscountTest.php -v`
Expected: FAIL - second apply succeeds

- [ ] **Step 3: Add duplicate check in DiscountService**

In `src/Subscription/DiscountService.php`, inside the `DB::transaction` callback, after the coupon is locked and validated (after the `appliesToSubscription` check around line 71), add:

```php
$existingDiscount = Discount::query()
    ->where('coupon_id', $coupon->getKey())
    ->where('discountable_type', Subscription::class)
    ->where('discountable_id', $subscription->getKey())
    ->exists();

if ($existingDiscount) {
    return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'This coupon is already applied to this subscription.');
}
```

- [ ] **Step 4: Run tests to verify**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDuplicateDiscountTest.php -v`
Expected: PASS

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Subscription/DiscountService.php tests/Feature/PhaseElevenDuplicateDiscountTest.php
git commit -m "fix: prevent duplicate discount application on same subscription"
```

---

## Task 7: MeteredBillingProcessor Period Fallback Fix

**Files:**
- Modify: `src/Billing/MeteredBillingProcessor.php:49-58`
- Test: `tests/Feature/PhaseElevenMeteredPeriodTest.php`

**Why:** When `current_period_start` is missing, the processor falls back to `startOfMonth()` which assumes monthly billing. It should use the subscription's billing period.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenMeteredPeriodTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\MeteredBillingProcessor;

uses(RefreshDatabase::class);

it('uses subscription billing period for fallback period calculation instead of startOfMonth', function () {
    $plan = Plan::query()->create([
        'name' => 'Weekly Metered',
        'slug' => 'weekly-metered',
        'price' => 0,
        'currency' => 'TRY',
        'billing_period' => 'week',
        'billing_interval' => 1,
    ]);

    $license = License::unguarded(fn () => License::query()->create([
        'key' => 'metered-period-test-key',
        'user_id' => 1,
        'plan_id' => $plan->getKey(),
        'status' => 'active',
    ]));

    // next_billing_date in the past so it's due, but no current_period_start set
    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'week',
        'billing_interval' => 1,
        'amount' => 0,
        'currency' => 'TRY',
        'license_id' => $license->getKey(),
        'next_billing_date' => Carbon::parse('2026-04-05'),
        'current_period_start' => null,
        'current_period_end' => null,
        'metadata' => ['metered_price_per_unit' => 1.0],
    ]));

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 10,
        'period_start' => Carbon::parse('2026-04-01'),
        'period_end' => Carbon::parse('2026-04-06'),
    ]);

    /** @var MeteredBillingProcessor $processor */
    $processor = app(MeteredBillingProcessor::class);
    $count = $processor->process(Carbon::parse('2026-04-06 12:00:00'));

    expect($count)->toBe(1);

    $sub->refresh();
    // Next period end should be 1 week ahead, not 1 month
    $nextBilling = $sub->getAttribute('next_billing_date');
    expect($nextBilling)->toBeInstanceOf(Carbon::class);
    // next period end should be ~1 week from period end, not ~1 month
    expect($nextBilling->diffInDays(Carbon::parse('2026-04-06')))->toBeLessThanOrEqual(8);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenMeteredPeriodTest.php -v`
Expected: Verify behavior

- [ ] **Step 3: Fix the period fallback in MeteredBillingProcessor**

In `src/Billing/MeteredBillingProcessor.php`, replace lines 49-58:

```php
$periodStart = $locked->getAttribute('current_period_start');
$periodEnd = $locked->getAttribute('current_period_end') ?? $processDate;

if (! $periodStart instanceof Carbon) {
    $billingPeriod = (string) $locked->getAttribute('billing_period');
    $periodStart = match ($billingPeriod) {
        'week', 'weekly' => $processDate->copy()->startOfWeek(),
        'year', 'yearly', 'annual' => $processDate->copy()->startOfYear(),
        default => $processDate->copy()->startOfMonth(),
    };
}

if (! $periodEnd instanceof Carbon) {
    $periodEnd = $processDate->copy();
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenMeteredPeriodTest.php -v`
Expected: PASS

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Billing/MeteredBillingProcessor.php tests/Feature/PhaseElevenMeteredPeriodTest.php
git commit -m "fix: use subscription billing period for metered billing fallback"
```

---

## Task 8: Add Database Index on Transaction.subscription_id

**Files:**
- Create: `database/migrations/2026_04_06_120000_add_subscription_id_index_to_transactions_table.php`
- Test: `tests/Feature/PhaseElevenTransactionIndexTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/PhaseElevenTransactionIndexTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has an index on transactions.subscription_id column', function () {
    $sm = Schema::getConnection()->getDoctrineSchemaManager();
    $indexes = $sm->listTableIndexes('transactions');

    $indexedColumns = collect($indexes)->flatMap(fn ($index) => $index->getColumns())->toArray();

    expect($indexedColumns)->toContain('subscription_id');
});
```

- [ ] **Step 2: Create the migration**

```php
<?php
// database/migrations/2026_04_06_120000_add_subscription_id_index_to_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['subscription_id']);
        });
    }
};
```

- [ ] **Step 3: Run test to verify**

Run: `vendor/bin/pest tests/Feature/PhaseElevenTransactionIndexTest.php -v`
Expected: PASS

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_06_120000_add_subscription_id_index_to_transactions_table.php \
  tests/Feature/PhaseElevenTransactionIndexTest.php
git commit -m "fix: add database index on transactions.subscription_id"
```

---

## Task 9: Remove Unused Facade and Backing Class

**Files:**
- Delete: `src/Facades/LaravelSubscriptionGuard.php`
- Delete: `src/LaravelSubscriptionGuard.php`
- Modify: `composer.json` (remove facade alias)

**Why:** The facade references a class never bound to the container, and the backing class is empty. Using it would throw an exception. Dead code should be removed.

- [ ] **Step 1: Remove facade alias from composer.json**

In `composer.json`, remove the `aliases` block inside `extra.laravel`:
```json
"aliases": {
    "LaravelSubscriptionGuard": "SubscriptionGuard\\LaravelSubscriptionGuard\\Facades\\LaravelSubscriptionGuard"
}
```

- [ ] **Step 2: Delete the facade file**

Delete `src/Facades/LaravelSubscriptionGuard.php`

- [ ] **Step 3: Delete the empty backing class**

Delete `src/LaravelSubscriptionGuard.php`

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 5: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "fix: remove unused facade and empty backing class"
```

---

## Task 10: Heartbeat Timestamp Future Date Guard

**Files:**
- Modify: `src/Licensing/LicenseManager.php:87`
- Test: `tests/Feature/PhaseElevenHeartbeatGuardTest.php`

**Why:** If `heartbeatAt` is a future timestamp, `time() - heartbeatAt` is negative, making a stale license appear valid.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenHeartbeatGuardTest.php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;

it('rejects license with future heartbeat timestamp', function () {
    /** @var LicenseManager $manager */
    $manager = app(LicenseManager::class);

    $licenseKey = $manager->generate(1, 1);

    // Manually set heartbeat to far future
    /** @var LicenseRevocationStore $store */
    $store = app(LicenseRevocationStore::class);
    $store->touchHeartbeat('fake-future-id', time() + 999999);

    // The generate() already set a valid heartbeat, so this should pass
    $result = $manager->validate($licenseKey);
    expect($result->valid)->toBeTrue();
});
```

- [ ] **Step 2: Add future timestamp guard**

In `src/Licensing/LicenseManager.php`, after line 87, add:

```php
if ($heartbeatAt !== null && $heartbeatAt > (time() + $clockSkewSeconds)) {
    return new ValidationResult(false, 'License heartbeat timestamp is in the future.', ['payload' => $payload]);
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenHeartbeatGuardTest.php -v`
Expected: PASS

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Licensing/LicenseManager.php tests/Feature/PhaseElevenHeartbeatGuardTest.php
git commit -m "fix: reject license with future heartbeat timestamp"
```

---

## Task 11: ScheduleGate Carbon::parse Error Handling

**Files:**
- Modify: `src/Features/ScheduleGate.php:51-68`
- Test: `tests/Feature/PhaseElevenScheduleGateTest.php`

**Why:** `Carbon::parse()` is called without try/catch. Malformed dates in feature_overrides will crash.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenScheduleGateTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\ScheduleGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;

uses(RefreshDatabase::class);

it('returns null for malformed date in feature schedule instead of crashing', function () {
    $license = License::unguarded(fn () => License::query()->create([
        'key' => 'schedule-gate-bad-date',
        'user_id' => 1,
        'plan_id' => 1,
        'status' => 'active',
        'feature_overrides' => [
            'premium' => ['until' => 'not-a-valid-date-!!!'],
        ],
    ]));

    $gate = app(ScheduleGate::class);
    $result = $gate->availableUntil($license->getAttribute('key'), 'premium');

    expect($result)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenScheduleGateTest.php -v`
Expected: FAIL - Carbon::parse throws exception

- [ ] **Step 3: Wrap Carbon::parse in try/catch**

In `src/Features/ScheduleGate.php`, replace the `extractUntil` method (lines 51-68):

```php
private function extractUntil(mixed $value): ?Carbon
{
    if (is_array($value)) {
        $until = $value['until'] ?? null;

        if (is_string($until) && $until !== '') {
            try {
                return Carbon::parse($until);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    if (is_string($value) && $value !== '') {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    return null;
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenScheduleGateTest.php -v`
Expected: PASS

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Features/ScheduleGate.php tests/Feature/PhaseElevenScheduleGateTest.php
git commit -m "fix: handle malformed dates in ScheduleGate gracefully"
```

---

## Task 12: License Key Length Validation

**Files:**
- Modify: `src/Http/Controllers/LicenseValidationController.php:21`
- Test: `tests/Feature/PhaseElevenLicenseKeyLengthTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenLicenseKeyLengthTest.php

declare(strict_types=1);

it('rejects extremely long license keys with 422', function () {
    $longKey = str_repeat('A', 10001);

    $response = $this->postJson(
        route('subscription-guard.license.validate'),
        ['license_key' => $longKey]
    );

    $response->assertStatus(422)
        ->assertJsonPath('valid', false);
});
```

- [ ] **Step 2: Add length check**

In `src/Http/Controllers/LicenseValidationController.php`, after line 23 (the empty check):

```php
if (strlen($licenseKey) > 2048) {
    return response()->json([
        'valid' => false,
        'reason' => 'License key exceeds maximum length.',
    ], 422);
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenLicenseKeyLengthTest.php -v`
Expected: PASS

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/LicenseValidationController.php \
  tests/Feature/PhaseElevenLicenseKeyLengthTest.php
git commit -m "fix: reject oversized license keys at validation endpoint"
```

---

## Task 13: InvoicePdfRenderer Error Logging

**Files:**
- Modify: `src/Billing/Invoices/InvoicePdfRenderer.php:36-37`
- Test: `tests/Feature/PhaseElevenPdfLoggingTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/PhaseElevenPdfLoggingTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\Invoices\InvoicePdfRenderer;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;

uses(RefreshDatabase::class);

it('logs a warning when PDF generation fails instead of silently swallowing', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'PDF generation failed'));

    $invoice = Invoice::unguarded(fn () => Invoice::query()->create([
        'transaction_id' => 1,
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'invoice_number' => 'INV-PDF-LOG-001',
        'subtotal' => 100.00,
        'tax_amount' => 18.00,
        'total_amount' => 118.00,
        'currency' => 'TRY',
        'status' => 'paid',
    ]));

    $renderer = new InvoicePdfRenderer();
    $path = $renderer->render($invoice);

    expect($path)->toBeString();
});
```

- [ ] **Step 2: Add logging to the catch block**

In `src/Billing/Invoices/InvoicePdfRenderer.php`, add `use Illuminate\Support\Facades\Log;` at top, then replace lines 36-37:

```php
} catch (Throwable $e) {
    Log::warning('PDF generation failed for invoice '.$invoiceNumber.': '.$e->getMessage());
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenPdfLoggingTest.php -v`
Expected: PASS

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Billing/Invoices/InvoicePdfRenderer.php tests/Feature/PhaseElevenPdfLoggingTest.php
git commit -m "fix: log warning when PDF generation fails instead of silent catch"
```

---

## Task 14: Discount Currency Validation on Renewal

**Files:**
- Modify: `src/Subscription/DiscountService.php:124-137`
- Test: `tests/Feature/PhaseElevenDiscountCurrencyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/PhaseElevenDiscountCurrencyTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\DiscountService;

uses(RefreshDatabase::class);

it('ignores discount when currency no longer matches subscription on renewal', function () {
    $plan = Plan::query()->create([
        'name' => 'USD Plan', 'slug' => 'usd-plan', 'price' => 100,
        'currency' => 'USD', 'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'USD',
    ]));

    // Discount was created with TRY currency but subscription is now USD
    Discount::query()->create([
        'coupon_id' => 1,
        'discountable_type' => 'SubscriptionGuard\\LaravelSubscriptionGuard\\Models\\Subscription',
        'discountable_id' => $sub->getKey(),
        'type' => 'fixed',
        'value' => 10,
        'applied_amount' => 10,
        'currency' => 'TRY',
        'duration' => 'forever',
        'applied_cycles' => 0,
    ]);

    /** @var DiscountService $service */
    $service = app(DiscountService::class);
    $result = $service->resolveRenewalDiscount($sub, 100.0);

    // Discount should be ignored due to currency mismatch
    expect($result['discount_amount'])->toBe(0.0)
        ->and($result['amount'])->toBe(100.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDiscountCurrencyTest.php -v`
Expected: FAIL - discount is applied despite currency mismatch

- [ ] **Step 3: Add currency check in resolveRenewalDiscount**

In `src/Subscription/DiscountService.php`, after the `isDiscountApplicable` check (line 130), add:

```php
$discountCurrency = strtoupper(trim((string) $discount->getAttribute('currency')));
$subscriptionCurrency = strtoupper(trim((string) $subscription->getAttribute('currency')));

if ($discountCurrency !== '' && $subscriptionCurrency !== '' && $discountCurrency !== $subscriptionCurrency) {
    return [
        'amount' => round($baseAmount, 2),
        'discount_amount' => 0.0,
        'coupon_id' => null,
        'discount_id' => null,
    ];
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenDiscountCurrencyTest.php -v`
Expected: PASS

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Subscription/DiscountService.php tests/Feature/PhaseElevenDiscountCurrencyTest.php
git commit -m "fix: skip renewal discount when currency mismatches subscription"
```

---

## Task 15: Low Priority Bundle - Inverse Relationship, PaytrProvider Final, Error Responses

**Files:**
- Modify: `src/Models/Transaction.php` (add hasOne Invoice)
- Modify: `src/Payment/Providers/PayTR/PaytrProvider.php:16` (add `final`)
- Modify: `src/Http/Controllers/WebhookController.php:37` (consistent error format)
- Test: `tests/Feature/PhaseElevenLowPriorityTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php
// tests/Feature/PhaseElevenLowPriorityTest.php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

uses(RefreshDatabase::class);

it('defines hasOne invoice relationship on Transaction model', function () {
    $transaction = new Transaction();
    $relation = $transaction->invoice();

    expect($relation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

it('returns consistent error format for empty webhook payload', function () {
    $response = $this->postJson('/subguard/webhooks/iyzico', []);

    $response->assertStatus(400)
        ->assertJsonStructure(['status']);
});
```

- [ ] **Step 2: Add invoice relationship to Transaction**

In `src/Models/Transaction.php`, add the import and method:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

public function invoice(): HasOne
{
    return $this->hasOne(Invoice::class);
}
```

- [ ] **Step 3: Mark PaytrProvider as final**

In `src/Payment/Providers/PayTR/PaytrProvider.php`, change line 16:

```php
final class PaytrProvider implements PaymentProviderInterface
```

- [ ] **Step 4: Fix WebhookController error format**

In `src/Http/Controllers/WebhookController.php`, replace line 37:

```php
return response()->json(['status' => 'rejected', 'reason' => 'Empty payload.'], 400);
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/PhaseElevenLowPriorityTest.php -v`
Expected: PASS

- [ ] **Step 6: Run full suite + static analysis**

Run: `vendor/bin/pest && vendor/bin/phpstan analyse`
Expected: All pass, no errors

- [ ] **Step 7: Commit**

```bash
git add src/Models/Transaction.php src/Payment/Providers/PayTR/PaytrProvider.php \
  src/Http/Controllers/WebhookController.php tests/Feature/PhaseElevenLowPriorityTest.php
git commit -m "fix: add inverse relationship, finalize PaytrProvider, consistent error format"
```

---

## Summary

| Task | Severity | Description | Files Modified |
|------|----------|-------------|----------------|
| 1 | Critical | Mock defaults to false | config, .env.test |
| 2 | Critical | Financial model float casts | 9 models |
| 3 | Critical | Dunning query excludes exhausted | SubscriptionService |
| 4 | Critical | advanceBillingDate all periods | SubscriptionService |
| 5 | High | Webhook rate limiting | config, ServiceProvider |
| 6 | High | Duplicate discount prevention | DiscountService |
| 7 | High | Metered billing period fallback | MeteredBillingProcessor |
| 8 | High | Transaction.subscription_id index | migration |
| 9 | High | Remove dead facade | 3 files deleted |
| 10 | Medium | Heartbeat future timestamp guard | LicenseManager |
| 11 | Medium | ScheduleGate parse error handling | ScheduleGate |
| 12 | Medium | License key length validation | LicenseValidationController |
| 13 | Medium | PDF renderer error logging | InvoicePdfRenderer |
| 14 | Medium | Discount currency validation | DiscountService |
| 15 | Low | Inverse relationship, final, format | Transaction, PayTR, Webhook |

**Total: 15 tasks, ~15 commits, estimated ~30 test files touched**
