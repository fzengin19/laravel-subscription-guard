# TEST-05: Dunning Exhaustion Senaryo Testleri

## Problem

Dunning exhaustion (retry tükenme) senaryosu hiç test edilmemiş. FIX-01 ile eklenen yeni davranışların doğrulanması gerekiyor:
- Max retry'a ulaşıldığında subscription'ın suspend edilmesi
- License'ın suspend edilmesi
- `DunningExhausted` event'inin dispatch edilmesi
- Notification job'unun kuyruğa atılması
- Transaction'ın `next_retry_at = null` olması

Ayrıca mevcut dunning akışının edge case'leri:
- Retry count boundary (2 → 3 geçişi)
- Grace period ile dunning etkileşimi
- Recovery sonrası durum

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenDunningExhaustionTest.php`

**Ön koşul**: Bu testler FIX-01 implementasyonu tamamlandıktan sonra yazılacak.

## Test Senaryoları

### Dunning exhaustion tetikleme

```
it suspends subscription when dunning retries are exhausted
```
- Active subscription oluştur
- Failed transaction oluştur: `retry_count = 3`, `next_retry_at` geçmiş tarih
- `ProcessDunningRetryJob` dispatch et
- Subscription status = `suspended` doğrula

```
it suspends associated license when dunning exhausted
```
- Subscription + License oluştur (`license_id` set)
- Failed transaction (retry_count = 3)
- `ProcessDunningRetryJob` çalıştır
- License status = `suspended` doğrula

```
it dispatches DunningExhausted event
```
- `Event::fake([DunningExhausted::class])`
- Failed transaction (retry_count = 3)
- Job çalıştır
- `Event::assertDispatched(DunningExhausted::class)` ile doğrula
- Event payload doğrula: provider, subscriptionId, transactionId, retryCount

```
it dispatches billing notification for dunning exhausted
```
- `Queue::fake()`
- Failed transaction (retry_count = 3)
- Job çalıştır
- `Queue::assertPushed(DispatchBillingNotificationsJob::class)` doğrula
- Job payload'da `dunning.exhausted` event type doğrula

```
it clears next_retry_at on exhausted transaction
```
- Failed transaction (retry_count = 3, next_retry_at = now)
- Job çalıştır
- Transaction refresh → `next_retry_at = null` doğrula

### Retry boundary testleri

```
it retries payment when retry_count is less than max
```
- Failed transaction: retry_count = 2, next_retry_at <= now
- `ProcessDunningRetryJob` çalıştır
- `PaymentChargeJob` dispatch edildiğini doğrula
- Transaction status = `retrying` doğrula
- Subscription hala `past_due` (suspended değil)

```
it does not retry when retry_count equals max
```
- Failed transaction: retry_count = 3
- Job çalıştır
- `PaymentChargeJob` dispatch EDİLMEDİĞİNİ doğrula
- Subscription = `suspended`

```
it respects configurable max retry count
```
- Config: `subscription-guard.billing.max_dunning_retries = 5`
- Failed transaction: retry_count = 4
- Job çalıştır → retry yapmalı (4 < 5)
- retry_count = 5 ile tekrar → suspend olmalı

### Grace period etkileşimi

```
it does not interfere with grace period when dunning is still in progress
```
- Subscription: status=past_due, grace_ends_at = 3 gün sonra
- Transaction: retry_count = 1
- Dunning retry devam etmeli
- Grace period değişmemeli

```
it suspends immediately on dunning exhaustion even if grace period not expired
```
- Subscription: status=past_due, grace_ends_at = 5 gün sonra
- Transaction: retry_count = 3 (exhausted)
- Job çalıştır
- Subscription = suspended (grace period bitmeden bile)

### Concurrent dunning testleri

```
it uses distributed lock to prevent concurrent dunning on same transaction
```
- Cache lock mekanizmasını doğrula
- İlk job lock alır
- İkinci job lock bekler veya skip eder

### Recovery senaryoları

```
it allows reactivation of suspended subscription after manual payment
```
- Suspended subscription (dunning exhaustion sonrası)
- Manuel ödeme alındığında subscription'ın `active` yapılabildiğini doğrula
- Not: Bu FIX-06 (state machine) ile `suspended → active` geçişinin izinli olduğunu doğrular

## Test Altyapısı

```php
use SubscriptionGuard\LaravelSubscriptionGuard\Events\DunningExhausted;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\PaymentChargeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;

beforeEach(function () {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
});
```

Transaction factory helper:
```php
function createFailedTransaction(int $subscriptionId, int $retryCount = 3): Transaction
{
    return Transaction::unguarded(fn () => Transaction::create([
        'subscription_id' => $subscriptionId,
        'provider' => 'iyzico',
        'provider_transaction_id' => 'txn_failed_' . Str::random(10),
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 99.90,
        'currency' => 'TRY',
        'retry_count' => $retryCount,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDays(2),
        'idempotency_key' => 'test:dunning:' . Str::random(20),
    ]));
}
```

## Doğrulama

1. Exhaustion tetiklemesi (retry_count >= max) doğru çalışıyor
2. Subscription + License suspend ediliyor
3. Event ve notification dispatch ediliyor
4. Boundary testleri (max-1 → retry, max → suspend) doğru
5. Config'den max retry okunuyor
6. Grace period ile çakışma yok
7. `composer test` → tüm testler geçiyor
