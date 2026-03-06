<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

function phaseSevenCreateScheduledPlanChange(array $attributes = []): ScheduledPlanChange
{
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase7 Scheduled Change User',
        'email' => sprintf('phase7-scheduled-change-%s@example.test', str_replace('.', '-', uniqid('', true))),
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fromPlan = Plan::query()->create([
        'name' => sprintf('Phase7 From Plan %s', uniqid()),
        'slug' => sprintf('phase7-from-%s', str_replace('.', '-', uniqid('', true))),
        'price' => 99,
        'currency' => 'TRY',
    ]);

    $toPlan = Plan::query()->create([
        'name' => sprintf('Phase7 To Plan %s', uniqid()),
        'slug' => sprintf('phase7-to-%s', str_replace('.', '-', uniqid('', true))),
        'price' => 199,
        'currency' => 'TRY',
    ]);

    $subscription = Subscription::unguarded(static fn (): Subscription => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $fromPlan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 99,
        'currency' => 'TRY',
    ]));

    return ScheduledPlanChange::query()->create(array_merge([
        'subscription_id' => $subscription->getKey(),
        'from_plan_id' => $fromPlan->getKey(),
        'to_plan_id' => $toPlan->getKey(),
        'scheduled_at' => now()->addDay(),
    ], $attributes));
}

it('marks a scheduled plan change as failed', function (): void {
    $change = phaseSevenCreateScheduledPlanChange();

    $change->markFailed('Subscription not found.');

    expect($change->fresh()?->getAttribute('status'))->toBe('failed')
        ->and($change->fresh()?->getAttribute('error_message'))->toBe('Subscription not found.')
        ->and($change->fresh()?->getAttribute('processed_at'))->not->toBeNull();
});

it('marks a scheduled plan change as processed and clears any old error', function (): void {
    $change = phaseSevenCreateScheduledPlanChange([
        'status' => 'failed',
        'error_message' => 'Old failure',
    ]);

    $change->markProcessed();

    expect($change->fresh()?->getAttribute('status'))->toBe('processed')
        ->and($change->fresh()?->getAttribute('error_message'))->toBeNull()
        ->and($change->fresh()?->getAttribute('processed_at'))->not->toBeNull();
});
