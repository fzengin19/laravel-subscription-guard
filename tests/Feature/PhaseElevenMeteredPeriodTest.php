<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\MeteredBillingProcessor;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

beforeEach(function (): void {
    config()->set('subscription-guard.providers.drivers.dummy', [
        'class' => null,
        'manages_own_billing' => true,
        'webhook_response_format' => 'json',
    ]);
});

// ---------------------------------------------------------------------------
// Helper: create a DB user row and return its id
// ---------------------------------------------------------------------------
function phaseElevenMeteredCreateUser(string $email): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'Metered Period User',
        'email' => $email,
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ===========================================================================
// Weekly subscription — period fallback should use startOfWeek()
// ===========================================================================

it('uses startOfWeek() as period_start fallback for a weekly subscription with no current_period_start', function (): void {
    $userId = phaseElevenMeteredCreateUser('metered-weekly@example.test');

    $plan = Plan::query()->create([
        'name' => 'Weekly Metered Plan',
        'slug' => 'weekly-metered-plan-test',
        'price' => 0,
        'currency' => 'TRY',
        'billing_period' => 'weekly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.weekly-metered-'.bin2hex(random_bytes(8)),
        'status' => 'active',
    ]));

    // next_billing_date is in the past; current_period_start is deliberately null
    // to trigger the fallback path in MeteredBillingProcessor.
    $processDate = Carbon::parse('2026-04-06 12:00:00', 'UTC');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'license_id' => $license->getKey(),
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'weekly',
        'billing_interval' => 1,
        'amount' => 0.00,
        'currency' => 'TRY',
        'next_billing_date' => $processDate->copy()->subHour(),
        'current_period_start' => null,   // force the fallback
        'current_period_end' => null,
        'metadata' => ['metered_price_per_unit' => 1.0],
    ]));

    // Create an unbilled usage record whose period_start sits within the
    // startOfWeek() … processDate window so it gets picked up.
    $weekStart = $processDate->copy()->startOfWeek();

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 5,
        'period_start' => $weekStart,
        'period_end' => $processDate->copy(),
        'billed_at' => null,
    ]);

    $processor = app(MeteredBillingProcessor::class);
    $count = $processor->process($processDate);

    expect($count)->toBe(1);

    $subscription->refresh();

    // After processing, next_billing_date should advance by 1 week (~7 days),
    // not by 1 month (~30 days).
    $nextBillingDate = $subscription->getAttribute('next_billing_date');
    expect($nextBillingDate)->toBeInstanceOf(Carbon::class);

    $daysDiff = (int) $processDate->diffInDays($nextBillingDate);
    expect($daysDiff)->toBeGreaterThanOrEqual(6);
    expect($daysDiff)->toBeLessThanOrEqual(8);
});

// ===========================================================================
// Monthly subscription — period fallback should still use startOfMonth()
// ===========================================================================

it('uses startOfMonth() as period_start fallback for a monthly subscription with no current_period_start', function (): void {
    $userId = phaseElevenMeteredCreateUser('metered-monthly@example.test');

    $plan = Plan::query()->create([
        'name' => 'Monthly Metered Plan',
        'slug' => 'monthly-metered-plan-test',
        'price' => 0,
        'currency' => 'TRY',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.monthly-metered-'.bin2hex(random_bytes(8)),
        'status' => 'active',
    ]));

    $processDate = Carbon::parse('2026-04-06 12:00:00', 'UTC');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'license_id' => $license->getKey(),
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 0.00,
        'currency' => 'TRY',
        'next_billing_date' => $processDate->copy()->subHour(),
        'current_period_start' => null,   // force the fallback
        'current_period_end' => null,
        'metadata' => ['metered_price_per_unit' => 1.0],
    ]));

    $monthStart = $processDate->copy()->startOfMonth();

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 10,
        'period_start' => $monthStart,
        'period_end' => $processDate->copy(),
        'billed_at' => null,
    ]);

    $processor = app(MeteredBillingProcessor::class);
    $count = $processor->process($processDate);

    expect($count)->toBe(1);

    $subscription->refresh();

    // next_billing_date should advance by ~1 month (28-31 days).
    $nextBillingDate = $subscription->getAttribute('next_billing_date');
    expect($nextBillingDate)->toBeInstanceOf(Carbon::class);

    $daysDiff = (int) $processDate->diffInDays($nextBillingDate);
    expect($daysDiff)->toBeGreaterThanOrEqual(28);
    expect($daysDiff)->toBeLessThanOrEqual(31);
});

// ===========================================================================
// Yearly subscription — period fallback should use startOfYear()
// ===========================================================================

it('uses startOfYear() as period_start fallback for a yearly subscription with no current_period_start', function (): void {
    $userId = phaseElevenMeteredCreateUser('metered-yearly@example.test');

    $plan = Plan::query()->create([
        'name' => 'Yearly Metered Plan',
        'slug' => 'yearly-metered-plan-test',
        'price' => 0,
        'currency' => 'TRY',
        'billing_period' => 'yearly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.yearly-metered-'.bin2hex(random_bytes(8)),
        'status' => 'active',
    ]));

    $processDate = Carbon::parse('2026-04-06 12:00:00', 'UTC');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'license_id' => $license->getKey(),
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'yearly',
        'billing_interval' => 1,
        'amount' => 0.00,
        'currency' => 'TRY',
        'next_billing_date' => $processDate->copy()->subHour(),
        'current_period_start' => null,   // force the fallback
        'current_period_end' => null,
        'metadata' => ['metered_price_per_unit' => 1.0],
    ]));

    $yearStart = $processDate->copy()->startOfYear();

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 50,
        'period_start' => $yearStart,
        'period_end' => $processDate->copy(),
        'billed_at' => null,
    ]);

    $processor = app(MeteredBillingProcessor::class);
    $count = $processor->process($processDate);

    expect($count)->toBe(1);

    $subscription->refresh();

    // next_billing_date should advance by ~1 year (365-366 days).
    $nextBillingDate = $subscription->getAttribute('next_billing_date');
    expect($nextBillingDate)->toBeInstanceOf(Carbon::class);

    $daysDiff = (int) $processDate->diffInDays($nextBillingDate);
    expect($daysDiff)->toBeGreaterThanOrEqual(364);
    expect($daysDiff)->toBeLessThanOrEqual(367);
});
