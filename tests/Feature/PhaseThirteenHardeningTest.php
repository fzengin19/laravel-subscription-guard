<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Support\Json;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
// Helpers (suffixed -Hardening to avoid collisions with PhaseTwelve helpers)
// -----------------------------------------------------------------------------

function makeUserHardening(string $email = 'hardening@example.test'): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'Hardening',
        'email' => $email,
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// -----------------------------------------------------------------------------
// Task 1: Json::safeHash helper
// -----------------------------------------------------------------------------

it('Task 1 — Json::safeHash returns stable sha256 for identical scalar arrays', function (): void {
    $a = ['x' => 1, 'y' => [2, 3]];
    $b = ['x' => 1, 'y' => [2, 3]];

    expect(Json::safeHash($a))
        ->toBe(Json::safeHash($b))
        ->toMatch('/^[a-f0-9]{64}$/');
});

it('Task 1 — Json::safeHash never returns sha256-of-empty for binary payloads', function (): void {
    $emptyHash = hash('sha256', '');
    $binary = ['blob' => "\xC3\x28\xA0\xA1"];

    $result = Json::safeHash($binary);

    expect($result)
        ->not->toBe($emptyHash)
        ->toMatch('/^[a-f0-9]{64}$/');
});

it('Task 1 — Json::safeHash distinguishes two distinct binary payloads', function (): void {
    $a = Json::safeHash(['blob' => "\xC3\x28"]);
    $b = Json::safeHash(['blob' => "\xA0\xA1"]);

    expect($a)->not->toBe($b);
});

it('Task 1 — Json::safeHash falls back to serialize() for resources or cycles', function (): void {
    $a = new \stdClass;
    $a->self = $a; // recursion → json_encode throws

    $hash = Json::safeHash($a);

    expect($hash)
        ->toMatch('/^[a-f0-9]{64}$/')
        ->not->toBe(hash('sha256', ''));
});

// -----------------------------------------------------------------------------
// Task 2: SubscriptionStatus::Trialing case + transitions
// -----------------------------------------------------------------------------

it('Task 2 — SubscriptionStatus has a Trialing case with value "trialing"', function (): void {
    expect(SubscriptionStatus::Trialing->value)->toBe('trialing');
});

it('Task 2 — SubscriptionStatus::normalize recognises trialing in any case', function (): void {
    expect(SubscriptionStatus::normalize('trialing'))->toBe(SubscriptionStatus::Trialing);
    expect(SubscriptionStatus::normalize('TRIALING'))->toBe(SubscriptionStatus::Trialing);
    expect(SubscriptionStatus::normalize(' Trialing '))->toBe(SubscriptionStatus::Trialing);
});

it('Task 2 — Trialing transitions allow Active, PastDue, Cancelled, Failed', function (): void {
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Active))->toBeTrue();
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::PastDue))->toBeTrue();
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Cancelled))->toBeTrue();
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Failed))->toBeTrue();
});

it('Task 2 — Trialing rejects Paused and Suspended transitions', function (): void {
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Paused))->toBeFalse();
    expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Suspended))->toBeFalse();
});

it('Task 2 — Pending can transition to Trialing', function (): void {
    expect(SubscriptionStatus::Pending->canTransitionTo(SubscriptionStatus::Trialing))->toBeTrue();
});

it('Task 2 — Active cannot transition to Trialing (no admin-extension path)', function (): void {
    // Per rev2 plan: Active → Trialing was dropped as speculative.
    expect(SubscriptionStatus::Active->canTransitionTo(SubscriptionStatus::Trialing))->toBeFalse();
});
