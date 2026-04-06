# FIX-01: Dunning Exhaustion Sonrası Otomatik Suspension

## Problem

`src/Jobs/ProcessDunningRetryJob.php` satır 54-55'te, `retry_count >= 3` koşulu sağlandığında job sessizce `return` ediyor. Herhangi bir:
- Abonelik durum güncellemesi yapılmıyor
- Event fırlatılmıyor
- Notification gönderilmiyor
- Log yazılmıyor

Sonuç: Abonelik `past_due` statüsünde zombi olarak kalıyor. `SuspendOverdueCommand` yalnızca `grace_ends_at` süresi dolan abonelikleri suspend eder, ama grace period 7 gün iken dunning 3x2=6 günde tükenebilir - aradaki 1 gün boşlukta hiçbir şey olmaz. Daha önemlisi, dunning tükenmesi ile suspension arasında otomatik bir bağ yok.

## Etkilenen Dosyalar

| Dosya | Satır | Mevcut Davranış |
|-------|-------|-----------------|
| `src/Jobs/ProcessDunningRetryJob.php` | 54-55 | `if ($retryCount >= 3) { return; }` |
| `src/Subscription/SubscriptionService.php` | 192 | `->where('retry_count', '<', 3)` hardcoded |
| `src/Commands/SuspendOverdueCommand.php` | 26-30 | Yalnızca grace_ends_at kontrolü |

## Çözüm Planı

### Adım 1: Yeni event oluştur

Dosya: `src/Events/DunningExhausted.php`

```php
final class DunningExhausted
{
    public function __construct(
        public readonly string $provider,
        public readonly int|string $subscriptionId,
        public readonly int|string $transactionId,
        public readonly int $retryCount,
        public readonly ?string $lastFailureReason,
    ) {}
}
```

Bu event, dunning retry'lar tükendikten sonra dispatch edilecek. Downstream listener'lar (notification, analytics, external webhook) bu event'i dinleyebilecek.

### Adım 2: ProcessDunningRetryJob'u güncelle

Dosya: `src/Jobs/ProcessDunningRetryJob.php`, satır 54-55

Mevcut kod:
```php
if ($retryCount >= 3) {
    return;
}
```

Yeni kod:
```php
if ($retryCount >= $maxRetries) {
    $this->handleDunningExhaustion($transaction, $subscription, $provider);
    return;
}
```

Yeni private method `handleDunningExhaustion()`:
1. Transaction'ı `failed` olarak işaretle (zaten failed ama durumu netleştir)
2. Subscription'ı `suspended` yap
3. İlişkili License varsa onu da `suspended` yap
4. `DunningExhausted` event'ini dispatch et
5. `DispatchBillingNotificationsJob` ile `dunning.exhausted` notification'ı kuyruğa at
6. Log yaz: `"Dunning exhausted for subscription {id} after {retryCount} retries"`

### Adım 3: handleDunningExhaustion implementasyonu

```php
private function handleDunningExhaustion(
    Transaction $transaction,
    Subscription $subscription,
    string $provider
): void {
    // 1. Transaction'ı final failed olarak işaretle
    $transaction->setAttribute('next_retry_at', null);
    $transaction->save();

    // 2. Subscription'ı DB transaction içinde suspend et
    DB::transaction(function () use ($subscription) {
        $locked = Subscription::query()
            ->lockForUpdate()
            ->find($subscription->getKey());

        if (! $locked instanceof Subscription) {
            return;
        }

        $locked->setAttribute('status', SubscriptionStatus::Suspended->value);
        $locked->save();

        // 3. İlişkili license'ı da suspend et
        $licenseId = $locked->getAttribute('license_id');
        if (is_numeric($licenseId)) {
            $license = License::query()
                ->lockForUpdate()
                ->find((int) $licenseId);

            if ($license instanceof License) {
                $license->setAttribute('status', SubscriptionStatus::Suspended->value);
                $license->save();
            }
        }
    });

    // 4. Event dispatch
    Event::dispatch(new DunningExhausted(
        provider: $provider,
        subscriptionId: $subscription->getKey(),
        transactionId: $transaction->getKey(),
        retryCount: (int) $transaction->getAttribute('retry_count'),
        lastFailureReason: (string) $transaction->getAttribute('failure_reason'),
    ));

    // 5. Notification job dispatch
    DispatchBillingNotificationsJob::dispatch('dunning.exhausted', [
        'provider' => $provider,
        'subscription_id' => $subscription->getKey(),
        'transaction_id' => $transaction->getKey(),
        'retry_count' => (int) $transaction->getAttribute('retry_count'),
    ])->onQueue(
        $this->paymentManager->queueName('notifications_queue', 'subguard-notifications')
    );

    // 6. Log
    Log::channel(
        (string) config('subscription-guard.logging.payments_channel', 'subguard_payments')
    )->warning('Dunning exhausted', [
        'subscription_id' => $subscription->getKey(),
        'transaction_id' => $transaction->getKey(),
        'retry_count' => (int) $transaction->getAttribute('retry_count'),
    ]);
}
```

### Adım 4: maxRetries değerini config'den oku

Bu adım FIX-04 ile birlikte yapılacak. Şimdilik `ProcessDunningRetryJob` içinde:

```php
$maxRetries = (int) config('subscription-guard.billing.max_dunning_retries', 3);
```

Bu değer `SubscriptionService.php:192`'deki hardcoded `3` ile de değiştirilecek (FIX-04 kapsamında).

### Adım 5: SuspendOverdueCommand ile tutarlılık

`SuspendOverdueCommand` mevcut haliyle `grace_ends_at` bazlı çalışıyor. Bu komut değişmeyecek - farklı bir amaç için var (zamanlı suspension). FIX-01 ise dunning tükenmesi bazlı ani suspension ekliyor. İkisi birbirini tamamlıyor:

- **SuspendOverdueCommand**: Grace period süresi dolmuş ama hala `past_due` olan abonelikler
- **FIX-01 (ProcessDunningRetryJob)**: Dunning retry'lar tükenmiş abonelikler

## Doğrulama

1. `retry_count` = 3 olan bir transaction ile `ProcessDunningRetryJob` çalıştır
2. Subscription status'un `suspended` olduğunu doğrula
3. License status'un `suspended` olduğunu doğrula
4. `DunningExhausted` event'inin dispatch edildiğini doğrula
5. Notification job'unun kuyruğa atıldığını doğrula
6. Transaction'ın `next_retry_at = null` olduğunu doğrula
7. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
