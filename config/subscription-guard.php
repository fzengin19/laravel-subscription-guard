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
        'renewal_command' => 'subguard:process-renewals',
        'dunning_command' => 'subguard:process-dunning',
        'suspend_command' => 'subguard:suspend-overdue',
        'plan_changes_command' => 'subguard:process-plan-changes',
        'sync_plans_command' => 'subguard:sync-plans',
        'reconcile_iyzico_command' => 'subguard:reconcile-iyzico-subscriptions',
    ],

    'logging' => [
        'payments_channel' => 'subguard_payments',
        'webhooks_channel' => 'subguard_webhooks',
        'licenses_channel' => 'subguard_licenses',
    ],

    'license' => [
        'algorithm' => 'ed25519',
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
