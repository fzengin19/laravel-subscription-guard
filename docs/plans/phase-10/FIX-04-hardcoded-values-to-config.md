# FIX-04: Hardcoded Değerleri Config'e Taşıma

## Problem

Billing akışında kritik parametreler kod içinde sabit olarak yazılmış. Bu değerler farklı iş kurallarına sahip projelerde uyarlanamaz.

| Değer | Dosya | Satır | Hardcoded |
|-------|-------|-------|-----------|
| Grace period | `src/Subscription/SubscriptionService.php` | 422, 576 | `now()->addDays(7)` |
| Max dunning retries | `src/Subscription/SubscriptionService.php` | 192 | `'retry_count', '<', 3` |
| Max dunning retries | `src/Jobs/ProcessDunningRetryJob.php` | 54 | `$retryCount >= 3` |
| Retry interval | `src/Subscription/SubscriptionService.php` | 372, 525 | `now()->addDays(2)` |
| Webhook lock TTL | `src/Http/Controllers/WebhookController.php` | 36 | `10` (saniye) |
| Webhook block timeout | `src/Http/Controllers/WebhookController.php` | 39 | `5` (saniye) |
| Callback lock TTL | `src/Http/Controllers/PaymentCallbackController.php` | 57 | `10` (saniye) |
| Callback block timeout | `src/Http/Controllers/PaymentCallbackController.php` | 60 | `5` (saniye) |
| Dunning job lock TTL | `src/Jobs/ProcessDunningRetryJob.php` | ~35 | `30` (saniye) |
| Renewal job lock TTL | `src/Jobs/ProcessRenewalCandidateJob.php` | ~35 | `30` (saniye) |

## Etkilenen Dosyalar

- `config/subscription-guard.php`
- `src/Subscription/SubscriptionService.php`
- `src/Jobs/ProcessDunningRetryJob.php`
- `src/Jobs/ProcessRenewalCandidateJob.php`
- `src/Http/Controllers/WebhookController.php`
- `src/Http/Controllers/PaymentCallbackController.php`

## Çözüm Planı

### Adım 1: Config dosyasına yeni parametreleri ekle

Dosya: `config/subscription-guard.php`

Mevcut `billing` veya `queue` section'larının sonuna (veya yeni `billing` section oluşturarak):

```php
'billing' => [
    'timezone' => env('SUBGUARD_BILLING_TIMEZONE', 'Europe/Istanbul'),

    // Grace period: Ödeme başarısız olduğunda müşteriye tanınan süre (gün)
    'grace_period_days' => (int) env('SUBGUARD_GRACE_PERIOD_DAYS', 7),

    // Dunning: Başarısız ödeme tekrar deneme ayarları
    'max_dunning_retries' => (int) env('SUBGUARD_MAX_DUNNING_RETRIES', 3),
    'dunning_retry_interval_days' => (int) env('SUBGUARD_DUNNING_RETRY_INTERVAL_DAYS', 2),
],

'locks' => [
    // Webhook intake lock süresi (saniye)
    'webhook_lock_ttl' => (int) env('SUBGUARD_WEBHOOK_LOCK_TTL', 10),
    'webhook_block_timeout' => (int) env('SUBGUARD_WEBHOOK_BLOCK_TIMEOUT', 5),

    // Callback lock süresi (saniye)
    'callback_lock_ttl' => (int) env('SUBGUARD_CALLBACK_LOCK_TTL', 10),
    'callback_block_timeout' => (int) env('SUBGUARD_CALLBACK_BLOCK_TIMEOUT', 5),

    // Job lock süreleri (saniye)
    'renewal_job_lock_ttl' => (int) env('SUBGUARD_RENEWAL_JOB_LOCK_TTL', 30),
    'dunning_job_lock_ttl' => (int) env('SUBGUARD_DUNNING_JOB_LOCK_TTL', 30),
],
```

### Adım 2: SubscriptionService'deki hardcoded değerleri değiştir

Dosya: `src/Subscription/SubscriptionService.php`

**Grace period** (satır 422):
```php
// Mevcut:
$subscription->setAttribute('grace_ends_at', now()->addDays(7));

// Yeni:
$gracePeriodDays = (int) config('subscription-guard.billing.grace_period_days', 7);
$subscription->setAttribute('grace_ends_at', now()->addDays($gracePeriodDays));
```

Aynı değişiklik satır 576 için de yapılacak (recordWebhookTransaction method'undaki grace period).

**Max dunning retries** (satır 192):
```php
// Mevcut:
->where('retry_count', '<', 3)

// Yeni:
->where('retry_count', '<', (int) config('subscription-guard.billing.max_dunning_retries', 3))
```

**Retry interval** (satır 372):
```php
// Mevcut:
'next_retry_at' => $result->success ? null : now()->addDays(2),

// Yeni:
'next_retry_at' => $result->success ? null : now()->addDays(
    (int) config('subscription-guard.billing.dunning_retry_interval_days', 2)
),
```

Aynı değişiklik satır 525 için de yapılacak (recordWebhookTransaction method'undaki retry interval).

### Adım 3: ProcessDunningRetryJob'daki hardcoded değerleri değiştir

Dosya: `src/Jobs/ProcessDunningRetryJob.php`

**Max retries** (satır 54):
```php
// Mevcut:
if ($retryCount >= 3) {

// Yeni:
$maxRetries = (int) config('subscription-guard.billing.max_dunning_retries', 3);
if ($retryCount >= $maxRetries) {
```

**Lock TTL** (varsa hardcoded lock):
```php
// Mevcut:
$lock = cache()->lock('subguard:dunning:'.$transactionId, 30);

// Yeni:
$lockTtl = (int) config('subscription-guard.locks.dunning_job_lock_ttl', 30);
$lock = cache()->lock('subguard:dunning:'.$transactionId, $lockTtl);
```

### Adım 4: ProcessRenewalCandidateJob'daki lock TTL'i değiştir

Dosya: `src/Jobs/ProcessRenewalCandidateJob.php`

```php
// Mevcut:
$lock = cache()->lock('subguard:renewal:'.$subscriptionId, 30);

// Yeni:
$lockTtl = (int) config('subscription-guard.locks.renewal_job_lock_ttl', 30);
$lock = cache()->lock('subguard:renewal:'.$subscriptionId, $lockTtl);
```

### Adım 5: WebhookController'daki lock değerlerini değiştir

Dosya: `src/Http/Controllers/WebhookController.php`

Satır 36:
```php
// Mevcut:
$lock = cache()->lock('subguard:webhook-intake:'.$provider.':'.$eventId, 10);

// Yeni:
$lockTtl = (int) config('subscription-guard.locks.webhook_lock_ttl', 10);
$lock = cache()->lock('subguard:webhook-intake:'.$provider.':'.$eventId, $lockTtl);
```

Satır 39:
```php
// Mevcut:
$result = $lock->block(5, function () use (...) {

// Yeni:
$blockTimeout = (int) config('subscription-guard.locks.webhook_block_timeout', 5);
$result = $lock->block($blockTimeout, function () use (...) {
```

### Adım 6: PaymentCallbackController'daki lock değerlerini değiştir

Dosya: `src/Http/Controllers/PaymentCallbackController.php`

Satır 57 ve 60 için WebhookController ile aynı pattern, `callback_lock_ttl` ve `callback_block_timeout` kullanarak.

## Doğrulama

1. Tüm config key'lerin doğru default değerlerle çalıştığını doğrula
2. Env override'ların çalıştığını doğrula (örn: `SUBGUARD_GRACE_PERIOD_DAYS=14`)
3. Config cache'lendiğinde (`php artisan config:cache`) değerlerin okunabildiğini doğrula
4. Mevcut testlerin hala geçtiğini doğrula - testler config override kullanabilmeli
5. Tüm hardcoded değerlerin kaldırıldığını grep ile doğrula:
   ```bash
   grep -rn "addDays(7)" src/
   grep -rn "addDays(2)" src/
   grep -rn "'<', 3" src/
   grep -rn ">= 3" src/Jobs/
   ```
