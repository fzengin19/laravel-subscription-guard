<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

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
