<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper: create the minimal user + plan + license + subscription graph
// ---------------------------------------------------------------------------

function makeUserAndPlan(): array
{
    $userId = (int) DB::table('users')->insertGetId([
        'name'       => 'Cast Test User',
        'email'      => 'cast-test-'.uniqid().'@example.test',
        'password'   => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name'             => 'Cast Test Plan',
        'slug'             => 'cast-test-plan-'.uniqid(),
        'price'            => '49.99',
        'currency'         => 'TRY',
        'billing_period'   => 'monthly',
        'billing_interval' => 1,
        'is_active'        => true,
    ]);

    return [$userId, $plan];
}

// ---------------------------------------------------------------------------
// Transaction – 7 decimal fields
// ---------------------------------------------------------------------------

it('casts Transaction decimal fields to float', function (): void {
    [$userId, $plan] = makeUserAndPlan();

    $license = \SubscriptionGuard\LaravelSubscriptionGuard\Models\License::unguarded(
        static fn () => \SubscriptionGuard\LaravelSubscriptionGuard\Models\License::query()->create([
            'user_id'    => $userId,
            'plan_id'    => $plan->getKey(),
            'key'        => 'SG.cast.tx.'.bin2hex(random_bytes(6)),
            'status'     => 'active',
            'expires_at' => now()->addMonth(),
        ])
    );

    $transaction = Transaction::unguarded(
        static fn () => Transaction::query()->create([
            'subscription_id'      => null,
            'payable_type'         => 'App\\Models\\User',
            'payable_id'           => $userId,
            'license_id'           => $license->getKey(),
            'provider'             => 'test',
            'idempotency_key'      => 'cast-tx-'.bin2hex(random_bytes(8)),
            'type'                 => 'payment',
            'status'               => 'pending',
            'amount'               => '99.99',
            'tax_amount'           => '18.00',
            'tax_rate'             => '18.00',
            'discount_amount'      => '5.50',
            'refunded_amount'      => '0.00',
            'fee'                  => '2.25',
            'exchange_rate'        => '1.250000',
            'currency'             => 'TRY',
        ])
    );

    $fresh = $transaction->fresh();

    expect($fresh->getAttribute('amount'))->toBeFloat();
    expect($fresh->getAttribute('tax_amount'))->toBeFloat();
    expect($fresh->getAttribute('tax_rate'))->toBeFloat();
    expect($fresh->getAttribute('discount_amount'))->toBeFloat();
    expect($fresh->getAttribute('refunded_amount'))->toBeFloat();
    expect($fresh->getAttribute('fee'))->toBeFloat();
    expect($fresh->getAttribute('exchange_rate'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// Subscription – 3 decimal fields
// ---------------------------------------------------------------------------

it('casts Subscription decimal fields to float', function (): void {
    [$userId, $plan] = makeUserAndPlan();

    $subscription = Subscription::unguarded(
        static fn () => Subscription::query()->create([
            'subscribable_type'  => 'App\\Models\\User',
            'subscribable_id'    => $userId,
            'plan_id'            => $plan->getKey(),
            'provider'           => 'test',
            'status'             => 'pending',
            'billing_period'     => 'monthly',
            'billing_interval'   => 1,
            'amount'             => '149.99',
            'tax_amount'         => '26.99',
            'tax_rate'           => '18.00',
            'currency'           => 'TRY',
        ])
    );

    $fresh = $subscription->fresh();

    expect($fresh->getAttribute('amount'))->toBeFloat();
    expect($fresh->getAttribute('tax_amount'))->toBeFloat();
    expect($fresh->getAttribute('tax_rate'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// Plan – 1 decimal field
// ---------------------------------------------------------------------------

it('casts Plan price to float', function (): void {
    $plan = Plan::query()->create([
        'name'             => 'Float Plan '.uniqid(),
        'slug'             => 'float-plan-'.uniqid(),
        'price'            => '99.99',
        'currency'         => 'TRY',
        'billing_period'   => 'monthly',
        'billing_interval' => 1,
        'is_active'        => true,
    ]);

    $fresh = $plan->fresh();

    expect($fresh->getAttribute('price'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// Invoice – 3 decimal fields
// ---------------------------------------------------------------------------

it('casts Invoice decimal fields to float', function (): void {
    [$userId] = makeUserAndPlan();

    $invoice = Invoice::unguarded(
        static fn () => Invoice::query()->create([
            'invoice_number'   => 'INV-CAST-'.uniqid(),
            'subscribable_type' => 'App\\Models\\User',
            'subscribable_id'  => $userId,
            'status'           => 'draft',
            'subtotal'         => '79.99',
            'tax_amount'       => '14.40',
            'total_amount'     => '94.39',
            'currency'         => 'TRY',
        ])
    );

    $fresh = $invoice->fresh();

    expect($fresh->getAttribute('subtotal'))->toBeFloat();
    expect($fresh->getAttribute('tax_amount'))->toBeFloat();
    expect($fresh->getAttribute('total_amount'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// Coupon – 3 decimal fields
// ---------------------------------------------------------------------------

it('casts Coupon decimal fields to float', function (): void {
    $coupon = Coupon::query()->create([
        'code'               => 'CAST'.strtoupper(bin2hex(random_bytes(4))),
        'name'               => 'Cast Coupon',
        'type'               => 'percentage',
        'value'              => '15.00',
        'min_purchase_amount' => '50.00',
        'max_discount_amount' => '100.00',
        'is_active'          => true,
    ]);

    $fresh = $coupon->fresh();

    expect($fresh->getAttribute('value'))->toBeFloat();
    expect($fresh->getAttribute('min_purchase_amount'))->toBeFloat();
    expect($fresh->getAttribute('max_discount_amount'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// Discount – 2 decimal fields
// ---------------------------------------------------------------------------

it('casts Discount decimal fields to float', function (): void {
    $coupon = Coupon::query()->create([
        'code'      => 'DCAST'.strtoupper(bin2hex(random_bytes(4))),
        'name'      => 'Discount Cast Coupon',
        'type'      => 'percentage',
        'value'     => '10.00',
        'is_active' => true,
    ]);

    [$userId] = makeUserAndPlan();

    $discount = Discount::unguarded(
        static fn () => Discount::query()->create([
            'coupon_id'        => $coupon->getKey(),
            'discountable_type' => 'App\\Models\\User',
            'discountable_id'  => $userId,
            'type'             => 'percentage',
            'value'            => '10.00',
            'currency'         => 'TRY',
            'duration'         => 'once',
            'applied_cycles'   => 1,
            'applied_amount'   => '9.99',
        ])
    );

    $fresh = $discount->fresh();

    expect($fresh->getAttribute('value'))->toBeFloat();
    expect($fresh->getAttribute('applied_amount'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// SubscriptionItem – unit_price (float) + quantity (integer)
// ---------------------------------------------------------------------------

it('casts SubscriptionItem unit_price to float and quantity to integer', function (): void {
    [$userId, $plan] = makeUserAndPlan();

    $subscription = Subscription::unguarded(
        static fn () => Subscription::query()->create([
            'subscribable_type' => 'App\\Models\\User',
            'subscribable_id'   => $userId,
            'plan_id'           => $plan->getKey(),
            'provider'          => 'test',
            'status'            => 'pending',
            'billing_period'    => 'monthly',
            'billing_interval'  => 1,
            'amount'            => 0,
            'currency'          => 'TRY',
        ])
    );

    $item = SubscriptionItem::unguarded(
        static fn () => SubscriptionItem::query()->create([
            'subscription_id' => $subscription->getKey(),
            'plan_id'         => $plan->getKey(),
            'quantity'        => '3',
            'unit_price'      => '29.99',
        ])
    );

    $fresh = $item->fresh();

    expect($fresh->getAttribute('unit_price'))->toBeFloat();
    expect($fresh->getAttribute('quantity'))->toBeInt();
});

// ---------------------------------------------------------------------------
// LicenseUsage – 1 decimal field
// ---------------------------------------------------------------------------

it('casts LicenseUsage quantity to float', function (): void {
    [$userId, $plan] = makeUserAndPlan();

    $license = \SubscriptionGuard\LaravelSubscriptionGuard\Models\License::unguarded(
        static fn () => \SubscriptionGuard\LaravelSubscriptionGuard\Models\License::query()->create([
            'user_id'    => $userId,
            'plan_id'    => $plan->getKey(),
            'key'        => 'SG.cast.lu.'.bin2hex(random_bytes(6)),
            'status'     => 'active',
            'expires_at' => now()->addMonth(),
        ])
    );

    $usage = LicenseUsage::unguarded(
        static fn () => LicenseUsage::query()->create([
            'license_id'   => $license->getKey(),
            'metric'       => 'api_calls',
            'quantity'     => '5.75',
            'period_start' => now()->subDay(),
            'period_end'   => now()->addDay(),
        ])
    );

    $fresh = $usage->fresh();

    expect($fresh->getAttribute('quantity'))->toBeFloat();
});

// ---------------------------------------------------------------------------
// ScheduledPlanChange – 1 decimal field
// ---------------------------------------------------------------------------

it('casts ScheduledPlanChange proration_credit to float', function (): void {
    [$userId, $fromPlan] = makeUserAndPlan();

    $toPlan = Plan::query()->create([
        'name'             => 'To Plan '.uniqid(),
        'slug'             => 'to-plan-'.uniqid(),
        'price'            => '199.99',
        'currency'         => 'TRY',
        'billing_period'   => 'monthly',
        'billing_interval' => 1,
        'is_active'        => true,
    ]);

    $subscription = Subscription::unguarded(
        static fn () => Subscription::query()->create([
            'subscribable_type' => 'App\\Models\\User',
            'subscribable_id'   => $userId,
            'plan_id'           => $fromPlan->getKey(),
            'provider'          => 'test',
            'status'            => 'active',
            'billing_period'    => 'monthly',
            'billing_interval'  => 1,
            'amount'            => 0,
            'currency'          => 'TRY',
        ])
    );

    $change = ScheduledPlanChange::unguarded(
        static fn () => ScheduledPlanChange::query()->create([
            'subscription_id'  => $subscription->getKey(),
            'from_plan_id'     => $fromPlan->getKey(),
            'to_plan_id'       => $toPlan->getKey(),
            'change_type'      => 'switch',
            'scheduled_at'     => now()->addDay(),
            'proration_type'   => 'credit',
            'proration_credit' => '12.50',
            'status'           => 'pending',
        ])
    );

    $fresh = $change->fresh();

    expect($fresh->getAttribute('proration_credit'))->toBeFloat();
});
