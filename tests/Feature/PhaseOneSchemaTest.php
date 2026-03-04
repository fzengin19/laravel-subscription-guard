<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates core phase one tables', function (): void {
    $tables = [
        'plans',
        'licenses',
        'license_activations',
        'subscriptions',
        'subscription_items',
        'transactions',
        'invoices',
        'payment_methods',
        'webhook_calls',
        'billing_profiles',
        'coupons',
        'discounts',
        'scheduled_plan_changes',
        'license_usages',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Failed asserting table [{$table}] exists.");
    }
});

it('creates core phase one columns in their own base migrations', function (): void {
    $requiredColumns = [
        'plans' => ['name', 'slug', 'price', 'currency', 'billing_period', 'features', 'limits'],
        'licenses' => ['key', 'status', 'expires_at', 'feature_overrides', 'limit_overrides', 'deleted_at'],
        'license_activations' => ['license_id', 'domain', 'ip_address', 'activated_at'],
        'subscriptions' => ['provider', 'provider_subscription_id', 'status', 'next_billing_date', 'deleted_at'],
        'subscription_items' => ['subscription_id', 'plan_id', 'quantity', 'unit_price'],
        'transactions' => ['idempotency_key', 'provider_transaction_id', 'retry_count', 'next_retry_at', 'deleted_at'],
        'invoices' => ['invoice_number', 'status', 'subtotal', 'total_amount', 'deleted_at'],
        'payment_methods' => ['provider_card_token', 'provider_customer_token', 'is_default', 'is_active', 'deleted_at'],
        'webhook_calls' => ['provider', 'event_type', 'event_id', 'status', 'processed_at'],
        'billing_profiles' => ['billable_type', 'billable_id', 'tax_office', 'tax_id', 'deleted_at'],
        'coupons' => ['code', 'type', 'value', 'max_uses', 'is_active'],
        'discounts' => ['coupon_id', 'discountable_type', 'discountable_id', 'duration', 'applied_amount'],
        'scheduled_plan_changes' => ['subscription_id', 'change_type', 'scheduled_at', 'status', 'proration_credit'],
        'license_usages' => ['license_id', 'metric', 'quantity', 'period_start', 'period_end'],
    ];

    foreach ($requiredColumns as $table => $columns) {
        expect(Schema::hasColumns($table, $columns))
            ->toBeTrue("Failed asserting required phase one columns exist on table [{$table}].");
    }
});

it('does not keep a phase one additive migration file', function (): void {
    $path = dirname(__DIR__, 2).'/database/migrations/2026_03_04_085603_add_phase1_columns_to_subguard_tables.php';

    expect(file_exists($path))
        ->toBeFalse('Phase one additive migration should not exist after schema consolidation.');
});
