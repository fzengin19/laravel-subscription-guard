# Payment Provider Architecture Design

**Date**: 2026-03-03  
**Context**: Laravel Subscription Guard - Multi-Provider Payment Integration  
**Providers**: iyzico, PayTR, Custom Providers  

## Executive Summary

This document outlines architectural patterns and best practices for implementing a multi-provider payment system in Laravel, based on analysis of Laravel Cashier, Omnipay, and existing iyzico/PayTR packages.

## 1. Architecture Analysis

### 1.1 Laravel Cashier (Stripe)

**Repository**: laravel/cashier-stripe  
**Commit**: 32cf8df9aa3ed8e47f41e68393dbdffaf06bae00

**Key Architectural Patterns**:

1. **Billable Trait Pattern**
   - Uses a single `Billable` trait that composes multiple concern traits
   - Clean separation of responsibilities through traits
   - Easy to add to any Eloquent model

   **Evidence** ([source](https://github.com/laravel/cashier-stripe/blob/32cf8df9aa3ed8e47f41e68393dbdffaf06bae00/src/Billable.php#L13-L22)):
   ```php
   trait Billable
   {
       use HandlesTaxes;
       use ManagesCustomer;
       use ManagesInvoices;
       use ManagesPaymentMethods;
       use ManagesSubscriptions;
       use ManagesUsageBilling;
       use PerformsCharges;
   }
   ```

2. **Concern-Based Organization**
   - `PerformsCharges`: Handles one-time payments
   - `ManagesSubscriptions`: Subscription lifecycle
   - `ManagesPaymentMethods`: Payment method CRUD
   - `ManagesCustomer`: Customer management
   - `ManagesInvoices`: Invoice generation

3. **Webhook Controller Pattern**
   - Single controller with dynamic method routing
   - Method naming convention: `handle{StudlyEventName}`
   - Middleware for signature verification
   - Event dispatching for extensibility

   **Evidence** ([source](https://github.com/laravel/cashier-stripe/blob/32cf8df9aa3ed8e47f41e68393dbdffaf06bae00/src/Http/Controllers/WebhookController.php#L40-L58)):
   ```php
   public function handleWebhook(Request $request)
   {
       $payload = json_decode($request->getContent(), true);
       $method = 'handle'.Str::studly(str_replace('.', '_', $payload['type']));

       WebhookReceived::dispatch($payload);

       if (method_exists($this, $method)) {
           $this->setMaxNetworkRetries();
           $response = $this->{$method}($payload);
           WebhookHandled::dispatch($payload);
           return $response;
       }

       return $this->missingMethod($payload);
   }
   ```

4. **Webhook Signature Verification**
   - Middleware-based verification
   - Uses Stripe's signature verification library
   - Configurable tolerance

   **Evidence** ([source](https://github.com/laravel/cashier-stripe/blob/32cf8df9aa3ed8e47f41e68393dbdffaf06bae00/src/Http/Middleware/VerifyWebhookSignature.php#L21-L35)):
   ```php
   public function handle($request, Closure $next)
   {
       try {
           WebhookSignature::verifyHeader(
               $request->getContent(),
               $request->header('Stripe-Signature'),
               config('cashier.webhook.secret'),
               config('cashier.webhook.tolerance')
           );
       } catch (SignatureVerificationException $exception) {
           throw new AccessDeniedHttpException($exception->getMessage(), $exception);
       }

       return $next($request);
   }
   ```

### 1.2 Omnipay (Multi-Gateway Library)

**Repository**: thephpleague/omnipay  
**Commit**: 9cb6293949647b8878b2e3371930e0a69c552073

**Key Architectural Patterns**:

1. **Gateway Interface Pattern**
   - Common interface for all payment gateways
   - Consistent API across different providers
   - Factory pattern for gateway creation

   **Usage Pattern**:
   ```php
   use Omnipay\Omnipay;

   $gateway = Omnipay::create('Stripe');
   $gateway->setApiKey('abc123');

   $response = $gateway->purchase([
       'amount' => '10.00',
       'currency' => 'USD',
       'card' => $formData
   ])->send();

   if ($response->isRedirect()) {
       $response->redirect();
   } elseif ($response->isSuccessful()) {
       // Payment successful
   } else {
       // Payment failed
   }
   ```

2. **Message Pattern**
   - Request/Response abstraction
   - Separate request objects for each operation
   - Consistent response interface

3. **Abstract Gateway Base**
   - Common functionality in abstract base class
   - Template method pattern
   - Gateway-specific implementations extend base

### 1.3 iyzico Integration Patterns

**Repository**: iyzico/iyzipay-php  
**Documentation**: Context7 /iyzico/iyzipay-php

**Key Integration Patterns**:

1. **Options-Based Configuration**
   ```php
   $options = new \Iyzipay\Options();
   $options->setApiKey("your api key");
   $options->setSecretKey("your secret key");
   $options->setBaseUrl("https://sandbox-api.iyzipay.com");
   ```

2. **Request/Response Model**
   - Detailed request objects with fluent setters
   - Comprehensive buyer and address information
   - Basket item structure

   **Payment Creation Pattern**:
   ```php
   $request = new \Iyzipay\Request\CreatePaymentRequest();
   $request->setLocale(\Iyzipay\Model\Locale::TR);
   $request->setConversationId("123456789");
   $request->setPrice("1");
   $request->setPaidPrice("1.2");
   $request->setCurrency(\Iyzipay\Model\Currency::TL);
   
   $payment = \Iyzipay\Model\Payment::create($request, $options);
   ```

### 1.4 Payment Gateway Interface Examples

**Found Patterns** (from GitHub search):

1. **Drupal Commerce** ([source](https://github.com/drupalcommerce/commerce/blob/8.x-2.x/modules/payment/src/Plugin/Commerce/PaymentGateway/PaymentGatewayInterface.php)):
   - Plugin-based architecture
   - Supports on-site and off-site payment methods
   - Configuration entities for gateway settings

2. **Liberu E-Commerce** ([source](https://github.com/liberu-ecommerce/ecommerce-laravel/blob/main/app/Interfaces/PaymentGatewayInterface.php)):
   ```php
   interface PaymentGatewayInterface
   {
       public function processPayment(float $amount, array $paymentDetails): array;
       public function processSubscription(string $planId, array $subscriptionDetails): array;
       public function refundPayment(string $transactionId, float $amount): array;
   }
   ```

3. **PHP BTC Exchange** ([source](https://github.com/diannt/php_btc_exchange/blob/master/lib/Payment/PaymentGatewayInterface.php)):
   ```php
   interface PaymentGatewayInterface
   {
       public function charge(array $paymentData): PaymentResult;
       public function refund(string $transactionId, ?float $amount = null): RefundResult;
       public function validateWebhook(array $payload, string $signature = ''): WebhookValidationResult;
       public function getTransactionStatus(string $transactionId): array;
   }
   ```

## 2. Recommended Architecture

### 2.1 Core Interface Design

```php
<?php

namespace SubGuard\LaravelSubGuard\Contracts;

use SubGuard\LaravelSubGuard\DataTransferObjects\PaymentResult;
use SubGuard\LaravelSubGuard\DataTransferObjects\RefundResult;
use SubGuard\LaravelSubGuard\DataTransferObjects\SubscriptionResult;

interface PaymentGatewayInterface
{
    /**
     * Get the gateway name/identifier.
     */
    public function getName(): string;

    /**
     * Process a one-time payment.
     */
    public function charge(float $amount, array $paymentDetails): PaymentResult;

    /**
     * Create a subscription.
     */
    public function createSubscription(string $planId, array $subscriptionDetails): SubscriptionResult;

    /**
     * Cancel a subscription.
     */
    public function cancelSubscription(string $subscriptionId): bool;

    /**
     * Refund a payment (full or partial).
     */
    public function refund(string $transactionId, ?float $amount = null): RefundResult;

    /**
     * Get transaction status.
     */
    public function getTransactionStatus(string $transactionId): array;

    /**
     * Validate webhook signature.
     */
    public function validateWebhook(array $payload, string $signature = ''): bool;

    /**
     * Handle webhook event.
     */
    public function handleWebhook(array $payload): void;

    /**
     * Get supported currencies.
     */
    public function getSupportedCurrencies(): array;
}
```

### 2.2 Abstract Base Provider

```php
<?php

namespace SubGuard\LaravelSubGuard\Providers;

use SubGuard\LaravelSubGuard\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

abstract class AbstractPaymentProvider implements PaymentGatewayInterface
{
    protected array $config;
    protected bool $testMode;

    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            $this->getDefaultConfig(),
            $config
        );
        $this->testMode = $this->config['test_mode'] ?? false;
    }

    /**
     * Get default configuration for this provider.
     */
    abstract protected function getDefaultConfig(): array;

    /**
     * Get the gateway client instance.
     */
    abstract protected function getClient(): mixed;

    /**
     * Log a payment event.
     */
    protected function log(string $event, array $context = []): void
    {
        Log::channel('payments')->info("[{$this->getName()}] {$event}", $context);
    }

    /**
     * Handle provider-specific errors.
     */
    protected function handleError(\Throwable $exception): void
    {
        $this->log('error', [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ]);

        throw new PaymentException(
            $this->getName() . ': ' . $exception->getMessage(),
            $exception->getCode(),
            $exception
        );
    }
}
```

### 2.3 iyzico Provider Implementation

```php
<?php

namespace SubGuard\LaravelSubGuard\Providers;

use Iyzipay\Options;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Model\Payment;
use SubGuard\LaravelSubGuard\DataTransferObjects\PaymentResult;

class IyzicoProvider extends AbstractPaymentProvider
{
    protected Options $client;

    protected function getDefaultConfig(): array
    {
        return [
            'api_key' => config('subguard.providers.iyzico.api_key'),
            'secret_key' => config('subguard.providers.iyzico.secret_key'),
            'base_url' => config('subguard.providers.iyzico.base_url'),
            'test_mode' => config('subguard.providers.iyzico.test_mode', true),
        ];
    }

    protected function getClient(): Options
    {
        if (!isset($this->client)) {
            $this->client = new Options();
            $this->client->setApiKey($this->config['api_key']);
            $this->client->setSecretKey($this->config['secret_key']);
            $this->client->setBaseUrl($this->config['base_url']);
        }

        return $this->client;
    }

    public function getName(): string
    {
        return 'iyzico';
    }

    public function charge(float $amount, array $paymentDetails): PaymentResult
    {
        try {
            $request = new CreatePaymentRequest();
            $request->setLocale($paymentDetails['locale'] ?? 'tr');
            $request->setConversationId($paymentDetails['conversation_id']);
            $request->setPrice((string) $amount);
            $request->setPaidPrice((string) ($paymentDetails['paid_price'] ?? $amount));
            $request->setCurrency($paymentDetails['currency'] ?? 'TRY');
            $request->setInstallment($paymentDetails['installment'] ?? 1);
            $request->setBasketId($paymentDetails['basket_id'] ?? null);
            
            // Set payment card
            $request->setPaymentCard($paymentDetails['card']);
            
            // Set buyer info
            $request->setBuyer($paymentDetails['buyer']);
            
            // Set addresses
            $request->setShippingAddress($paymentDetails['shipping_address']);
            $request->setBillingAddress($paymentDetails['billing_address']);
            
            // Set basket items
            $request->setBasketItems($paymentDetails['items']);

            $payment = Payment::create($request, $this->getClient());

            $this->log('charge', [
                'conversation_id' => $paymentDetails['conversation_id'],
                'amount' => $amount,
                'status' => $payment->getStatus(),
            ]);

            return new PaymentResult(
                success: $payment->getStatus() === 'success',
                transactionId: $payment->getPaymentId(),
                message: $payment->getErrorMessage() ?? 'Payment successful',
                rawResponse: $payment->getRawResult()
            );
        } catch (\Throwable $exception) {
            $this->handleError($exception);
        }
    }

    public function refund(string $transactionId, ?float $amount = null): RefundResult
    {
        // Implement iyzico refund logic
    }

    public function validateWebhook(array $payload, string $signature = ''): bool
    {
        // Implement iyzico webhook validation
    }

    // ... other interface methods
}
```

### 2.4 Payment Manager (Factory Pattern)

```php
<?php

namespace SubGuard\LaravelSubGuard;

use SubGuard\LaravelSubGuard\Contracts\PaymentGatewayInterface;
use SubGuard\LaravelSubGuard\Exceptions\InvalidProviderException;

class PaymentManager
{
    protected array $providers = [];
    protected array $resolved = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a payment provider instance.
     */
    public function provider(string $name = null): PaymentGatewayInterface
    {
        $name = $name ?? $this->getDefaultProvider();

        if (!isset($this->resolved[$name])) {
            $this->resolved[$name] = $this->resolve($name);
        }

        return $this->resolved[$name];
    }

    /**
     * Resolve a provider instance.
     */
    protected function resolve(string $name): PaymentGatewayInterface
    {
        $config = $this->config['providers'][$name] ?? null;

        if (!$config) {
            throw new InvalidProviderException("Provider [{$name}] is not configured.");
        }

        $class = $config['driver'];

        if (!class_exists($class)) {
            throw new InvalidProviderException("Provider class [{$class}] does not exist.");
        }

        return new $class($config);
    }

    /**
     * Get the default provider name.
     */
    protected function getDefaultProvider(): string
    {
        return $this->config['default'] ?? 'iyzico';
    }

    /**
     * Register a custom provider.
     */
    public function extend(string $name, callable $callback): self
    {
        $this->providers[$name] = $callback;

        return $this;
    }
}
```

### 2.5 Webhook Controller Pattern

```php
<?php

namespace SubGuard\LaravelSubGuard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use SubGuard\LaravelSubGuard\Events\WebhookReceived;
use SubGuard\LaravelSubGuard\Events\WebhookHandled;

class WebhookController extends Controller
{
    protected string $provider;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $provider = $request->route('provider');
            $this->provider = app('subguard')->provider($provider);
            
            // Verify webhook signature
            if (!$this->provider->validateWebhook(
                $request->all(),
                $request->header('X-Signature')
            )) {
                abort(403, 'Invalid webhook signature');
            }

            return $next($request);
        });
    }

    /**
     * Handle a webhook call.
     */
    public function handle(Request $request, string $provider)
    {
        $payload = $request->all();
        
        event(new WebhookReceived($provider, $payload));

        $method = 'handle' . Str::studly(str_replace(['.', '_'], '', $payload['event_type'] ?? ''));

        if (method_exists($this->provider, $method)) {
            $this->provider->{$method}($payload);
        } else {
            $this->provider->handleWebhook($payload);
        }

        event(new WebhookHandled($provider, $payload));

        return response()->json(['status' => 'success']);
    }
}
```

### 2.6 Billable Trait Pattern

```php
<?php

namespace SubGuard\LaravelSubGuard\Traits;

use SubGuard\LaravelSubGuard\Facades\SubGuard;

trait Billable
{
    /**
     * Make a one-time charge.
     */
    public function charge(float $amount, array $details = [])
    {
        return SubGuard::provider($details['provider'] ?? null)
            ->charge($amount, array_merge($details, [
                'customer_id' => $this->id,
                'email' => $this->email,
            ]));
    }

    /**
     * Subscribe to a plan.
     */
    public function subscribe(string $planId, array $details = [])
    {
        return SubGuard::provider($details['provider'] ?? null)
            ->createSubscription($planId, array_merge($details, [
                'customer_id' => $this->id,
                'email' => $this->email,
            ]));
    }

    /**
     * Refund a payment.
     */
    public function refund(string $transactionId, ?float $amount = null, string $provider = null)
    {
        return SubGuard::provider($provider)
            ->refund($transactionId, $amount);
    }
}
```

## 3. Configuration Structure

```php
// config/subguard.php
return [
    'default' => env('PAYMENT_PROVIDER', 'iyzico'),

    'providers' => [
        'iyzico' => [
            'driver' => \SubGuard\LaravelSubGuard\Providers\IyzicoProvider::class,
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
            'test_mode' => env('IYZICO_TEST_MODE', true),
        ],

        'paytr' => [
            'driver' => \SubGuard\LaravelSubGuard\Providers\PayTRProvider::class,
            'merchant_id' => env('PAYTR_MERCHANT_ID'),
            'merchant_key' => env('PAYTR_MERCHANT_KEY'),
            'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
            'test_mode' => env('PAYTR_TEST_MODE', true),
        ],
    ],

    'webhooks' => [
        'secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'tolerance' => 300, // seconds
    ],

    'logging' => [
        'enabled' => true,
        'channel' => 'payments',
    ],
];
```

## 4. Database Schema Recommendations

### 4.1 Payments Table

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->morphs('payable'); // User or other model
    $table->string('provider');
    $table->string('transaction_id')->unique();
    $table->string('conversation_id')->nullable();
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3);
    $table->decimal('paid_amount', 10, 2)->nullable();
    $table->string('status');
    $table->json('metadata')->nullable();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['provider', 'transaction_id']);
    $table->index('status');
});
```

### 4.2 Subscriptions Table

```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->morphs('subscribable');
    $table->string('provider');
    $table->string('subscription_id')->unique();
    $table->string('plan_id');
    $table->string('status');
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['provider', 'subscription_id']);
    $table->index('status');
});
```

## 5. Best Practices & Recommendations

### 5.1 Security

1. **Webhook Signature Verification**
   - Always verify webhook signatures
   - Use constant-time comparison
   - Log all webhook attempts

2. **Sensitive Data Handling**
   - Never store full card numbers
   - Use tokenization when available
   - Encrypt sensitive configuration

3. **Error Handling**
   - Catch provider-specific exceptions
   - Convert to generic payment exceptions
   - Never expose internal errors to users

### 5.2 Testing

1. **Provider Testing**
   - Each provider should have comprehensive unit tests
   - Use mock responses for testing
   - Test all webhook scenarios

2. **Integration Testing**
   - Test with provider sandbox environments
   - Test failure scenarios
   - Test refund flows

### 5.3 Extensibility

1. **Custom Providers**
   - Easy to add new providers via `extend()` method
   - Clear interface contract
   - Abstract base class for common functionality

2. **Event System**
   - Dispatch events for all major actions
   - Allow listeners to modify behavior
   - Support custom event subscribers

### 5.4 Performance

1. **Caching**
   - Cache provider instances
   - Cache exchange rates if needed
   - Cache subscription status

2. **Queues**
   - Process webhooks in queues
   - Async refund processing
   - Batch operations where possible

## 6. Implementation Strategy

### Phase 1: Core Infrastructure (Week 1)
- [ ] Define interfaces and contracts
- [ ] Create abstract base provider
- [ ] Implement payment manager
- [ ] Set up database migrations
- [ ] Create DTOs for results

### Phase 2: iyzico Provider (Week 2)
- [ ] Implement IyzicoProvider
- [ ] Handle payment creation
- [ ] Implement webhook handling
- [ ] Add refund functionality
- [ ] Write comprehensive tests

### Phase 3: PayTR Provider (Week 3)
- [ ] Implement PayTRProvider
- [ ] Handle payment creation
- [ ] Implement webhook handling
- [ ] Add refund functionality
- [ ] Write comprehensive tests

### Phase 4: Subscription Support (Week 4)
- [ ] Add subscription interface methods
- [ ] Implement for iyzico
- [ ] Implement for PayTR
- [ ] Add subscription management
- [ ] Test subscription workflows

### Phase 5: Documentation & Polish (Week 5)
- [ ] Write comprehensive documentation
- [ ] Add usage examples
- [ ] Create migration guides
- [ ] Performance optimization
- [ ] Security audit

## 7. Key Takeaways

1. **Use the Billable Trait Pattern** - Clean, composable, follows Laravel conventions
2. **Abstract Gateway Pattern** - Common interface with provider-specific implementations
3. **Webhook Controller Pattern** - Dynamic routing with signature verification
4. **Manager/Factory Pattern** - Easy provider resolution and extension
5. **Event-Driven Architecture** - Extensible through events and listeners
6. **DTO Pattern** - Type-safe result objects
7. **Configuration-Driven** - Flexible provider configuration

## 8. Next Steps

After reviewing this document, we should:

1. Validate the architecture design
2. Prioritize provider implementations
3. Define subscription requirements in detail
4. Plan database schema
5. Set up testing infrastructure

Would you like me to proceed with implementing any specific part of this architecture?
