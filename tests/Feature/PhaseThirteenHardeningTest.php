<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessTrialExpiryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;
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

// -----------------------------------------------------------------------------
// Task 3: no raw 'trialing' string outside the enum itself
// -----------------------------------------------------------------------------

it('Task 3 — no production source file (outside the enum) contains the raw string "trialing"', function (): void {
    $paths = [
        __DIR__.'/../../src/Subscription/SubscriptionService.php',
        __DIR__.'/../../src/Payment/Providers/PayTR/PaytrProvider.php',
        __DIR__.'/../../src/Jobs/PaymentChargeJob.php',
        __DIR__.'/../../src/Jobs/ProcessRenewalCandidateJob.php',
        __DIR__.'/../../src/Licensing/LicenseManager.php',
    ];

    foreach ($paths as $path) {
        $content = (string) file_get_contents($path);
        $hasRawTrialing = str_contains($content, "'trialing'");
        expect($hasRawTrialing)->toBeFalse("Raw 'trialing' string still present in {$path}");
    }
});

// -----------------------------------------------------------------------------
// Task 4: plans.trial_days migration
// -----------------------------------------------------------------------------

it('Task 4 — plans table has trial_days column', function (): void {
    expect(Schema::hasColumn('plans', 'trial_days'))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Task 5: trial-aware SubscriptionService::create()
// -----------------------------------------------------------------------------

it('Task 5 — create() sets status=trialing and trial_ends_at when plan.trial_days > 0', function (): void {
    $userId = makeUserHardening('trial-create@example.test');
    $plan = Plan::query()->create([
        'name' => 'Trial Plan',
        'slug' => 'trial-plan-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 99,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'trial_days' => 15,
        'provider' => 'paytr',
    ]);

    $service = app(SubscriptionService::class);
    $result = $service->create($userId, $plan->getKey(), 1);

    expect($result['status'])->toBe(SubscriptionStatus::Trialing->value);
    expect($result['trial_ends_at'])->not->toBeNull();
});

it('Task 5 — create() sets status=pending and trial_ends_at=null when plan has no trial_days', function (): void {
    $userId = makeUserHardening('no-trial-create@example.test');
    $plan = Plan::query()->create([
        'name' => 'Regular Plan',
        'slug' => 'regular-plan-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 99,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'provider' => 'paytr',
    ]);

    $service = app(SubscriptionService::class);
    $result = $service->create($userId, $plan->getKey(), 1);

    expect($result['status'])->toBe(SubscriptionStatus::Pending->value);
    expect($result['trial_ends_at'])->toBeNull();
});

it('Task 5 — create() with zero trial_days behaves like no-trial', function (): void {
    $userId = makeUserHardening('zero-trial@example.test');
    $plan = Plan::query()->create([
        'name' => 'Zero Trial',
        'slug' => 'zero-trial-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 99,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'trial_days' => 0,
        'provider' => 'paytr',
    ]);

    $service = app(SubscriptionService::class);
    $result = $service->create($userId, $plan->getKey(), 1);

    expect($result['status'])->toBe(SubscriptionStatus::Pending->value);
    expect($result['trial_ends_at'])->toBeNull();
});

// -----------------------------------------------------------------------------
// Task 6: ProcessTrialExpiryJob + subguard:process-trial-expiry command
// -----------------------------------------------------------------------------

function makeTrialingSubscriptionHardening(string $email, string $provider, $trialEndsAt): Subscription
{
    $userId = makeUserHardening($email);
    $plan = Plan::query()->create([
        'name' => 'P',
        'slug' => 'trial-'.bin2hex(random_bytes(3)),
        'currency' => 'TRY',
        'price' => 50,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'trial_days' => 15,
        'provider' => $provider,
    ]);

    return Subscription::unguarded(fn (): Subscription => Subscription::query()->create([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => $provider,
        'status' => SubscriptionStatus::Trialing->value,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 50,
        'currency' => 'TRY',
        'trial_ends_at' => $trialEndsAt,
        'next_billing_date' => $trialEndsAt,
        'metadata' => [],
    ]));
}

it('Task 6 — ProcessTrialExpiryJob transitions trialing → past_due when self-managed trial expired', function (): void {
    $sub = makeTrialingSubscriptionHardening('trial-exp-pastdue@example.test', 'paytr', now()->subMinute());

    (new ProcessTrialExpiryJob((int) $sub->getKey()))->handle(
        app(PaymentManager::class),
        app(SubscriptionService::class),
    );

    $sub->refresh();
    expect($sub->getAttribute('status'))->toBe(SubscriptionStatus::PastDue->value);
});

it('Task 6 — ProcessTrialExpiryJob leaves status unchanged when trial not yet expired', function (): void {
    $sub = makeTrialingSubscriptionHardening('trial-future@example.test', 'paytr', now()->addDays(5));

    (new ProcessTrialExpiryJob((int) $sub->getKey()))->handle(
        app(PaymentManager::class),
        app(SubscriptionService::class),
    );

    $sub->refresh();
    expect($sub->getAttribute('status'))->toBe(SubscriptionStatus::Trialing->value);
});

it('Task 6 — ProcessTrialExpiryJob skips provider-managed subscriptions (iyzico drives transition via webhook)', function (): void {
    $sub = makeTrialingSubscriptionHardening('trial-iyzico@example.test', 'iyzico', now()->subMinute());

    (new ProcessTrialExpiryJob((int) $sub->getKey()))->handle(
        app(PaymentManager::class),
        app(SubscriptionService::class),
    );

    $sub->refresh();
    expect($sub->getAttribute('status'))->toBe(SubscriptionStatus::Trialing->value);
});

it('Task 6 — subguard:process-trial-expiry dispatches a job per expired trialing subscription', function (): void {
    Queue::fake();

    makeTrialingSubscriptionHardening('cmd-trial-1@example.test', 'paytr', now()->subMinute());
    makeTrialingSubscriptionHardening('cmd-trial-2@example.test', 'paytr', now()->subMinute());
    makeTrialingSubscriptionHardening('cmd-trial-future@example.test', 'paytr', now()->addDays(5));

    $exitCode = Artisan::call('subguard:process-trial-expiry');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(ProcessTrialExpiryJob::class, 2);
});

// -----------------------------------------------------------------------------
// Task 7-narrow: Json::safeHash at webhook eventId fallback sites
// -----------------------------------------------------------------------------

it('Task 7 — IyzicoProvider::processWebhook eventId is stable, non-empty for binary payload', function (): void {
    $provider = app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider::class);
    $emptyHash = hash('sha256', '');

    $a = $provider->processWebhook(['data' => "\xC3\x28"]);
    $b = $provider->processWebhook(['data' => "\xA0\xA1"]);

    expect($a->eventId)->not->toBe($emptyHash);
    expect($b->eventId)->not->toBe($emptyHash);
    expect($a->eventId)->not->toBe($b->eventId);
});

it('Task 7 — PaytrProvider::processWebhook eventId is stable, non-empty for binary payload (no merchant_oid/event_id)', function (): void {
    $provider = app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider::class);
    $emptyHash = hash('sha256', '');

    $a = $provider->processWebhook(['status' => 'failed', 'data' => "\xC3\x28"]);
    $b = $provider->processWebhook(['status' => 'failed', 'data' => "\xA0\xA1"]);

    expect($a->eventId)->not->toBe($emptyHash);
    expect($b->eventId)->not->toBe($emptyHash);
    expect($a->eventId)->not->toBe($b->eventId);
});

// -----------------------------------------------------------------------------
// Task 9: lazyById chunking for cron-style queries
// -----------------------------------------------------------------------------

it('Task 9 — SubscriptionService uses lazyById for all four cron-style queries', function (): void {
    $content = (string) file_get_contents(__DIR__.'/../../src/Subscription/SubscriptionService.php');

    // 4 cron methods (processRenewals, processDunning, processScheduledPlanChanges, retryPastDuePayments)
    // each replaced their ->get() with ->lazyById(500).
    expect(substr_count($content, '->lazyById(500)'))->toBeGreaterThanOrEqual(4);

    // No Eloquent ->get() should remain in cron methods. We assert by checking that
    // no line containing "Subscription::query()", "Transaction::query()", or
    // "ScheduledPlanChange::query()" is followed within the same chain by ->get().
    // Simpler: assert ->get() does not appear in cron methods' bodies by looking for
    // ->whereIn or ->where chains that end with get().
    $cronMethodGets = preg_match_all('/Subscription::query\(\)[^;]*->get\(\)|Transaction::query\(\)[^;]*->get\(\)|ScheduledPlanChange::query\(\)[^;]*->get\(\)/s', $content);
    expect($cronMethodGets)->toBe(0);
});

// -----------------------------------------------------------------------------
// Task 12: cancel TOCTOU — re-check status inside the lock
// -----------------------------------------------------------------------------

it('Task 12 — cancel returns true for an already-cancelled subscription (pre-lock idempotency)', function (): void {
    $userId = makeUserHardening('cancel-already@example.test');
    $plan = Plan::query()->create([
        'name' => 'P',
        'slug' => 'cancel-already-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 50,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'provider' => 'paytr',
    ]);
    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => SubscriptionStatus::Cancelled->value,
        'cancelled_at' => now(),
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 50,
        'currency' => 'TRY',
        'metadata' => [],
    ]));

    expect(app(SubscriptionService::class)->cancel($sub->getKey()))->toBeTrue();
});

it('Task 12 — cancel returns true when DB became cancelled between find and lock-attempt', function (): void {
    $userId = makeUserHardening('cancel-toctou@example.test');
    $plan = Plan::query()->create([
        'name' => 'P',
        'slug' => 'cancel-toctou-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 50,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'provider' => 'paytr',
    ]);
    $sub = Subscription::unguarded(fn () => Subscription::query()->create([
        'subscribable_type' => config('auth.providers.users.model'),
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => SubscriptionStatus::Active->value,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 50,
        'currency' => 'TRY',
        'metadata' => [],
    ]));

    // Simulate winner: hold the cache lock externally AND mark sub cancelled in DB.
    $externalLock = \Illuminate\Support\Facades\Cache::lock('subguard:subscription-cancel:'.$sub->getKey(), 30);
    expect($externalLock->get())->toBeTrue();

    DB::table('subscriptions')->where('id', $sub->getKey())->update([
        'status' => SubscriptionStatus::Cancelled->value,
        'cancelled_at' => now(),
    ]);

    // Loser arrives: find() will read fresh-cancelled, line 100-102 short-circuit fires → true.
    expect(app(SubscriptionService::class)->cancel($sub->getKey()))->toBeTrue();

    $externalLock->release();
});

// -----------------------------------------------------------------------------
// Task B: PaymentCallbackController must strip sensitive headers before persisting
// -----------------------------------------------------------------------------

it('Task B — PaymentCallbackController strips authorization and cookie headers before storing WebhookCall', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);

    $response = $this->postJson(
        '/subguard/webhooks/iyzico/3ds/callback',
        [
            'event_type' => 'subscription.created',
            'event_id' => 'evt_callback_filter_'.bin2hex(random_bytes(4)),
            'paymentId' => 'pay_123',
            'status' => 'success',
        ],
        [
            'Authorization' => 'Bearer secret-token-should-not-persist',
            'Cookie' => 'session=do-not-persist',
            'X-Iyz-Signature-V3' => 'mock-signature',
        ]
    );

    expect($response->status())->toBeIn([200, 202]);

    $call = \SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall::query()
        ->where('provider', 'iyzico')
        ->latest('id')
        ->first();

    expect($call)->not->toBeNull();
    $headers = $call->getAttribute('headers');
    expect($headers)->toBeArray();
    expect(array_keys(array_change_key_case($headers, CASE_LOWER)))->not->toContain('authorization');
    expect(array_keys(array_change_key_case($headers, CASE_LOWER)))->not->toContain('cookie');
});

it('Task 9 — processRenewals still dispatches one job per candidate across many subscriptions', function (): void {
    Queue::fake();

    $plan = Plan::query()->create([
        'name' => 'BulkPlan',
        'slug' => 'bulk-plan-'.bin2hex(random_bytes(2)),
        'currency' => 'TRY',
        'price' => 50,
        'billing_period' => 'month',
        'billing_interval' => 1,
        'provider' => 'paytr',
    ]);

    for ($i = 0; $i < 25; $i++) {
        $userId = makeUserHardening("bulk-{$i}@example.test");
        Subscription::unguarded(fn () => Subscription::query()->create([
            'subscribable_type' => config('auth.providers.users.model'),
            'subscribable_id' => $userId,
            'plan_id' => $plan->getKey(),
            'provider' => 'paytr',
            'status' => SubscriptionStatus::Active->value,
            'billing_period' => 'month',
            'billing_interval' => 1,
            'amount' => 50,
            'currency' => 'TRY',
            'next_billing_date' => now()->subMinute(),
            'metadata' => [],
        ]));
    }

    $count = app(SubscriptionService::class)->processRenewals(now());

    expect($count)->toBe(25);
    Queue::assertPushed(
        \SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob::class,
        25
    );
});
