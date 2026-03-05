<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\WebhookReceived;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\PaymentMethod;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

function sendCallback(string $provider, string $path, array $payload, array $headers = []): TestResponse
{
    $server = array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers);

    $request = Request::create(
        '/subguard/webhooks/'.$provider.$path,
        'POST',
        [],
        [],
        [],
        $server,
        (string) json_encode($payload)
    );

    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.iyzico.mock' => true,
        'subscription-guard.providers.drivers.iyzico.api_key' => null,
        'subscription-guard.providers.drivers.iyzico.secret_key' => null,
    ]);
});

it('registers phase two commands and resolves iyzico provider adapter', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys([
        'subguard:sync-plans',
        'subguard:reconcile-iyzico-subscriptions',
    ]);

    $manager = app(PaymentManager::class);
    $provider = $manager->provider('iyzico');

    expect($provider)->toBeInstanceOf(IyzicoProvider::class);
    expect($provider->managesOwnBilling())->toBeTrue();
});

it('syncs iyzico plan references idempotently', function (): void {
    $plan = Plan::query()->create([
        'name' => 'Phase Two Plan',
        'slug' => 'phase-two-plan',
        'price' => 149.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $firstExitCode = Artisan::call('subguard:sync-plans', ['--provider' => 'iyzico']);
    expect($firstExitCode)->toBe(0);

    $firstPlanState = $plan->fresh();

    expect((string) $firstPlanState?->getAttribute('iyzico_product_reference'))->toBe('iyz-prod-phase-two-plan');
    expect((string) $firstPlanState?->getAttribute('iyzico_pricing_plan_reference'))->toBe('iyz-price-phase-two-plan');

    $secondExitCode = Artisan::call('subguard:sync-plans', ['--provider' => 'iyzico']);
    expect($secondExitCode)->toBe(0);
});

it('falls back to local deterministic sync when remote mode is requested in mock configuration', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);

    $plan = Plan::query()->create([
        'name' => 'Phase Two Remote Sync Fallback',
        'slug' => 'phase-two-remote-sync-fallback',
        'price' => 159.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $exitCode = Artisan::call('subguard:sync-plans', [
        '--provider' => 'iyzico',
        '--remote' => true,
    ]);

    expect($exitCode)->toBeIn([0, 1]);

    $plan->refresh();
    expect((string) $plan->getAttribute('iyzico_product_reference'))->toBe('iyz-prod-phase-two-remote-sync-fallback');
    expect((string) $plan->getAttribute('iyzico_pricing_plan_reference'))->toBe('iyz-price-phase-two-remote-sync-fallback');
});

it('reconciles pending iyzico subscriptions', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Reconcile User',
        'email' => 'reconcile-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Reconcile Plan',
        'slug' => 'reconcile-plan',
        'price' => 199.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_reconcile_001',
        'status' => 'pending',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 199.90,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]));

    $dryRunExitCode = Artisan::call('subguard:reconcile-iyzico-subscriptions', ['--dry-run' => true]);
    expect($dryRunExitCode)->toBe(0);
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('pending');

    $runExitCode = Artisan::call('subguard:reconcile-iyzico-subscriptions');
    expect($runExitCode)->toBe(0);
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('active');
});

it('falls back to metadata reconciliation when remote mode is requested in mock configuration', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Metadata Reconcile User',
        'email' => 'metadata-reconcile-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Metadata Reconcile Plan',
        'slug' => 'metadata-reconcile-plan',
        'price' => 89.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_metadata_reconcile_001',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 89.90,
        'currency' => 'TRY',
        'metadata' => ['iyzico_remote_status' => 'canceled'],
        'next_billing_date' => now()->addMonth(),
    ]));

    $exitCode = Artisan::call('subguard:reconcile-iyzico-subscriptions', [
        '--remote' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('cancelled');
});

it('finalizes iyzico order success webhooks through provider adapter', function (): void {
    Event::fake([WebhookReceived::class, SubscriptionRenewed::class]);
    config()->set('app.timezone', 'UTC');

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Iyzico User',
        'email' => 'iyzico-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Iyzico Growth',
        'slug' => 'iyzico-growth',
        'price' => 299.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_phase2_001',
        'status' => 'past_due',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 299.90,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]));

    $payload = [
        'event_id' => 'evt_phase2_success_001',
        'event_type' => 'subscription.order.success',
        'subscription_id' => 'iyz_sub_phase2_001',
        'payment_id' => 'pay_phase2_001',
        'paid_price' => 299.90,
        'nextPaymentDate' => '2026-03-29 00:30:00',
    ];

    $webhookCall = WebhookCall::query()->create([
        'provider' => 'iyzico',
        'event_type' => 'subscription.order.success',
        'event_id' => 'evt_phase2_success_001',
        'idempotency_key' => 'iyzico:evt_phase2_success_001',
        'payload' => $payload,
        'headers' => ['x-iyz-signature-v3' => ['ignored-in-mock-mode']],
        'status' => 'pending',
    ]);

    $job = new FinalizeWebhookEventJob((int) $webhookCall->getKey());
    $job->handle(app(PaymentManager::class));

    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('active');
    expect($subscription->fresh()?->getAttribute('next_billing_date')?->format('Y-m-d H:i:s'))
        ->toBe(
            Carbon::parse('2026-03-29 00:30:00', 'Europe/Istanbul')
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s')
        );
    expect((string) $webhookCall->fresh()?->getAttribute('status'))->toBe('processed');

    $transaction = Transaction::query()->where('idempotency_key', 'iyzico:webhook:evt_phase2_success_001')->first();

    expect($transaction)->not->toBeNull();
    expect((string) $transaction?->getAttribute('status'))->toBe('processed');
    expect((string) $transaction?->getAttribute('provider_transaction_id'))->toBe('pay_phase2_001');

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(SubscriptionRenewed::class);
});

it('accepts iyzico callback endpoints and persists callback payload', function (): void {
    $threeDsResponse = sendCallback('iyzico', '/3ds/callback', [
        'conversationId' => '3ds-conv-001',
        'status' => 'success',
    ]);

    $threeDsResponse->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'duplicate' => false,
    ]);

    $checkoutResponse = sendCallback('iyzico', '/checkout/callback', [
        'token' => 'checkout-token-001',
        'status' => 'success',
    ]);

    $checkoutResponse->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'duplicate' => false,
    ]);

    expect(WebhookCall::query()->where('event_type', 'payment.3ds.callback')->count())->toBe(1);
    expect(WebhookCall::query()->where('event_type', 'payment.checkout.callback')->count())->toBe(1);
});

it('derives iyzico callback event id from referenceCode before hash fallback', function (): void {
    $response = sendCallback('iyzico', '/checkout/callback', [
        'referenceCode' => 'checkout-ref-001',
        'status' => 'success',
    ]);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => 'checkout-ref-001',
        'duplicate' => false,
    ]);
});

it('handles duplicate callback intake atomically for same event id', function (): void {
    Bus::fake();

    $payload = [
        'conversationId' => '3ds-conv-duplicate-001',
        'status' => 'success',
    ];

    $first = sendCallback('iyzico', '/3ds/callback', $payload);
    $second = sendCallback('iyzico', '/3ds/callback', $payload);

    $first->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => '3ds-conv-duplicate-001',
        'duplicate' => false,
    ]);

    $second->assertStatus(200)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => '3ds-conv-duplicate-001',
        'duplicate' => true,
    ]);

    expect(WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_id', '3ds-conv-duplicate-001')
        ->count())->toBe(1);

    Bus::assertDispatchedTimes(FinalizeWebhookEventJob::class, 1);
});

it('rejects iyzico callback when live mode signature is missing', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'phase2-secret');

    $response = sendCallback('iyzico', '/3ds/callback', [
        'event_type' => 'payment.3ds.callback',
        'status' => 'success',
        'paymentId' => 'pay_live_001',
        'paymentConversationId' => 'conv_live_001',
    ]);

    $response->assertStatus(401)->assertJson([
        'status' => 'rejected',
        'provider' => 'iyzico',
        'reason' => 'Invalid callback signature.',
    ]);
});

it('does not persist iyzico card tokens in provider adapter createSubscription', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Card User',
        'email' => 'card-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $provider = app(PaymentManager::class)->provider('iyzico');
    $response = $provider->createSubscription(
        [
            'slug' => 'phase-two-card-storage',
            'iyzico_pricing_plan_reference' => 'iyz-price-phase-two-card-storage',
        ],
        [
            'payable_type' => 'App\\Models\\User',
            'payable_id' => $userId,
            'payment_method' => [
                'provider_customer_token' => 'cust_tok_001',
                'provider_card_token' => 'card_tok_001',
                'provider_method_id' => 'pm_001',
                'card_last_four' => '4242',
                'card_brand' => 'VISA',
                'card_expiry' => '12/30',
                'card_holder_name' => 'Card User',
                'is_default' => true,
            ],
        ]
    );

    expect($response->success)->toBeTrue();

    $method = PaymentMethod::query()->where('provider', 'iyzico')->first();
    expect($method)->toBeNull();
});

it('persists iyzico card tokens through subscription service orchestration', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Card Orchestration User',
        'email' => 'card-orchestration-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(SubscriptionService::class);

    $method = $service->persistProviderPaymentMethod('iyzico', [
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'payment_method' => [
            'provider_customer_token' => 'cust_tok_002',
            'provider_card_token' => 'card_tok_002',
            'provider_method_id' => 'pm_002',
            'card_last_four' => '5454',
            'card_brand' => 'MASTERCARD',
            'card_expiry' => '11/31',
            'card_holder_name' => 'Card Orchestration User',
            'is_default' => true,
        ],
    ]);

    expect($method)->not->toBeNull();
    expect((string) $method?->getAttribute('provider_customer_token'))->toBe('cust_tok_002');
    expect((string) $method?->getAttribute('provider_card_token'))->toBe('card_tok_002');
    expect((bool) $method?->getAttribute('is_default'))->toBeTrue();
});

it('returns a credential-focused error in live payment mode when keys are missing', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.api_key', null);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', null);

    $provider = app(PaymentManager::class)->provider('iyzico');
    $response = $provider->pay(199.90, ['mode' => 'non_3ds']);

    expect($response->success)->toBeFalse();
    expect((string) $response->failureReason)->toContain('credentials');
});

it('blocks iyzico subscription creation when pricing plan reference is missing', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $response = $provider->createSubscription([
        'slug' => 'unmapped-plan',
        'iyzico_pricing_plan_reference' => '',
    ], []);

    expect($response->success)->toBeFalse();
    expect((string) $response->failureReason)->toContain('pricing plan reference');
});

it('reconcile command aligns local status using remote status snapshot metadata', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Remote Reconcile User',
        'email' => 'remote-reconcile-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Remote Reconcile Plan',
        'slug' => 'remote-reconcile-plan',
        'price' => 99.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_remote_001',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 99.90,
        'currency' => 'TRY',
        'metadata' => [
            'iyzico_remote_status' => 'cancelled',
        ],
    ]));

    $exitCode = Artisan::call('subguard:reconcile-iyzico-subscriptions');

    expect($exitCode)->toBe(0);
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('cancelled');
});

it('builds iyzico callback urls from auto-route prefix and custom override', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
    config()->set('subscription-guard.providers.drivers.iyzico.callback_url', null);
    config()->set('subscription-guard.webhooks.prefix', 'subguard/webhooks');

    $provider = app(PaymentManager::class)->provider('iyzico');

    $threeDsResponse = $provider->pay(99.90, ['mode' => '3ds']);

    expect((string) ($threeDsResponse->providerResponse['callback_url'] ?? ''))
        ->toBe('http://localhost/subguard/webhooks/iyzico/3ds/callback');

    config()->set('subscription-guard.providers.drivers.iyzico.callback_url', 'https://merchant.example/hooks/iyzico');

    $checkoutResponse = $provider->pay(99.90, ['mode' => 'checkout_form']);

    expect((string) ($checkoutResponse->providerResponse['callback_url'] ?? ''))
        ->toBe('https://merchant.example/hooks/iyzico/checkout/callback');

    config()->set('subscription-guard.providers.drivers.iyzico.callback_url', 'not-a-valid-url');

    $fallbackResponse = $provider->pay(99.90, ['mode' => 'checkout_form']);

    expect((string) ($fallbackResponse->providerResponse['callback_url'] ?? ''))
        ->toBe('http://localhost/subguard/webhooks/iyzico/checkout/callback');
});

it('parses iyzico webhook payload without mutating local billing state in provider adapter', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Pure Adapter User',
        'email' => 'pure-adapter-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Pure Adapter Plan',
        'slug' => 'pure-adapter-plan',
        'price' => 149.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_pure_001',
        'status' => 'past_due',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 149.90,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]));

    $provider = app(PaymentManager::class)->provider('iyzico');
    $result = $provider->processWebhook([
        'event_id' => 'evt_pure_adapter_001',
        'event_type' => 'subscription.order.success',
        'subscription_id' => 'iyz_sub_pure_001',
        'payment_id' => 'pay_pure_adapter_001',
        'paid_price' => 149.90,
    ]);

    expect($result->processed)->toBeTrue();
    expect($result->eventId)->toBe('evt_pure_adapter_001');
    expect($result->eventType)->toBe('subscription.order.success');
    expect($result->subscriptionId)->toBe('iyz_sub_pure_001');
    expect($result->transactionId)->toBe('pay_pure_adapter_001');
    expect($result->amount)->toBe(149.90);

    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('past_due');
    expect(Transaction::query()->where('idempotency_key', 'iyzico:webhook:evt_pure_adapter_001')->exists())->toBeFalse();
});

it('applies normalized webhook result through subscription service orchestration', function (): void {
    Event::fake([WebhookReceived::class, SubscriptionRenewed::class]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Orchestration User',
        'email' => 'orchestration-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Orchestration Plan',
        'slug' => 'orchestration-plan',
        'price' => 249.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_orchestrated_001',
        'status' => 'past_due',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 249.90,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]));

    $service = app(SubscriptionService::class);

    $service->handleWebhookResult(new WebhookResult(
        processed: true,
        eventId: 'evt_orchestrated_001',
        eventType: 'subscription.order.success',
        subscriptionId: 'iyz_sub_orchestrated_001',
        transactionId: 'pay_orchestrated_001',
        amount: 249.90,
        status: 'active',
    ), 'iyzico');

    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('active');

    $transaction = Transaction::query()->where('idempotency_key', 'iyzico:webhook:evt_orchestrated_001')->first();

    expect($transaction)->not->toBeNull();
    expect((string) $transaction?->getAttribute('status'))->toBe('processed');
    expect((string) $transaction?->getAttribute('provider_transaction_id'))->toBe('pay_orchestrated_001');

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(SubscriptionRenewed::class);
});

it('ignores out-of-order activation webhooks for cancelled subscriptions', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Cancelled Guard User',
        'email' => 'cancelled-guard-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Cancelled Guard Plan',
        'slug' => 'cancelled-guard-plan',
        'price' => 59.90,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_sub_cancelled_guard_001',
        'status' => 'cancelled',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 59.90,
        'currency' => 'TRY',
    ]));

    app(SubscriptionService::class)->handleWebhookResult(new WebhookResult(
        processed: true,
        eventId: 'evt_out_of_order_001',
        eventType: 'subscription.created',
        subscriptionId: 'iyz_sub_cancelled_guard_001',
        status: 'active',
    ), 'iyzico');

    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('cancelled');
});
