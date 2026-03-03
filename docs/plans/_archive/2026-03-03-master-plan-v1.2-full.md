# Laravel Subscription Guard - Master Plan

> **Versiyon**: 1.2 (Kritik Gerçeklik Güncellemesi)
> **Tarih**: 2026-03-03
> **Durum**: Draft - Review Bekliyor

## Proje Özeti

## Proje Özeti

Laravel ekosistemi için ödeme entegrasyonu ve lisans yönetimini bir arada sunan, modüler ve genişletilebilir bir paket. Türk pazarına özel olarak iyzico ve PayTR desteği ile başlayıp, custom provider eklenebilir yapısıyla global ölçeklenebilirlik.

---

## Hedefler

### Temel Hedefler
1. **Ödeme Sistemi**: iyzico + PayTR ile tam entegre ödeme altyapısı
2. **Lisans Sistemi**: Özelleştirilebilir, modüler lisans yönetimi
3. **Genişletilebilirlik**: Custom provider desteği (ara yüz tabanlı)
4. **Kolay Entegrasyon**: Minimum konfigürasyonla çalışabilir
5. **Production-Ready**: Güvenli, test edilmiş, dokümantasyonlu

### Kalite Hedefleri
- %90+ test coverage
- PSR-12 uyumlu kod standardı
- PHP 8.4+ type safety
- Laravel 11/12 desteği
- Kapsamlı dokümantasyon (TR/EN)

---

## Mimari Prensipler

### 1. Domain-Driven Design
```
src/
├── Payment/          # Ödeme domain'i
├── Licensing/        # Lisans domain'i
├── Subscription/     # Abonelik domain'i
└── Shared/           # Paylaşılan bileşenler
```

### 2. Contract-Based Design
Her domain, interface'ler ile tanımlanır. Implementasyonlar değiştirilebilir.

### 3. Event-Driven Architecture
Ödeme, lisans, abonelik olayları için event'ler. Custom logic için listener'lar.

### 4. Configuration Over Convention
Mevcut Laravel convention'ları takip edilir, davranış config ile özelleştirilir.

---

## Paket Yapısı

```
laravel-subscription-guard/
├── src/
│   ├── LaravelSubscriptionGuardServiceProvider.php
│   ├── LaravelSubscriptionGuard.php (Facade)
│   │
│   ├── Contracts/                    # Interface tanımları
│   │   ├── PaymentProviderInterface.php
│   │   ├── LicenseManagerInterface.php
│   │   ├── SubscriptionServiceInterface.php
│   │   └── FeatureGateInterface.php
│   │
│   ├── Payment/
│   ├── Payment/
│   │   ├── PaymentManager.php        # Laravel Manager pattern + Factory
│   │   ├── PaymentGatewayRegistry.php # Static provider registry
│   │   ├── PaymentResponse.php       # DTO
│   │   ├── PaymentRequest.php        # DTO
│   │   ├── WebhookHandler.php        # Abstract webhook
│   │   ├── PaymentResponse.php       # DTO
│   │   ├── PaymentRequest.php        # DTO
│   │   ├── WebhookHandler.php        # Abstract webhook
│   │   │
│   │   ├── Providers/                # Provider implementasyonları
│   │   │   ├── AbstractProvider.php  # Base class
│   │   │   ├── IyzicoProvider.php
│   │   │   ├── PaytrProvider.php
│   │   │   └── NullProvider.php      # Test/null provider
│   │   │   └── Custom/               # User custom providers dir
│   │   │   └── NullProvider.php      # Test/null provider
│   │   │
│   │   ├── Models/
│   │   │   ├── Transaction.php
│   │   │   └── PaymentMethod.php
│   │   │
│   │   └── Events/
│   │       ├── PaymentInitiated.php
│   │       ├── PaymentCompleted.php
│   │       ├── PaymentFailed.php
│   │       ├── RefundProcessed.php
│   │       └── WebhookReceived.php
│   │
│   ├── Licensing/
│   ├── Licensing/
│   │   ├── LicenseManager.php        # Ana yönetim sınıfı
│   │   ├── LicenseValidator.php      # Online/offline validation
│   │   ├── LicenseGenerator.php      # Ed25519/RSA-2048 crypto
│   │   ├── LicenseSignature.php      # Asymmetric signature ops
│   │   ├── LicenseResponse.php       # DTO
│   │   ├── LicenseValidator.php      # Doğrulama logic
│   │   ├── LicenseGenerator.php      # Key generation
│   │   ├── LicenseResponse.php       # DTO
│   │   │
│   │   ├── Models/
│   │   │   ├── License.php           # Ana license model
│   │   │   ├── LicensePlan.php       # Plan tanımları
│   │   │   ├── LicenseFeature.php    # Feature tanımları
│   │   │   └── LicenseUsage.php      # Kullanım tracking
│   │   │
│   │   ├── Traits/
│   │   │   └── HasLicense.php        # Model trait'i
│   │   │
│   │   ├── Middleware/
│   │   │   ├── CheckLicense.php
│   │   │   ├── CheckFeature.php
│   │   │   └── CheckUsageLimit.php
│   │   │
│   │   └── Events/
│   │       ├── LicenseActivated.php
│   │       ├── LicenseExpired.php
│   │       ├── LicenseRevoked.php
│   │       └── UsageLimitExceeded.php
│   │
│   ├── Subscription/
│   │   ├── SubscriptionManager.php   # Abonelik lifecycle
│   │   ├── SubscriptionBuilder.php   # Fluent builder
│   │   ├── SubscriptionCalculator.php # Fiyatlandırma
│   │   │
│   │   ├── Models/
│   │   │   ├── Subscription.php
│   │   │   ├── SubscriptionItem.php  # Multi-plan desteği
│   │   │   └── SubscriptionPlan.php
│   │   │
│   │   ├── Traits/
│   │   │   └── Billable.php          # User model trait
│   │   │
│   │   ├── Billing/
│   │   │   ├── InvoiceGenerator.php
│   │   │   └── ReceiptGenerator.php
│   │   │
│   │   └── Events/
│   │       ├── SubscriptionCreated.php
│   │       ├── SubscriptionCancelled.php
│   │       ├── SubscriptionRenewed.php
│   │       ├── SubscriptionUpgraded.php
│   │       └── SubscriptionDowngraded.php
│   │
│   ├── Features/
│   │   ├── FeatureManager.php        # Feature gating
│   │   ├── FeatureDefinition.php     # Feature config
│   │   │
│   │   ├── Gates/
│   │   │   ├── BooleanGate.php       # Açık/Kapalı
│   │   │   ├── LimitGate.php         # Limit bazlı
│   │   │   ├── UsageGate.php         # Kullanım bazlı
│   │   │   └── ScheduleGate.php      # Zaman bazlı
│   │   │
│   │   └── Directives/
│   │       ├── @feature blade directive
│   │       └── @featurenot blade directive
│   │
│   ├── Shared/
│   │   ├── DTOs/                     # Data Transfer Objects
│   │   │   ├── Address.php
│   │   │   ├── Customer.php
│   │   │   └── Money.php             # Money pattern
│   │   │
│   │   ├── Enums/
│   │   │   ├── PaymentStatus.php
│   │   │   ├── SubscriptionStatus.php
│   │   │   ├── LicenseStatus.php
│   │   │   └── Currency.php
│   │   │
│   │   ├── Exceptions/
│   │   │   ├── PaymentException.php
│   │   │   ├── LicenseException.php
│   │   │   ├── SubscriptionException.php
│   │   │   └── InvalidConfigurationException.php
│   │   │
│   │   └── Helpers/
│   │       └── functions.php
│   │
│   └── Commands/
│       ├── InstallCommand.php
│       ├── GenerateLicenseCommand.php
│       ├── SyncSubscriptionsCommand.php
│       └── CleanupExpiredCommand.php
│
├── config/
│   └── subscription-guard.php        # Ana konfigürasyon
│
├── database/
│   └── migrations/
│       ├── create_licenses_table.php
│       ├── create_license_plans_table.php
│       ├── create_license_features_table.php
│       ├── create_license_usage_table.php
│       ├── create_subscriptions_table.php
│       ├── create_subscription_items_table.php
│       ├── create_transactions_table.php
│       └── create_payment_methods_table.php
│
├── routes/
│   └── webhooks.php                  # Webhook endpoint'leri
│
├── resources/
│   └── views/
│       ├── receipts/
│       └── invoices/
│
├── tests/
│   ├── Unit/
│   │   ├── Payment/
│   │   ├── Licensing/
│   │   ├── Subscription/
│   │   └── Features/
│   │
│   ├── Integration/
│   │   ├── IyzicoIntegrationTest.php
│   │   └── PaytrIntegrationTest.php
│   │
│   └── Pest.php
│
└── docs/
    ├── installation.md
    ├── configuration.md
    ├── payment-providers.md
    ├── licensing.md
    ├── subscriptions.md
    ├── feature-gating.md
    ├── custom-providers.md
    └── api-reference.md
```

---

## Veritabanı Şeması

### Licenses Tablosu
```sql
CREATE TABLE licenses (
    id BIGINT UNSIGNED PRIMARY KEY,
    key VARCHAR(64) UNIQUE,              -- License key (encrypted)
    hash VARCHAR(128) UNIQUE,            -- Hash for lookup
    user_id BIGINT UNSIGNED,
    plan_id BIGINT UNSIGNED,
    
    status ENUM('active', 'inactive', 'expired', 'suspended', 'revoked'),
    
    -- Metadata
    domain VARCHAR(255),                 -- Domain restriction
    ip_whitelist JSON,                   -- IP restrictions
    custom_data JSON,                    -- Custom metadata
    
    -- Dates
    activated_at TIMESTAMP,
    expires_at TIMESTAMP,
    last_validated_at TIMESTAMP,
    
    -- Limits
    max_activations INT DEFAULT 1,
    current_activations INT DEFAULT 0,
    
    -- Soft deletes (CRITICAL!)
    deleted_at TIMESTAMP NULL,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_plan_id (plan_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),
    INDEX idx_deleted_at (deleted_at)
);
```

### License Plans Tablosu
```sql
CREATE TABLE license_plans (
    id BIGINT UNSIGNED PRIMARY KEY,
    slug VARCHAR(100) UNIQUE,
    name VARCHAR(255),
    description TEXT,
    
    -- Pricing
    price DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'TRY',
    billing_period ENUM('monthly', 'yearly', 'lifetime', 'custom'),
    
    -- Limits
    features JSON,                       -- Feature definitions
    limits JSON,                         -- Usage limits
    
    -- Restrictions
    max_activations INT DEFAULT 1,
    trial_days INT DEFAULT 0,
    
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### License Features Tablosu
```sql
CREATE TABLE license_features (
    id BIGINT UNSIGNED PRIMARY KEY,
    plan_id BIGINT UNSIGNED,
    
    key VARCHAR(100),                    -- Feature identifier
    type ENUM('boolean', 'limit', 'usage', 'schedule'),
    value JSON,                          -- Feature value/config
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    UNIQUE KEY unique_plan_feature (plan_id, key),
    FOREIGN KEY (plan_id) REFERENCES license_plans(id)
);
```

### License Usage Tablosu
```sql
CREATE TABLE license_usage (
    id BIGINT UNSIGNED PRIMARY KEY,
    license_id BIGINT UNSIGNED,
    feature_key VARCHAR(100),
    
    used INT DEFAULT 0,
    limit INT,
    period_start DATE,
    period_end DATE,
    
    reset_at TIMESTAMP,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    UNIQUE KEY unique_license_feature_period (license_id, feature_key, period_start),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
);
```

### Subscriptions Tablosu
```sql
CREATE TABLE subscriptions (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    -- Polymorphic relation (User, Team, Company support)
    subscribable_type VARCHAR(255),
    subscribable_id BIGINT UNSIGNED,
    
    license_id BIGINT UNSIGNED NULLABLE,
    
    -- Provider info
    provider VARCHAR(50),                -- iyzico, paytr, custom
    provider_subscription_id VARCHAR(255),
    provider_customer_id VARCHAR(255),
    
    -- Plan info
    plan_id BIGINT UNSIGNED NULLABLE,
    
    status ENUM('pending', 'active', 'past_due', 'suspended', 'grace_period', 'cancelled', 'expired', 'trialing'),
    
    -- Billing cycle
    billing_period ENUM('monthly', 'yearly', 'weekly', 'custom'),
    billing_interval INT DEFAULT 1,
    
    -- Amounts (TAX INCLUDED!)
    amount DECIMAL(10,2),               -- Total amount including tax
    tax_amount DECIMAL(10,2) DEFAULT 0, -- KDV amount
    tax_rate DECIMAL(5,2) DEFAULT 18.00,-- KDV rate (%)
    currency VARCHAR(3) DEFAULT 'TRY',
    
    -- Trial
    trial_ends_at TIMESTAMP NULL,
    
    -- Dates
    starts_at TIMESTAMP,
    current_period_start TIMESTAMP,
    current_period_end TIMESTAMP,
    grace_ends_at TIMESTAMP NULL,       -- Grace period end (for failed payments)
    cancels_at TIMESTAMP NULL,          -- Scheduled cancellation date
    ends_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    
    -- Scheduled plan changes (for non-proration providers)
    scheduled_change_id BIGINT UNSIGNED NULLABLE, -- FK to scheduled_plan_changes
    
    -- Metadata
    metadata JSON,
    
    -- Soft deletes (CRITICAL for financial records!)
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_subscribable (subscribable_type, subscribable_id),
    INDEX idx_status (status),
    INDEX idx_provider_subscription (provider, provider_subscription_id),
    INDEX idx_grace_ends_at (grace_ends_at),
    INDEX idx_deleted_at (deleted_at)
);
```

### Subscription Items Tablosu (Multi-plan desteği)
```sql
CREATE TABLE subscription_items (
    id BIGINT UNSIGNED PRIMARY KEY,
    subscription_id BIGINT UNSIGNED,
    
    provider_item_id VARCHAR(255),
    plan_id BIGINT UNSIGNED,
    
    quantity INT DEFAULT 1,
    amount DECIMAL(10,2),
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
);
```

### Transactions Tablosu
```sql
CREATE TABLE transactions (
    id BIGINT UNSIGNED PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NULLABLE,
    
    -- Polymorphic relation
    payable_type VARCHAR(255),
    payable_id BIGINT UNSIGNED,
    
    license_id BIGINT UNSIGNED NULLABLE,
    
    -- Provider info
    provider VARCHAR(50),
    provider_transaction_id VARCHAR(255),
    provider_refund_id VARCHAR(255) NULLABLE,
    
    -- Idempotency (CRITICAL for webhook deduplication!)
    idempotency_key VARCHAR(255) UNIQUE,    -- paymentId or iyziReferenceCode from webhooks
    
    type ENUM('payment', 'refund', 'chargeback'),
    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded'),
    
    -- Amounts (with TAX!)
    amount DECIMAL(10,2),                  -- Total amount
    tax_amount DECIMAL(10,2) DEFAULT 0,    -- KDV amount
    tax_rate DECIMAL(5,2) DEFAULT 18.00,   -- KDV rate (%)
    discount_amount DECIMAL(10,2) DEFAULT 0,
    refunded_amount DECIMAL(10,2) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'TRY',
    fee DECIMAL(10,2) DEFAULT 0,
    
    -- Payment details
    payment_method ENUM('card', 'bank_transfer', 'wallet', 'other'),
    card_last_four VARCHAR(4),
    card_brand VARCHAR(50),
    installment INT DEFAULT 1,
    
    -- Coupon/Discount
    coupon_id BIGINT UNSIGNED NULLABLE,
    discount_id BIGINT UNSIGNED NULLABLE,
    
    -- Response data
    provider_response JSON,
    failure_reason TEXT NULLABLE,
    
    -- Metadata
    description TEXT,
    metadata JSON,
    
    -- Timestamps
    processed_at TIMESTAMP NULLABLE,
    refunded_at TIMESTAMP NULLABLE,
    
    -- Soft deletes
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_subscription_id (subscription_id),
    INDEX idx_payable (payable_type, payable_id),
    INDEX idx_provider_transaction (provider, provider_transaction_id),
    INDEX idx_idempotency_key (idempotency_key),
    INDEX idx_status (status)
);

### Payment Methods Tablosu
```sql
CREATE TABLE payment_methods (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    -- Polymorphic relation
    payable_type VARCHAR(255),
    payable_id BIGINT UNSIGNED,
    
    provider VARCHAR(50),
    provider_method_id VARCHAR(255),
    
    -- CARD TOKENS (CRITICAL for recurring payments!)
    provider_card_token VARCHAR(255),      -- iyzico: cardToken, PayTR: ctoken
    provider_customer_token VARCHAR(255),  -- iyzico: cardUserKey, PayTR: utoken
    
    type ENUM('card', 'bank_account', 'wallet'),
    
    -- Card info (masked)
    card_last_four VARCHAR(4),
    card_brand VARCHAR(50),
    card_expiry VARCHAR(7),
    card_holder_name VARCHAR(255),
    
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    
    metadata JSON,
    
    -- Soft deletes
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_payable (payable_type, payable_id),
    INDEX idx_provider_tokens (provider, provider_card_token, provider_customer_token)
);
```

### Webhook Calls Tablosu (Idempotency)
```sql
CREATE TABLE webhook_calls (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    provider VARCHAR(50),                 -- iyzico, paytr
    event_type VARCHAR(100),              -- iyziEventType, payment.success etc.
    event_id VARCHAR(255),                -- Unique event ID from provider
    
    payload JSON,                         -- Full webhook payload
    headers JSON,                         -- Webhook headers (for signature verification)
    
    status ENUM('pending', 'processed', 'failed', 'ignored'),
    processed_at TIMESTAMP NULLABLE,
    error_message TEXT NULLABLE,
    
    -- Idempotency
    idempotency_key VARCHAR(255),         -- For deduplication
    
    -- Reference to related entities (nullable, filled after processing)
    transaction_id BIGINT UNSIGNED NULLABLE,
    subscription_id BIGINT UNSIGNED NULLABLE,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    UNIQUE KEY unique_provider_event (provider, event_id),
    INDEX idx_status (status),
    INDEX idx_processed_at (processed_at)
);
```

### Coupons Tablosu
```sql
CREATE TABLE coupons (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    code VARCHAR(50) UNIQUE,              -- Coupon code (e.g., SUMMER2026)
    name VARCHAR(255),
    description TEXT NULLABLE,
    
    -- Discount type
    type ENUM('percentage', 'fixed_amount', 'free_trial'),
    value DECIMAL(10,2),                  -- Percentage (e.g., 20.00 = 20%) or fixed amount
    currency VARCHAR(3) DEFAULT 'TRY',
    
    -- Restrictions
    min_purchase_amount DECIMAL(10,2) NULLABLE,
    max_discount_amount DECIMAL(10,2) NULLABLE,
    
    -- Usage limits
    max_uses INT NULLABLE,
    max_uses_per_user INT DEFAULT 1,
    current_uses INT DEFAULT 0,
    
    -- Applicability
    applies_to ENUM('all', 'first_payment', 'all_payments'),
    plan_ids JSON NULLABLE,               -- Array of plan IDs this coupon applies to
    
    -- Validity
    starts_at TIMESTAMP NULLABLE,
    expires_at TIMESTAMP NULLABLE,
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_code (code),
    INDEX idx_active (is_active, starts_at, expires_at)
);
```

### Discounts Tablosu
```sql
CREATE TABLE discounts (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    coupon_id BIGINT UNSIGNED NULLABLE,   -- If discount from coupon
    
    -- Polymorphic relation
    discountable_type VARCHAR(255),       -- Subscription, Transaction, License
    discountable_id BIGINT UNSIGNED,
    
    -- Discount details
    type ENUM('percentage', 'fixed_amount', 'free_trial', 'credit'),
    value DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'TRY',
    
    -- Tracking
    applied_amount DECIMAL(10,2),         -- Actual discount amount applied
    description TEXT NULLABLE,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_discountable (discountable_type, discountable_id),
    INDEX idx_coupon (coupon_id)
);
```

### Scheduled Plan Changes Tablosu (Non-Proration)
```sql
CREATE TABLE scheduled_plan_changes (
    id BIGINT UNSIGNED PRIMARY KEY,
    
    subscription_id BIGINT UNSIGNED,
    
    from_plan_id BIGINT UNSIGNED,
    to_plan_id BIGINT UNSIGNED,
    
    -- Change details
    change_type ENUM('upgrade', 'downgrade', 'switch'),
    scheduled_at TIMESTAMP,               -- When to execute the change
    
    -- Proration strategy (manual calculation for non-proration providers)
    proration_type ENUM('none', 'credit', 'immediate_charge'),
    proration_credit DECIMAL(10,2) NULLABLE, -- Credit amount to apply
    
    status ENUM('pending', 'processing', 'completed', 'cancelled', 'failed'),
    processed_at TIMESTAMP NULLABLE,
    error_message TEXT NULLABLE,
    
    -- Metadata
    metadata JSON,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_subscription (subscription_id),
    INDEX idx_scheduled_at (scheduled_at, status),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
);
```

---

## Konfigürasyon Yapısı

```php
// config/subscription-guard.php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUBSCRIPTION_GUARD_PROVIDER', 'iyzico'),
    
    /*
    |--------------------------------------------------------------------------
    | Payment Providers Configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'iyzico' => [
            'driver' => \SubscriptionGuard\Payment\Providers\IyzicoProvider::class,
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
            'webhook_key' => env('IYZICO_WEBHOOK_KEY'),
            'callback_url' => env('APP_URL') . '/webhooks/iyzico',
            // IYZWSv2 Auth: HMAC-SHA256 with format 'IYZWSv2 {base64_hash}'
            // Key = randomString + uriPath + requestBody hashed with secretKey
            'auth_version' => 'IYZWSv2',
        ],
        ],
        
        'paytr' => [
            'driver' => \SubscriptionGuard\Payment\Providers\PaytrProvider::class,
            'merchant_id' => env('PAYTR_MERCHANT_ID'),
            'merchant_key' => env('PAYTR_MERCHANT_KEY'),
            'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
            'merchant_ok_url' => env('APP_URL') . '/payment/success',
            'merchant_fail_url' => env('APP_URL') . '/payment/fail',
            'webhook_url' => env('APP_URL') . '/webhooks/paytr',
            'test_mode' => env('PAYTR_TEST_MODE', true),
        ],
        
        'null' => [
        
        // Custom provider registration example:
        // 'my_custom' => [
        //     'driver' => \App\Payment\MyCustomProvider::class,
        //     'api_key' => env('CUSTOM_API_KEY'),
        //     // ... custom config
        // ],
            'driver' => \SubscriptionGuard\Payment\Providers\NullProvider::class,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | License Configuration
    |--------------------------------------------------------------------------
    */
    'license' => [
        // Key generation - Asymmetric crypto (Ed25519 veya RSA-2048)
        // Format: ${ENCODED_DATA}.${ENCODED_SIGNATURE}
        // NOT: PKV (Partial Key Verification) güvenli değil, kullanma!
        'crypto_algorithm' => env('LICENSE_CRYPTO', 'ed25519'), // ed25519 | rsa2048
        'private_key' => env('LICENSE_PRIVATE_KEY'), // Signing key (gizli)
        'public_key' => env('LICENSE_PUBLIC_KEY'),   // Verification key (herkese açık)
        'key_prefix' => env('LICENSE_KEY_PREFIX', 'SG'),
        'key_format' => 'XXXX-XXXX-XXXX-XXXX',
        
        // Validation
        'validation_cache_ttl' => 3600, // 1 hour
        'offline_validation' => true,   // Signature verification without API call
        'online_validation_endpoint' => env('LICENSE_API_ENDPOINT'), // Optional
        
        // Security
        'rate_limit_per_minute' => 60,  // Validation rate limit
        'log_validation_attempts' => true,
        
        // Restrictions
        'enforce_domain' => true,
        'enforce_ip' => false,
        'max_activation_attempts' => 5,
        'activation_lockout_duration' => 900, // 15 minutes
    ],
        // Key generation
        'key_prefix' => env('LICENSE_KEY_PREFIX', 'SG'),
        'key_length' => 32,
        'key_format' => 'XXXX-XXXX-XXXX-XXXX', // Format pattern
        
        // Validation
        'validation_cache_ttl' => 3600, // 1 hour
        'offline_validation' => true,
        
        // Security
        'encryption_key' => env('LICENSE_ENCRYPTION_KEY'),
        'hash_algorithm' => 'sha256',
        
        // Restrictions
        'enforce_domain' => true,
        'enforce_ip' => false,
        'max_activation_attempts' => 5,
        'activation_lockout_duration' => 900, // 15 minutes
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Subscription Configuration
    |--------------------------------------------------------------------------
    */
    'subscription' => [
        // Grace period (days after expiration)
        'grace_period' => 3,
        
        // Trial
        'default_trial_days' => 14,
        
        // Proration
        'prorate_on_change' => true,
        
        // Invoices
        'invoice_prefix' => 'INV',
        'generate_invoices' => true,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Feature Gating Configuration
    |--------------------------------------------------------------------------
    */
    'features' => [
        // Default behavior when feature not defined
        'default_access' => false,
        
        // Cache TTL for feature checks
        'cache_ttl' => 300, // 5 minutes
        
        // Feature definitions (can also be in database)
        'definitions' => [
            // 'feature_key' => [
            //     'type' => 'boolean|limit|usage',
            //     'default' => false|0,
            //     'reset_period' => 'daily|weekly|monthly|never',
            // ],
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    */
    'tables' => [
        'licenses' => 'licenses',
        'license_plans' => 'license_plans',
        'license_features' => 'license_features',
        'license_usage' => 'license_usage',
        'subscriptions' => 'subscriptions',
        'subscription_items' => 'subscription_items',
        'transactions' => 'transactions',
        'payment_methods' => 'payment_methods',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user' => \App\Models\User::class,
        'license' => \SubscriptionGuard\Licensing\Models\License::class,
        'license_plan' => \SubscriptionGuard\Licensing\Models\LicensePlan::class,
        'subscription' => \SubscriptionGuard\Subscription\Models\Subscription::class,
        'transaction' => \SubscriptionGuard\Payment\Models\Transaction::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'webhooks_enabled' => true,
        'webhooks_prefix' => 'webhooks',
        'middleware' => ['web'],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('SUBSCRIPTION_QUEUE_CONNECTION', 'database'),
        'webhook_processing' => 'webhooks',
        'invoice_generation' => 'invoices',
    ],
];
```

---

## Faz Planlaması

### Faz 0: Altyapı ve Temel Yapı (2 Hafta)
- Proje yapısının oluşturulması
- Service provider setup
- Configuration sistemi
- Base interface'ler ve contract'lar
- Shared utilities (DTOs, Enums, Exceptions)
- Migration'lar
- Test altyapısı

### Faz 1: Ödeme Sistemi - Temel (3 Hafta)
- PaymentProviderInterface tasarımı
- AbstractProvider base class
- PaymentManager (Factory)
- PaymentRequest/PaymentResponse DTOs
- Transaction model
- Temel ödeme akışı
- Webhook handler base

### Faz 2: Ödeme Sistemi - iyzico (2 Hafta)
- IyzicoProvider implementasyonu
- iyzico SDK entegrasyonu
- 3D Secure desteği
- iyzico webhook handling
- Installment desteği
- iyzico-specific testler

### Faz 3: Ödeme Sistemi - PayTR (2 Hafta)
- PaytrProvider implementasyonu
- PayTR API entegrasyonu
- iFrame checkout
- PayTR notification handling
- Installment desteği
- PayTR-specific testler

### Faz 4: Ödeme Sistemi - Gelişmiş (2 Hafta)
- Refund sistemi
- Payment method management
- Invoice generation
- Receipt generation
- Custom provider documentation
- Payment events ve listeners

### Faz 5: Lisans Sistemi - Temel (3 Hafta)
- LicenseManager tasarımı
- LicenseGenerator (key generation)
- LicenseValidator
- License model ve relationships
- LicensePlan model
- LicenseFeature model
- License middleware'leri

### Faz 6: Lisans Sistemi - Gelişmiş (2 Hafta)
- Domain/IP restrictions
- Usage tracking sistemi
- LicenseUsage model
- Usage limit enforcement
- License activation/deactivation
- License events

### Faz 7: Abonelik Sistemi (3 Hafta)
- SubscriptionManager
- SubscriptionBuilder
- Billable trait
- Subscription model
- SubscriptionItem model (multi-plan)
- Subscription lifecycle
- Proration logic
- Trial management

### Faz 8: Feature Gating (2 Hafta)
- FeatureManager
- Feature gates (Boolean, Limit, Usage, Schedule)
- Blade directives (@feature, @featurenot)
- Feature middleware
- Feature caching

### Faz 9: Entegrasyon ve API (2 Hafta)
- Subscription <-> License entegrasyonu
- Subscription <-> Payment entegrasyonu
- API endpoints (opsiyonel)
- Controller helpers
- Integration tests

### Faz 10: Dokümantasyon ve Polish (2 Hafta)
- Installation guide
- Configuration guide
- Payment providers guide
- Licensing guide
- Subscriptions guide
- Feature gating guide
- Custom provider guide
- API reference
- Code examples
- Demo uygulaması

### Faz 11: Test ve QA (2 Hafta)
- Unit test coverage > %90
- Integration tests
- Pest test suites
- PHPStan level 8
- Laravel Pint formatting
- Security audit
- Performance testing

### Faz 12: Release Hazırlığı (1 Hafta)
- Packagist publish
- README ve CHANGELOG
- Version tagging
- Release notes
- Demo site (opsiyonel)

---

## Önceliklendirme ve Bağımlılıklar

```
Faz 0 (Altyapı)
    │
    ├──► Faz 1 (Ödeme Temel)
    │       │
    │       ├──► Faz 2 (iyzico)
    │       │
    │       └──► Faz 3 (PayTR)
    │               │
    │               └──► Faz 4 (Ödeme Gelişmiş)
    │
    ├──► Faz 5 (Lisans Temel)
    │       │
    │       └──► Faz 6 (Lisans Gelişmiş)
    │
    └──► Faz 7 (Abonelik)
            │
            ├──► Faz 8 (Feature Gating)
            │
            └──► Faz 9 (Entegrasyon)
                    │
                    └──► Faz 10-12 (Docs, Test, Release)
```

---

## Teknoloji Stack

### Gerekli Paketler
```json
{
    "require": {
        "php": "^8.4",
        "illuminate/support": "^11.0|^12.0",
        "illuminate/database": "^11.0|^12.0",
        "spatie/laravel-package-tools": "^1.16",
        "iyzico/iyzipay-php": "^2.0",
        "nesbot/carbon": "^3.0"
    },
    "require-dev": {
        "laravel/pint": "^1.13",
        "nunomaduro/collision": "^8.0",
        "nunomaduro/larastan": "^2.0",
        "orchestra/testbench": "^9.0",
        "pestphp/pest": "^3.0",
        "pestphp/pest-plugin-arch": "^3.0",
        "pestphp/pest-plugin-laravel": "^3.0",
        "phpstan/extension-installer": "^1.3"
    }
}
```

### Önerilen Paketler (Kullanıcı tarafı)
```json
{
    "suggest": {
        "spatie/laravel-event-sourcing": "For event-sourced license tracking",
        "spatie/laravel-query-builder": "For API filtering",
        "maatwebsite/excel": "For license export/import"
    }
}
```

---

## Güvenlik Önlemleri

### Ödeme Güvenliği
1. Webhook signature verification (her provider için)
2. Idempotency key desteği (duplicate payments önleme)
3. HTTPS zorunluluğu
4. Sensitive data encryption (card info asla kaydedilmez)
5. PCI-DSS compliance (tokenization kullanımı)

### Lisans Güvenliği
1. License key encryption at rest
2. Hash-based lookup (raw key aranmaz)
3. Rate limiting on validation
4. Domain/IP validation
5. Activation attempt limiting
6. Secure random key generation

### Genel Güvenlik
1. Input validation ve sanitization
2. SQL injection prevention (Eloquent ORM)
3. CSRF protection on forms
4. Secure session handling
5. Error message sanitization

---

## Test Stratejisi

### Unit Tests
- Her class için unit test
- Provider mock'ları
- DTO validation tests
- Model relationship tests
- Middleware tests

### Integration Tests
- Full payment flow tests
- License lifecycle tests
- Subscription workflow tests
- Feature gating tests
- Webhook handling tests

### Feature Tests
- HTTP endpoint tests
- Middleware integration
- Event/listener flow
- Queue job tests

### Provider Tests
- Mock HTTP responses
- Sandbox mode tests
- Error scenario tests
- Webhook simulation

---

## Performans Optimizasyonları

### Caching
- License validation cache
- Feature check cache
- Plan definitions cache
- Provider configurations cache

### Database
- Proper indexing (schema'da belirtilmiş)
- Query optimization
- Eager loading relationships
- Batch operations for bulk updates

### Queue
- Webhook processing async
- Invoice generation async
- Usage tracking async
- Notification sending async

---

## Dokümantasyon Planı

### Kullanıcı Dokümantasyonu
1. **Getting Started**
   - Installation
   - Configuration
   - Quick Start Guide

2. **Payment System**
   - Provider Setup (iyzico, PayTR)
   - Processing Payments
   - Handling Webhooks
   - Refunds
   - Custom Providers

3. **Licensing System**
   - License Management
   - Plan Configuration
   - Feature Definition
   - Usage Tracking
   - API Integration

4. **Subscription System**
   - Subscription Management
   - Billing Cycles
   - Trials
   - Upgrades/Downgrades
   - Invoice Generation

5. **Feature Gating**
   - Feature Definitions
   - Blade Directives
   - Middleware Usage
   - Usage Limits

6. **Advanced Topics**
   - Events & Listeners
   - Custom Models
   - Multi-tenancy
   - Self-hosted Options

### Developer Dokümantasyonu
1. Architecture Overview
2. Contributing Guide
3. Testing Guide
4. API Reference
5. Changelog

---

## Başarı Kriterleri

### Teknik Kriterler
- [ ] PHPStan level 8 geçiyor
- [ ] Laravel Pint formatlı
- [ ] %90+ test coverage
- [ ] Zero critical security vulnerabilities
- [ ] PSR-12 compliant

### Fonksiyonel Kriterler
- [ ] iyzico ile tam ödeme akışı çalışıyor
- [ ] PayTR ile tam ödeme akışı çalışıyor
- [ ] License generation/validation çalışıyor
- [ ] Subscription lifecycle yönetimi çalışıyor
- [ ] Feature gating çalışıyor
- [ ] Custom provider eklenebiliyor

### Kullanıcı Deneyimi Kriterleri
- [ ] 5 dakikada kurulum
- [ ] Net hata mesajları
- [ ] Kapsamlı dokümantasyon
- [ ] Örnek kodlar
- [ ] TR/EN dil desteği

---

## Riskler ve Azaltma Stratejileri

### Risk 1: Provider API Değişiklikleri
- **Risk**: iyzico/PayTR API breaking changes
- **Azaltma**: Version-locked SDK kullanımı, adapter pattern

### Risk 2: Güvenlik Açıkları
- **Risk**: Ödeme veya lisans sisteminde güvenlik açığı
- **Azaltma**: Regular security audit, penetration testing

### Risk 3: Performans Sorunları
- **Risk**: Yüksek trafikte yavaşlama
- **Azaltma**: Caching, queue processing, database optimization

### Risk 4: Dokümantasyon Yetersizliği
- **Risk**: Kullanıcılar paketi kullanamıyor
- **Azaltma**: Comprehensive docs, examples, demo app

### Risk 5: Provider Downtime
- **Risk**: iyzico/PayTR servis kesintisi
- **Azaltma**: Retry logic, fallback providers, status monitoring

---

## Gelecek Özellikler (Post-MVP)

### Faz 13+: Gelecek Geliştirmeler
- Stripe entegrasyonu
- PayPal entegrasyonu
- Multi-currency support
- Tax calculation (KDV, etc.)
- Affiliate/commission system
- Marketplace support (PayTR split payments)
- White-label licensing
- SaaS metrics dashboard
- Webhook retry dashboard
- License analytics
- A/B testing for pricing

---

## Zaman Çizelgesi

| Faz | Süre | Başlangıç | Bitiş |
|-----|------|-----------|-------|
| Faz 0 | 2 hafta | - | - |
| Faz 1 | 3 hafta | - | - |
| Faz 2 | 2 hafta | - | - |
| Faz 3 | 2 hafta | - | - |
| Faz 4 | 2 hafta | - | - |
| Faz 5 | 3 hafta | - | - |
| Faz 6 | 2 hafta | - | - |
| Faz 7 | 3 hafta | - | - |
| Faz 8 | 2 hafta | - | - |
| Faz 9 | 2 hafta | - | - |
| Faz 10 | 2 hafta | - | - |
| Faz 11 | 2 hafta | - | - |
| Faz 12 | 1 hafta | - | - |
| **TOPLAM** | **28 hafta** | | |

---

## Sonraki Adımlar

1. ✅ Master plan review ve onay
2. ⬜ Faz 0 detaylı plan yazımı
3. ⬜ Faz 1 detaylı plan yazımı
4. ⬜ Faz 2 detaylı plan yazımı
5. ⬜ Faz 5 detaylı plan yazımı
6. ⬜ İmplementasyona başlama

---

**Doküman Versiyonu**: 1.1
**Son Güncelleme**: 2026-03-03
**Durum**: Draft - Review Bekliyor

---

## Ek: Araştırma Kaynakları ve Teknik Notlar

### iyzico API Detayları

**Authentication (IYZWSv2):**
```
Authorization: IYZWSv2 {base64_hash}

Hash = HMAC-SHA256(
    randomString + uriPath + requestBody,
    secretKey
)
```

**Webhook Signature Verification:**
```php
// X-Iyz-Signature-V3 header verification
$signature = hash_hmac('sha256', 
    $secretKey . $eventType . $paymentId . $conversationId . $status,
    $secretKey
);
```

**Önemli Notlar:**
- Price hashing için trailing zero removal: `'10.00' → '10'`
- Required objects: PaymentCard, Buyer, Address, BasketItem[]
- Buyer object requires: IP, identityNumber, full address
- Subscription API v2: `/v2/subscription/*`
- Subscription STATUS values: ACTIVE, PENDING, UNPAID, UPGRADED, CANCELED, EXPIRED

### PayTR API Detayları

**Authentication:**
- merchant_id + merchant_key + merchant_salt
- Hash-based signature with merchant_salt

**Payment Flow:**
- iFrame API for embedded checkout
- Notification URL (bildirim) for callbacks

**Desteklenen Özellikler:**
- One-time payments
- Installment payments
- Recurring subscriptions
- Marketplace/Split payments (PayTR'e özel)

### Lisanslama Crypto Seçenekleri

**Ed25519 (Önerilen):**
- Daha hızlı
- Daha kısa key'ler
- Modern algorithm
- PHP: `sodium_crypto_sign_*` functions

**RSA-2048:**
- Daha yaygın destek
- Legacy compatibility
- PHP: `openssl_*` functions

**⚠️ Güvenlik Uyarısı:**
- PKV (Partial Key Verification) kullanma - güvenli değil!
- Online validation for SaaS, offline validation with embedded signature
- Entitlements stored as JSON array in license model

### Feature Gating ile Laravel Pennant Entegrasyonu

```php
// Optional integration with Laravel Pennant
use Laravel\Pennant\Feature;

Feature::define('advanced-export', function (User $user) {
    return $user->license?->hasFeature('advanced-export') ?? false;
});

Feature::define('api-calls-limit', function (User $user) {
    return $user->license?->getLimit('api-calls') ?? 1000;
});
```

### Payment Provider Extension Pattern

**Custom Provider Oluşturma:**

1. `PaymentProviderInterface` implement et
2. `AbstractProvider` extend et (optional)
3. `PaymentGatewayRegistry::register('my-provider', MyProvider::class)`
4. Config'e ekle

```php
// AppServiceProvider.php
public function boot()
{
    PaymentGatewayRegistry::register('my-provider', MyCustomProvider::class);
}

// config/subscription-guard.php
'providers' => [
    'my-provider' => [
        'driver' => \App\Payment\MyCustomProvider::class,
        'api_key' => env('MY_PROVIDER_KEY'),
    ],
],
```

---

---

## Kritik Gerçekler ve Çözümler

### 1. Proration (Kıstelyevm) Gerçekliği

**Sorun**: iyzico ve PayTR, Stripe gibi otomatik proration desteklemiyor. Bir kullanıcı 100 TL'lik paketten 300 TL'lik pakete geçerken aradaki gün farkını hesaplayıp karttan anında çekmek BDDK kuralları gereği zordur.

**iyzico Upgrade API**:
- `POST /v2/subscription/subscriptions/{referenceCode}/upgrade`
- `upgradePeriod`: 'NOW' veya 'NEXT_PERIOD'
- **NOW**: Hemen yeni fiyattan charge (karttan anında çekim)
- **NEXT_PERIOD**: Sonraki ödeme döneminden itibaren yeni fiyat
- **KRİTİK**: Yeni plan AYNI ürün ve AYNI ödeme aralığına sahip olmalı!

**Çözüm Stratejileri**:

1. **Upgrade için**:
   - Kullanıcı upgrade isteği → Sistem unused days credit hesapla
   - `upgradePeriod = NOW` ile iyzico'ya git → Tam yeni fiyat charge
   - Local DB'de credit kaydet → Sonraki faturalara uygula
   - `scheduled_plan_changes` tablosunda log tut

2. **Downgrade için**:
   - `scheduled_plan_changes` tablosuna kaydet
   - `scheduled_at = current_period_end`
   - Period sonunda: Eski aboneliği iptal et → Yeni abonelik oluştur
   - Kullanıcıya notification gönder

3. **Hybrid Approach (Önerilen)**:
   ```php
   // Upgrade
   if ($upgrade) {
       $credit = calculateUnusedCredit($subscription);
       $subscription->upgrade($newPlan, [
           'upgrade_period' => 'NOW',
           'credit' => $credit,
       ]);
   }
   
   // Downgrade
   if ($downgrade) {
       ScheduledPlanChange::create([
           'subscription_id' => $subscription->id,
           'from_plan_id' => $currentPlan->id,
           'to_plan_id' => $newPlan->id,
           'change_type' => 'downgrade',
           'scheduled_at' => $subscription->current_period_end,
           'proration_type' => 'none',
       ]);
   }
   ```

### 2. Kart Saklama Token'ları

**Sorun**: payment_methods tablosunda `card_last_four` var ama asıl sihir olan `provider_card_token` ve `provider_customer_token` yok.

**iyzico Card Storage**:
- SDK: `\Iyzipay\Model\CardStorage::create($request, $options)`
- Returns: `cardUserKey` + `cardToken`
- PaymentCard objesi: `setRegisteredCard(1)` ile kaydet

**PayTR Card Storage (CAPI)**:
- `utoken` (user token) + `ctoken` (card token)
- Payment'da: `store_card=1` parametresi
- **KRİTİK**: Recurring payments için Non3D permission gerekli! (BDDK rule)

**Çözüm**: `payment_methods` tablosuna eklendi:
```sql
provider_card_token VARCHAR(255),      -- iyzico: cardToken, PayTR: ctoken
provider_customer_token VARCHAR(255),  -- iyzico: cardUserKey, PayTR: utoken
```

**Kullanım**:
```php
// Recurring payment with saved card
$paymentMethod = $user->defaultPaymentMethod();

if ($provider === 'iyzico') {
    $request->setCardUserKey($paymentMethod->provider_customer_token);
    $request->setCardToken($paymentMethod->provider_card_token);
}

if ($provider === 'paytr') {
    $request->setUtoken($paymentMethod->provider_customer_token);
    $request->setCtoken($paymentMethod->provider_card_token);
    $request->setNon3d(1); // Requires permission!
}
```

### 3. PayTR iFrame Mantığı

**Sorun**: PayTR direkt API değil, iFrame token üretip frontend'e basmanı ister. PaymentResponse DTO ne dönmeli?

**PayTR iFrame Flow**:
1. Backend: POST `https://www.paytr.com/odeme/api/get-token` → returns `iframe_token`
2. Frontend: Display iFrame with token
   ```html
   <iframe src="https://www.paytr.com/odeme/guvenli/{$iframe_token}" 
           id="paytriframe" 
           frameborder="0" 
           scrolling="no">
   </iframe>
   ```
3. PayTR: POST to callback URL (merchant_fail_url or merchant_ok_url)

**PaymentResponse DTO**:
```php
class PaymentResponse
{
    public bool $success;
    public string $transactionId;
    public ?string $redirectUrl;      // For redirect-based providers
    public ?string $iframeToken;       // For PayTR iFrame
    public ?string $iframeUrl;         // Full iFrame URL
    public ?string $htmlContent;       // For embedded checkout forms
    public array $providerResponse;
}
```

**Controller Usage**:
```php
public function checkout(Request $request)
{
    $response = PaymentManager::driver('paytr')->pay($amount, $details);
    
    if ($response->iframeToken) {
        // PayTR iFrame
        return view('checkout.paytr-iframe', [
            'iframeUrl' => $response->iframeUrl,
        ]);
    }
    
    if ($response->redirectUrl) {
        // iyzico 3DS or redirect
        return redirect($response->redirectUrl);
    }
}
```

### 4. Webhook Idempotency

**Sorun**: iyzico ve PayTR aynı webhook'u 3-4 kez gönderebilir. Sistemin buna hazır olması lazım.

**iyzico Webhook**:
- 15 saniye içinde 200 OK alana kadar retry
- Her 10 dk'da bir, max 3 kez
- Payload: `paymentId`, `iyziReferenceCode`

**PayTR Webhook**:
- merchant_ok_url / merchant_fail_url
- Response must be plain text 'OK'

**Çözüm**: `webhook_calls` tablosu eklendi:
```sql
CREATE TABLE webhook_calls (
    provider VARCHAR(50),
    event_id VARCHAR(255),              -- Unique event ID
    idempotency_key VARCHAR(255),       -- For deduplication
    status ENUM('pending', 'processed', 'failed', 'ignored'),
    payload JSON,
    processed_at TIMESTAMP NULLABLE,
    UNIQUE KEY unique_provider_event (provider, event_id)
);
```

**Webhook Handler**:
```php
public function handleWebhook(Request $request, string $provider)
{
    $eventId = $provider === 'iyzico' 
        ? $request->input('paymentId')
        : $request->input('merchant_oid');
    
    // Idempotency check
    $webhookCall = WebhookCall::firstOrCreate(
        ['provider' => $provider, 'event_id' => $eventId],
        ['payload' => $request->all(), 'status' => 'pending']
    );
    
    if ($webhookCall->status === 'processed') {
        return response('OK', 200); // Already processed
    }
    
    DB::transaction(function () use ($webhookCall) {
        // Process webhook...
        $webhookCall->update(['status' => 'processed', 'processed_at' => now()]);
    });
    
    return response('OK', 200);
}
```

### 5. Grace Period vs Hard Suspend

**Sorun**: Kredi kartı limit yetersiz olduğunda ne yapmalıyız? Kullanıcının License'ı hemen suspend mi edilmeli, yoksa grace period verilmeli mi?

**Grace Period Strategy**:
- `payment_failed`: 7 gün
- `card_expired`: 7 gün
- `insufficient_funds`: 3 gün
- `hard_decline`: 0 gün (immediate suspend)

**Subscription Status Flow**:
```
active → past_due → grace_period → suspended → cancelled
                     ↓
                  (payment recovered)
                     ↓
                   active
```

**Implementation**:
```php
// Subscription model
public function onGracePeriod(): bool
{
    return $this->status === 'grace_period' 
        && $this->grace_ends_at 
        && $this->grace_ends_at->isFuture();
}

public function shouldSuspend(): bool
{
    return $this->status === 'grace_period'
        && $this->grace_ends_at
        && $this->grace_ends_at->isPast();
}

// Scheduled command (runs daily)
php artisan subscriptions:suspend-overdue
```

**Dunning (Payment Recovery)**:
- max_retries: 4
- retry_intervals: [1, 3, 5, 7] days
- Recovery rate: 50-70% with dunning

**License Behavior**:
- `active` subscription → License `active`
- `grace_period` subscription → License `active` (but warning shown)
- `suspended` subscription → License `suspended`

### 6. Subscription → License Bridge

**Sorun**: Ödeme başarılı olduğunda abonelik "active" duruma geçiyor, ama License nasıl oluşacak?

**Çözüm**: Event-Listener Pattern:

```php
// Events
SubscriptionCreated::class
SubscriptionActivated::class
SubscriptionSuspended::class
SubscriptionCancelled::class

// Listeners
class GenerateLicenseForSubscription
{
    public function handle(SubscriptionActivated $event)
    {
        $subscription = $event->subscription;
        
        // Check if license already exists
        if ($subscription->license) {
            return;
        }
        
        // Generate license
        $license = License::create([
            'user_id' => $subscription->subscribable_id,
            'plan_id' => $subscription->plan_id,
            'key' => LicenseGenerator::generate(),
            'status' => 'active',
            'expires_at' => $subscription->current_period_end,
        ]);
        
        $subscription->update(['license_id' => $license->id]);
        
        // Fire event
        event(new LicenseGenerated($license));
    }
}

// EventServiceProvider
protected $listen = [
    SubscriptionActivated::class => [
        GenerateLicenseForSubscription::class,
        SendLicenseEmail::class,
    ],
    SubscriptionSuspended::class => [
        SuspendRelatedLicense::class,
    ],
];
```

### 7. B2B Fatura Bilgileri

**Sorun**: iyzico "Buyer" objesi TCKN'yi zorunlu tutar. B2B müşteriler için vergi bilgileri şart.

**Billable Trait (User Model)**:
```php
trait Billable
{
    // Existing fields...
    
    // B2B Invoice Fields
    protected ?string $tax_office = null;
    protected ?string $tax_id = null;    // Vergi No or TCKN
    protected ?string $company_name = null;
    
    public function getBuyerInfo(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'surname' => $this->surname,
            'email' => $this->email,
            'identityNumber' => $this->tax_id ?? $this->tc_kimlik,
            'registrationAddress' => $this->address,
            'ip' => request()->ip(),
            'city' => $this->city,
            'country' => 'Turkey',
            'zipCode' => $this->zip_code,
        ];
    }
}
```

**Migration**:
```php
Schema::table('users', function (Blueprint $table) {
    $table->string('tax_office')->nullable();
    $table->string('tax_id')->nullable();
    $table->string('company_name')->nullable();
});
```

### 8. Soft Deletes Zorunluluğu

**Sorun**: Finansal kayıtlar asla hard-delete ile silinmemelidir.

**Çözüm**: Tüm kritik tablolara `deleted_at` eklendi:
- `subscriptions` ✓
- `transactions` ✓
- `payment_methods` ✓
- `licenses` ✓
- `license_plans` ✓ (TODO)

**Model Usage**:
```php
class Subscription extends Model
{
    use SoftDeletes;
    
    protected $dates = ['deleted_at'];
}

// Query only non-deleted records
$subscriptions = Subscription::all();

// Include trashed
$subscriptions = Subscription::withTrashed()->get();

// Restore
$subscription->restore();
```

---


## Referans Projeler

- [Laravel Cashier (Stripe)](https://github.com/laravel/cashier) - Subscription billing pattern
- [iyzico/iyzipay-php](https://github.com/iyzico/iyzipay-php) - Official PHP SDK
- [Omnipay](https://github.com/thephpleague/omnipay) - Gateway abstraction pattern
- [coollabsio/laravel-saas](https://github.com/coollabsio/laravel-saas) - SaaS billing features
- [Sylius/PayPalPlugin](https://github.com/Sylius/PayPalPlugin) - Payment provider interface design

---

## Değişiklik Günlüğü

### v1.2 (2026-03-03)
**Kritik Gerçeklik Güncellemesi** - Feedback sonrası kapsamlı revizyon:
- **Card Storage Tokens**: payment_methods tablosuna provider_card_token ve provider_customer_token eklendi
- **Webhook Idempotency**: webhook_calls tablosu ve deduplication logic eklendi
- **Coupons & Discounts**: coupons ve discounts tabloları eklendi
- **Tax/KDV Management**: transactions ve subscriptions tablolarına tax_amount ve tax_rate eklendi
- **Scheduled Plan Changes**: scheduled_plan_changes tablosu (non-proration providers için)
- **Grace Period**: subscriptions tablosuna grace_ends_at ve dunning strategy eklendi
- **Soft Deletes**: Tüm finansal tablolara deleted_at eklendi (subscriptions, transactions, payment_methods, licenses)
- **PayTR iFrame**: PaymentResponse DTO'ya iframeToken ve iframeUrl eklendi
- **B2B Invoice**: Billable trait'e tax_office ve tax_id alanları eklendi
- **Event-Listener Bridge**: SubscriptionActivated → GenerateLicenseForSubscription patterni eklendi
- **"Kritik Gerçekler ve Çözümler" bölümü**: 8 kritik konu ve çözümleri detaylıca eklendi
- **iyzico/PayTR SDK detayları**: Gerçek class names, method signatures ve örnek kodlar eklendi

### v1.1 (2026-03-03)
- IYZWSv2 authentication detayları eklendi
- Ed25519/RSA-2048 crypto yapısı eklendi
- Laravel Pennant entegrasyonu notları eklendi
- PaymentGatewayRegistry pattern eklendi
- Webhook signature verification detayları eklendi
- Araştırma kaynakları bölümü eklendi

### v1.0 (2026-03-03)
- İlk master plan oluşturuldu
**Son Güncelleme**: 2026-03-03
**Durum**: Draft - Review Bekliyor
