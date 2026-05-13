<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
// Helpers (suffixed -Cascade to avoid collisions with other phase helpers)
// -----------------------------------------------------------------------------

function makeUserCascade(string $email = 'cascade@example.test'): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'Cascade',
        'email' => $email,
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makePlanCascade(array $overrides = []): Plan
{
    return Plan::query()->forceCreate(array_merge([
        'name' => 'Cascade Plan',
        'slug' => 'cascade-'.uniqid(),
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ], $overrides));
}

// -----------------------------------------------------------------------------
// Task 1: subscriptions.plan_id is RESTRICT — plan hard-delete blocked
// -----------------------------------------------------------------------------

it('Task 1 — DB refuses to hard-delete a plan that has a subscription', function (): void {
    $userId = makeUserCascade('task1@example.test');
    $plan = makePlanCascade();

    Subscription::query()->forceCreate([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);

    expect(Subscription::query()->where('plan_id', $plan->id)->exists())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Task 3: licenses.plan_id is RESTRICT — plan hard-delete blocked by license
// -----------------------------------------------------------------------------

it('Task 3 — DB refuses to hard-delete a plan that has a license', function (): void {
    $userId = makeUserCascade('task3@example.test');
    $plan = makePlanCascade();

    License::query()->forceCreate([
        'user_id' => $userId,
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);

    expect(License::query()->where('plan_id', $plan->id)->exists())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Task 4: licenses.user_id is RESTRICT — user hard-delete blocked by license
// -----------------------------------------------------------------------------

it('Task 4 — DB refuses to hard-delete a user that owns a license', function (): void {
    $userId = makeUserCascade('task4@example.test');
    $plan = makePlanCascade();

    License::query()->forceCreate([
        'user_id' => $userId,
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    expect(fn () => DB::table('users')->where('id', $userId)->delete())
        ->toThrow(QueryException::class);

    expect(License::query()->where('user_id', $userId)->exists())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Task 5: subscription_items.plan_id is RESTRICT
// -----------------------------------------------------------------------------

it('Task 5 — DB refuses to hard-delete a plan that has subscription_items', function (): void {
    $userId = makeUserCascade('task5@example.test');
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    SubscriptionItem::query()->forceCreate([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);
});

// -----------------------------------------------------------------------------
// Task 6: Plan supports SoftDeletes
// -----------------------------------------------------------------------------

it('Task 6 — Plan supports soft delete and is excluded from default queries', function (): void {
    $plan = makePlanCascade();
    $plan->delete();

    expect(Plan::query()->find($plan->id))->toBeNull();
    expect(Plan::withTrashed()->find($plan->id))->not->toBeNull();
    expect(Plan::withTrashed()->find($plan->id)->trashed())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Task 7: Subscription/License/SubscriptionItem plan() resolves trashed plans
// -----------------------------------------------------------------------------

it('Task 7 — Subscription, License, SubscriptionItem resolve their soft-deleted plan via withTrashed', function (): void {
    $userId = makeUserCascade('task7@example.test');
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    $license = License::query()->forceCreate([
        'user_id' => $userId,
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    $item = SubscriptionItem::query()->forceCreate([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $plan->delete();

    expect($subscription->fresh()->plan)->not->toBeNull()
        ->and($subscription->fresh()->plan->id)->toBe($plan->id);
    expect($license->fresh()->plan)->not->toBeNull()
        ->and($license->fresh()->plan->id)->toBe($plan->id);
    expect($item->fresh()->plan)->not->toBeNull()
        ->and($item->fresh()->plan->id)->toBe($plan->id);
});

// -----------------------------------------------------------------------------
// Task 8: Subscription soft-delete still works (paranoia after FK swap)
// -----------------------------------------------------------------------------

it('Task 8 — Subscription softDelete unaffected by FK changes', function (): void {
    $userId = makeUserCascade('task8@example.test');
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    $subscription->delete();

    expect(Subscription::query()->find($subscription->id))->toBeNull();
    expect(Subscription::withTrashed()->find($subscription->id))->not->toBeNull();
});
