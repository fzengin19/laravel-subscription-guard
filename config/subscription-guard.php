<?php

return [
    'providers' => [
        'default' => env('SUBGUARD_PROVIDER', 'iyzico'),
        'drivers' => [
            'iyzico' => [
                'class' => \SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider::class,
                'event_dispatcher' => \SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProviderEventDispatcher::class,
                'manages_own_billing' => true,
                'api_key' => env('IYZICO_API_KEY'),
                'secret_key' => env('IYZICO_SECRET_KEY'),
                'merchant_id' => null,
                'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
                'callback_url' => env('IYZICO_CALLBACK_URL'),
                'signature_header' => 'x-iyz-signature-v3',
                'mock' => env('IYZICO_MOCK', true),
            ],
            'paytr' => [
                'class' => \SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider::class,
                'event_dispatcher' => \SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProviderEventDispatcher::class,
                'manages_own_billing' => false,
                'merchant_id' => env('PAYTR_MERCHANT_ID'),
                'merchant_key' => env('PAYTR_MERCHANT_KEY'),
                'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
                'callback_url' => env('PAYTR_CALLBACK_URL'),
                'mock' => env('PAYTR_MOCK', true),
                'webhook_response_format' => 'text',
                'webhook_response_body' => 'OK',
            ],
        ],
    ],

    'webhooks' => [
        'auto_register_routes' => true,
        'prefix' => env('SUBGUARD_WEBHOOK_PREFIX', 'subguard/webhooks'),
        'middleware' => ['api'],
    ],

    'queue' => [
        'connection' => env('SUBGUARD_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue' => env('SUBGUARD_QUEUE', 'subguard-billing'),
        'webhooks_queue' => env('SUBGUARD_WEBHOOKS_QUEUE', 'subguard-webhooks'),
        'notifications_queue' => env('SUBGUARD_NOTIFICATIONS_QUEUE', 'subguard-notifications'),
    ],

    'billing' => [
        'timezone' => env('SUBGUARD_BILLING_TIMEZONE', 'Europe/Istanbul'),
        'grace_period_days' => (int) env('SUBGUARD_GRACE_PERIOD_DAYS', 7),
        'max_dunning_retries' => (int) env('SUBGUARD_MAX_DUNNING_RETRIES', 3),
        'dunning_retry_interval_days' => (int) env('SUBGUARD_DUNNING_RETRY_INTERVAL_DAYS', 2),

        'renewal_command' => 'subguard:process-renewals',
        'dunning_command' => 'subguard:process-dunning',
        'suspend_command' => 'subguard:suspend-overdue',
        'metered_command' => 'subguard:process-metered-billing',
        'plan_changes_command' => 'subguard:process-plan-changes',
        'simulate_webhook_command' => 'subguard:simulate-webhook',
        'sync_plans_command' => 'subguard:sync-plans',
        'reconcile_iyzico_command' => 'subguard:reconcile-iyzico-subscriptions',
        'generate_license_command' => 'subguard:generate-license',
        'check_license_command' => 'subguard:check-license',
        'sync_license_revocations_command' => 'subguard:sync-license-revocations',
        'sync_license_heartbeats_command' => 'subguard:sync-license-heartbeats',
    ],

    'locks' => [
        'webhook_lock_ttl' => (int) env('SUBGUARD_WEBHOOK_LOCK_TTL', 10),
        'webhook_block_timeout' => (int) env('SUBGUARD_WEBHOOK_BLOCK_TIMEOUT', 5),
        'callback_lock_ttl' => (int) env('SUBGUARD_CALLBACK_LOCK_TTL', 10),
        'callback_block_timeout' => (int) env('SUBGUARD_CALLBACK_BLOCK_TIMEOUT', 5),
        'renewal_job_lock_ttl' => (int) env('SUBGUARD_RENEWAL_JOB_LOCK_TTL', 30),
        'dunning_job_lock_ttl' => (int) env('SUBGUARD_DUNNING_JOB_LOCK_TTL', 30),
    ],

    'logging' => [
        'payments_channel' => 'subguard_payments',
        'webhooks_channel' => 'subguard_webhooks',
        'licenses_channel' => 'subguard_licenses',
    ],

    'license' => [
        'algorithm' => 'ed25519',
        'key_id' => env('SUBGUARD_LICENSE_KEY_ID', 'v1'),
        'default_ttl_seconds' => (int) env('SUBGUARD_LICENSE_TTL_SECONDS', 2_592_000),
        'validation_path' => env('SUBGUARD_LICENSE_VALIDATION_PATH', 'subguard/licenses/validate'),
        'auto_register_validation_route' => (bool) env('SUBGUARD_LICENSE_AUTO_REGISTER_VALIDATION_ROUTE', true),
        'keys' => [
            'public' => env('SUBGUARD_LICENSE_PUBLIC_KEY', ''),
            'private' => env('SUBGUARD_LICENSE_PRIVATE_KEY', ''),
        ],
        'offline' => [
            'max_stale_seconds' => (int) env('SUBGUARD_LICENSE_HEARTBEAT_MAX_STALE_SECONDS', 604800),
            'heartbeat_ttl_seconds' => (int) env('SUBGUARD_LICENSE_HEARTBEAT_TTL_SECONDS', 1209600),
            'heartbeat_cache_prefix' => env('SUBGUARD_LICENSE_HEARTBEAT_CACHE_PREFIX', 'subguard:license:heartbeat:'),
            'clock_skew_seconds' => (int) env('SUBGUARD_LICENSE_HEARTBEAT_CLOCK_SKEW_SECONDS', 60),
        ],
        'revocation' => [
            'cache_key' => env('SUBGUARD_LICENSE_REVOCATION_CACHE_KEY', 'subguard:license:revocation'),
            'snapshot_ttl_seconds' => (int) env('SUBGUARD_LICENSE_REVOCATION_SNAPSHOT_TTL_SECONDS', 604800),
            'fail_open_on_expired' => (bool) env('SUBGUARD_LICENSE_REVOCATION_FAIL_OPEN_ON_EXPIRED', false),
            'sync_endpoint' => env('SUBGUARD_LICENSE_REVOCATION_SYNC_ENDPOINT', ''),
            'sync_token' => env('SUBGUARD_LICENSE_REVOCATION_SYNC_TOKEN', ''),
            'sync_timeout_seconds' => (int) env('SUBGUARD_LICENSE_REVOCATION_SYNC_TIMEOUT_SECONDS', 10),
        ],
        'events' => [
            'emit_feature_checked' => false,
        ],
        'rate_limit' => [
            'key' => 'license-validation',
            'max_attempts' => 60,
            'decay_minutes' => 1,
        ],
    ],

    'routes' => [
        'install_portal' => false,
    ],

];
