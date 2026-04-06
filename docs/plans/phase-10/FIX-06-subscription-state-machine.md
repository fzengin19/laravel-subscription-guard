# FIX-06: Subscription State Machine Doğrulaması

## Problem

`src/Enums/SubscriptionStatus.php` yalnızca string → enum normalizasyonu yapıyor. Geçersiz state geçişlerini engelleyen bir mekanizma yok.

Şu an mümkün olan tehlikeli geçişler:
- `cancelled` → `active` (iptal edilmiş abonelik sessizce aktifleşebilir)
- `suspended` → `active` (suspend edilmiş abonelik kontrol olmadan aktifleşebilir)
- `failed` → `active` (başarısız abonelik doğrudan aktif yapılabilir)
- `pending` → `cancelled` (henüz başlamamış abonelik atlayarak iptal edilebilir)

Bunlar `SubscriptionService`, `handleWebhookResult`, ve doğrudan model attribute set eden her yerde oluşabilir.

## Etkilenen Dosyalar

| Dosya | Satır | Sorun |
|-------|-------|-------|
| `src/Enums/SubscriptionStatus.php` | tüm dosya | Sadece normalizasyon var, geçiş kontrolü yok |
| `src/Models/Subscription.php` | setAttribute çağrıları | Status değişikliği kontrolsüz |
| `src/Subscription/SubscriptionService.php` | 74, 87, 100, 287, 388, 419, 539, 574 | Status değişiklik noktaları |

## Çözüm Planı

### Adım 1: Geçerli state geçişlerini tanımla

Dosya: `src/Enums/SubscriptionStatus.php`

Enum'a `allowedTransitions()` method'u ekle:

```php
/**
 * Bu durumdan geçiş yapılabilecek durumlar.
 *
 * @return array<SubscriptionStatus>
 */
public function allowedTransitions(): array
{
    return match ($this) {
        self::Pending => [
            self::Active,
            self::Failed,
            self::Cancelled,
        ],
        self::Active => [
            self::Cancelled,
            self::Paused,
            self::PastDue,
            self::Suspended,
        ],
        self::PastDue => [
            self::Active,      // Başarılı ödeme sonrası recovery
            self::Suspended,   // Grace period sona ermiş veya dunning tükenmiş
            self::Cancelled,   // Müşteri iptal etmiş
        ],
        self::Paused => [
            self::Active,      // Resume
            self::Cancelled,   // İptal
        ],
        self::Suspended => [
            self::Active,      // Manuel reactivation (ödeme alındıktan sonra)
            self::Cancelled,   // Kalıcı iptal
        ],
        self::Cancelled => [
            // Cancelled terminal state - geri dönüş yok
            // Yeni abonelik oluşturulmalı
        ],
        self::Failed => [
            self::Active,      // Retry başarılı
            self::Cancelled,   // Vazgeçildi
        ],
    };
}
```

### Adım 2: canTransitionTo() method'u ekle

Dosya: `src/Enums/SubscriptionStatus.php`

```php
public function canTransitionTo(SubscriptionStatus $target): bool
{
    if ($this === $target) {
        return true; // Aynı duruma geçiş her zaman izinli (idempotent)
    }

    return in_array($target, $this->allowedTransitions(), true);
}
```

### Adım 3: Subscription model'inde status setter'ını koruma altına al

Dosya: `src/Models/Subscription.php`

Model'e `transitionTo()` method'u ekle:

```php
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\SubGuardException;

public function transitionTo(SubscriptionStatus $newStatus): void
{
    $currentStatusValue = (string) $this->getAttribute('status');
    $currentStatus = SubscriptionStatus::normalize($currentStatusValue);

    if (! $currentStatus instanceof SubscriptionStatus) {
        // Bilinmeyen mevcut durum - geçişe izin ver (legacy data uyumluluğu)
        $this->setAttribute('status', $newStatus->value);
        return;
    }

    if (! $currentStatus->canTransitionTo($newStatus)) {
        throw new SubGuardException(
            sprintf(
                'Invalid subscription state transition: %s → %s (subscription #%s)',
                $currentStatus->value,
                $newStatus->value,
                (string) $this->getKey()
            )
        );
    }

    $this->setAttribute('status', $newStatus->value);
}
```

### Adım 4: SubscriptionService'deki status değişikliklerini transitionTo() ile değiştir

Dosya: `src/Subscription/SubscriptionService.php`

Her `setAttribute('status', ...)` çağrısını `transitionTo()` ile değiştir:

**cancel()** - satır 74:
```php
// Mevcut:
$subscription->setAttribute('status', SubscriptionStatus::Cancelled->value);

// Yeni:
$subscription->transitionTo(SubscriptionStatus::Cancelled);
```

**pause()** - satır 87:
```php
$subscription->transitionTo(SubscriptionStatus::Paused);
```

**resume()** - satır 100:
```php
$subscription->transitionTo(SubscriptionStatus::Active);
```

**handleWebhookResult()** - satır 286-332 arası:
```php
// subscription.created
$subscription->transitionTo(SubscriptionStatus::Active);

// subscription.cancelled
$subscription->transitionTo(SubscriptionStatus::Cancelled);
```

**handlePaymentResult()** - satır 387-425 arası:
```php
// Başarılı ödeme:
$subscription->transitionTo(SubscriptionStatus::Active);

// Başarısız ödeme:
$subscription->transitionTo(SubscriptionStatus::PastDue);
```

**recordWebhookTransaction()** - satır 539, 574:
```php
// Başarılı:
$subscription->transitionTo(SubscriptionStatus::Active);

// Başarısız:
$subscription->transitionTo(SubscriptionStatus::PastDue);
```

### Adım 5: SuspendOverdueCommand ve ProcessDunningRetryJob'da da transitionTo() kullan

Dosya: `src/Commands/SuspendOverdueCommand.php`, satır 46:
```php
$locked->transitionTo(SubscriptionStatus::Suspended);
```

Dosya: `src/Jobs/ProcessDunningRetryJob.php` (FIX-01'deki yeni handleDunningExhaustion):
```php
$locked->transitionTo(SubscriptionStatus::Suspended);
```

### Adım 6: handleWebhookResult'a geçersiz geçiş koruması ekle

Dosya: `src/Subscription/SubscriptionService.php`, `handleWebhookResult()` method'unda

Satır 280-282'de zaten iptal edilmiş aboneliğe `subscription.created` veya `subscription.order.success` gelirse atlıyor. Bu kontrolü genelleştir:

```php
try {
    $subscription->transitionTo($targetStatus);
} catch (SubGuardException $e) {
    Log::channel(
        (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
    )->warning('Webhook caused invalid state transition, skipping', [
        'subscription_id' => $subscription->getKey(),
        'current_status' => $subscription->getAttribute('status'),
        'target_status' => $targetStatus->value,
        'event_type' => $eventType,
    ]);
    return;
}
```

Bu sayede geçersiz webhook event'leri abonelik durumunu bozamaz.

## Geriye Dönük Uyumluluk

- `transitionTo()` bir yeni method. Mevcut `setAttribute('status', ...)` hala çalışır ama artık service layer üzerinden geçişler doğrulanır.
- Doğrudan model üzerinde `setAttribute('status', ...)` yapan harici kodlar etkilenmez - ama bu bilinçli bir tercih (paket içi koruma, dışarıya zorlama yok).
- `cancelled` → herhangi bir şey geçişi engellendiğinden, yanlışlıkla iptal edilmiş aboneliklerin yeniden aktifleştirilmesi artık hata fırlatır. Bu doğru davranıştır - yeni abonelik oluşturulmalıdır.

## Doğrulama

1. `active` → `cancelled` geçişinin başarılı olduğunu doğrula
2. `cancelled` → `active` geçişinin `SubGuardException` fırlattığını doğrula
3. `past_due` → `active` geçişinin başarılı olduğunu doğrula (recovery)
4. `past_due` → `suspended` geçişinin başarılı olduğunu doğrula (dunning exhaustion)
5. `suspended` → `active` geçişinin başarılı olduğunu doğrula (reactivation)
6. Aynı duruma geçişin (idempotent) başarılı olduğunu doğrula
7. Webhook ile geçersiz geçiş yapılmaya çalışıldığında loglanıp atlandığını doğrula
8. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
