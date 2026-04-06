# TEST-07: Race Condition ve Concurrent Operation Testleri

## Problem

Paket distributed lock mekanizmaları kullanıyor ama bu mekanizmaların doğru çalıştığı hiç test edilmemiş:
- Webhook duplicate detection (cache lock + DB lockForUpdate)
- Renewal job concurrent execution
- Dunning job concurrent execution
- License activation concurrent requests
- Metered billing concurrent processing

Not: Gerçek concurrent execution test etmek zordur. Bu testler lock mekanizmalarını **sequential ama controlled** şekilde doğrulayacak.

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenConcurrencyTest.php`

## Test Senaryoları

### Webhook deduplication testleri

```
it prevents duplicate webhook processing via database unique constraint
```
- WebhookCall oluştur: provider=iyzico, event_id=evt_001
- Aynı provider+event_id ile ikinci WebhookCall oluşturmaya çalış
- Unique constraint violation (QueryException) doğrula
- Bu test lock'tan bağımsız, DB seviyesinde korumanın çalıştığını doğrular

```
it handles duplicate webhook via controller endpoint
```
- POST webhook endpoint ile event_id=evt_001 → 202
- Aynı POST tekrar → 200 (duplicate)
- DB'de tek kayıt doğrula
- İkinci request'te FinalizeWebhookEventJob dispatch EDİLMEDİĞİNİ doğrula (processed ise)

```
it retries failed webhook on re-delivery via controller
```
- WebhookCall oluştur: status=failed, event_id=evt_002
- POST webhook endpoint ile event_id=evt_002
- WebhookCall status resetForRetry olmuş doğrula
- FinalizeWebhookEventJob dispatch edildiğini doğrula

### Renewal job lock testleri

```
it uses cache lock keyed by subscription id
```
- `ProcessRenewalCandidateJob` çalıştır
- Lock key'in `subguard:renewal:{subscription_id}` formatında olduğunu doğrula
- Cache::has() ile lock'un var olduğunu kontrol et (job çalışırken)

```
it skips renewal if lock is already held
```
- Subscription için cache lock manuel olarak al
- `ProcessRenewalCandidateJob` dispatch et
- Job'un lock alamadığı için skip ettiğini doğrula
- Transaction oluşmadığını doğrula
- Lock'u release et

```
it creates transaction with idempotency key to prevent duplicates
```
- `ProcessRenewalCandidateJob` çalıştır → transaction oluşur
- Aynı subscription + billing date ile tekrar çalıştır
- İkinci çalıştırmada yeni transaction oluşmadığını doğrula (firstOrCreate idempotency)

### Dunning job lock testleri

```
it skips dunning retry if lock is already held
```
- Transaction için cache lock manuel al
- `ProcessDunningRetryJob` dispatch et
- PaymentChargeJob dispatch EDİLMEDİĞİNİ doğrula
- Transaction retry_count değişmediğini doğrula

### FinalizeWebhookEventJob lock testleri

```
it uses distributed lock per webhook call
```
- WebhookCall oluştur (pending)
- `FinalizeWebhookEventJob` çalıştır
- Lock key formatını doğrula
- WebhookCall'ın processed olduğunu doğrula

```
it skips webhook finalization if lock is held
```
- WebhookCall için cache lock al
- `FinalizeWebhookEventJob` çalıştır
- WebhookCall hala pending doğrula (işlenmemiş)

### Transaction idempotency testleri

```
it prevents duplicate transactions via idempotency_key unique constraint
```
- Transaction oluştur: idempotency_key = 'test:unique:001'
- Aynı key ile ikinci Transaction oluşturmaya çalış
- firstOrCreate davranışı: yeni kayıt oluşmaz, mevcut döner
- DB'de tek kayıt doğrula

```
it generates unique idempotency keys for different billing periods
```
- Subscription billing date 2026-01-15 → key = 'renewal:1:20260115...'
- Billing date 2026-02-15 → key = 'renewal:1:20260215...'
- İki farklı transaction oluştuğunu doğrula

### License activation concurrent testleri

```
it prevents double activation for same domain under lock
```
- License: max_activations = 5, current_activations = 0
- activate('domain1.com') çağır → current_activations = 1
- Aynı domain ile tekrar activate → reddedilmeli veya idempotent
- current_activations hala 1

```
it correctly increments activations under sequential calls
```
- License: max_activations = 3
- activate('d1.com') → 1
- activate('d2.com') → 2
- activate('d3.com') → 3
- activate('d4.com') → rejected
- Tüm sayaçların tutarlı olduğunu doğrula

### Metered billing concurrent testleri

```
it prevents double billing of same usage via billed_at flag
```
- Usage kayıtları oluştur (billed_at = null)
- MeteredBillingProcessor ile işle → billed_at set edilir
- Aynı processor'ı tekrar çalıştır → whereNull('billed_at') filtresi nedeniyle 0 usage bulur
- İkinci transaction oluşmaz

```
it uses lockForUpdate on usage records during billing
```
- Bu FIX-03 sonrası geçerli
- Usage kayıtları lockForUpdate ile okunduğunu doğrula
- İkinci sorgunun lock serbest kalana kadar beklediğini doğrula

## Test Stratejisi

### Lock simulation pattern

```php
// Manuel lock alma ve sonra job çalıştırma
$lock = Cache::lock('subguard:renewal:' . $subscriptionId, 30);
$lock->acquire();

try {
    // Job bu lock'u alamayacak
    $job = new ProcessRenewalCandidateJob($subscriptionId);
    $job->handle(app(SubscriptionServiceInterface::class), app(PaymentManager::class));

    // Job'un skip ettiğini doğrula
    $this->assertDatabaseMissing('transactions', [
        'subscription_id' => $subscriptionId,
    ]);
} finally {
    $lock->release();
}
```

### Idempotency test pattern

```php
// Aynı idempotency key ile iki insert
$key = 'renewal:1:20260115120000';
$txn1 = Transaction::unguarded(fn () => Transaction::firstOrCreate(
    ['idempotency_key' => $key],
    ['status' => 'pending', ...]
));
$txn2 = Transaction::unguarded(fn () => Transaction::firstOrCreate(
    ['idempotency_key' => $key],
    ['status' => 'processed', ...]
));

expect($txn1->getKey())->toBe($txn2->getKey());
expect($txn2->wasRecentlyCreated)->toBeFalse();
```

## Doğrulama

1. DB unique constraint'lerin duplicate'ları engellediğini doğrula
2. Cache lock'ların concurrent job execution'ı engellediğini doğrula
3. Idempotency key'lerin duplicate transaction'ları engellediğini doğrula
4. Lock held durumunda job'ların graceful skip ettiğini doğrula
5. Metered billing billed_at flag'inin double billing'i engellediğini doğrula
6. `composer test` → tüm testler geçiyor
