# Faz 7: Code Simplification & Architectural Decomposition (Plan v3)

> **Durum**: Planlama
> **Hedef Başlangıç**: 2026-03-06
> **Tahmini Süre**: 3 hafta
> **Bağımlılıklar**: Faz 1-6 çıktıları, özellikle Faz 6 security hardening guardrail'leri

---

## 1) Faz Amacı

Bu fazın amacı, iş mantığını değiştirmeden kod okunabilirliğini ve bakım hızını artırmaktır:

- **Anemic → Rich Domain Model**: Modellere state-transition metodları ekleme
- **DRY**: Controller/Job tekrarlarını trait ve model metotları ile ortadan kaldırma
- **Decomposition**: 917-satırlık `IyzicoProvider` ve 830-satırlık `SubscriptionService`'i sorumluluk bazlı ayrıştırma
- **Consistency**: `LicenseManager` activate/deactivate counter tutarlılığı
- **Stability**: Public service API'lerini ve provider/domain modül sınırlarını bozmadan iç ayrıştırma

Temel prensip: **daha kısa kod** tek başına hedef değildir; hedef **daha anlaşılır, daha güvenli, daha test edilebilir kod** üretmektir.

---

## 2) Sadeleştirme İlkeleri (Faz 7 Kuralları)

1. **Readability First**: Her değişiklikte okunabilirlik etkisi açıklanır; salt satır azaltma yapılmaz.
2. **No Business Logic Drift**: Davranış eşdeğerliği testlerle kanıtlanmadan refactor kabul edilmez.
3. **Guard-Aware Refactor**: Faz 6 model guard sınırları korunur; `update()` geçişlerinde guard/forceFill/unguarded etkisi kontrol edilir.
4. **Event Semantics Preservation**: Model event tetikleme davranışı korunur (instance update vs query builder update farkı gözetilir).
5. **Lock-Safety**: `lockForUpdate` varsayımlarına dayanan query reuse değişiklikleri sadece transaction kapsamı içinde yapılır.
6. **DI-Aware Decomposition**: Constructor'a parametre ekleyen her refactor mutlaka `LaravelSubscriptionGuardServiceProvider` güncellemesi içerir.
7. **Public API Compatibility**: `SubscriptionService` üzerindeki mevcut public method imzaları bu fazda korunur; iç delegasyon yapılabilir, dış çağrıcı kırılması yapılamaz.
8. **Boundary Preservation**: Payment provider katmanı domain persistence/event dispatch yapmaz; yeni helper/service'ler aynı modül sınırını korur.
9. **Explicit Shared Infrastructure**: Iyzico helper paylaşımı belirsiz trait kullanımı ile değil, tek bir support collaborator ile çözülür.

---

## 3) Uygulama Fazları

---

### Phase A: Model State-Transition Methods

Şu anda state-transition blokları Jobs, Controllers ve billing orchestration içinde 14+ yerde inline tekrarlanıyor. Ancak her tekrar aynı türde değildir: model-instance `save()` akışları model metodlarına taşınabilir, bulk query-builder `update()` akışları ise event/performance semantiği değişmesin diye ayrı değerlendirilmelidir.

#### A1: `WebhookCall` modeline 3 yeni public method

**Dosya**: `src/Models/WebhookCall.php`

```php
public function markFailed(string $message): void
{
    $this->setAttribute('status', 'failed');
    $this->setAttribute('error_message', $message);
    $this->setAttribute('processed_at', now());
    $this->save();
}

public function markProcessed(?string $message = null): void
{
    $this->setAttribute('status', 'processed');
    $this->setAttribute('processed_at', now());
    $this->setAttribute('error_message', $message);
    $this->save();
}

public function resetForRetry(array $attributes): void
{
    foreach ($attributes as $key => $value) {
        $this->setAttribute($key, $value);
    }
    $this->setAttribute('status', 'pending');
    $this->setAttribute('error_message', null);
    $this->setAttribute('processed_at', null);
    $this->save();
}
```

**Kullanım yerleri**: `FinalizeWebhookEventJob` (5 blok), `WebhookController`, `PaymentCallbackController`

#### A2: `Transaction` modeline 4 yeni public method

**Dosya**: `src/Models/Transaction.php`

```php
use Illuminate\Support\Carbon;

public function markFailed(
    string $reason,
    int $retryCount,
    ?Carbon $nextRetryAt = null,
    mixed $providerResponse = null,
    bool $replaceProviderResponse = false,
): void
{
    $this->setAttribute('retry_count', $retryCount);
    $this->setAttribute('status', 'failed');
    $this->setAttribute('failure_reason', $reason);
    $this->setAttribute('last_retry_at', now());
    $this->setAttribute('next_retry_at', $nextRetryAt);
    $this->setAttribute('processed_at', now());
    if ($replaceProviderResponse || $providerResponse !== null) {
        $this->setAttribute('provider_response', $providerResponse);
    }
    $this->save();
}

public function markProcessing(): void
{
    $this->setAttribute('status', 'processing');
    $this->setAttribute('failure_reason', null);
    $this->setAttribute('last_retry_at', now());
    $this->setAttribute('next_retry_at', null);
    $this->setAttribute('processed_at', null);
    $this->save();
}

public function markRetrying(): void
{
    $this->setAttribute('status', 'retrying');
    $this->setAttribute('last_retry_at', now());
    $this->save();
}

public function markProcessed(
    string $transactionId,
    mixed $providerResponse = null,
    bool $replaceProviderResponse = false,
): void
{
    $this->setAttribute('status', 'processed');
    $this->setAttribute('provider_transaction_id', $transactionId);
    $this->setAttribute('failure_reason', null);
    $this->setAttribute('processed_at', now());
    if ($replaceProviderResponse || $providerResponse !== null) {
        $this->setAttribute('provider_response', $providerResponse);
    }
    $this->save();
}
```

**Kullanım yerleri**: `PaymentChargeJob` (3 blok), `ProcessDunningRetryJob` (1 blok), `MeteredBillingProcessor` (2 blok)

#### A3: `ScheduledPlanChange` modeline 2 yeni public method

**Dosya**: `src/Models/ScheduledPlanChange.php`

```php
public function markFailed(string $message): void
{
    $this->setAttribute('status', 'failed');
    $this->setAttribute('error_message', $message);
    $this->setAttribute('processed_at', now());
    $this->save();
}

public function markProcessed(): void
{
    $this->setAttribute('status', 'processed');
    $this->setAttribute('processed_at', now());
    $this->setAttribute('error_message', null);
    $this->save();
}
```

**Kullanım yerleri**: `ProcessScheduledPlanChangeJob` (3 blok)

#### A4: Bulk query update carve-out

**Dosya**: `src/Jobs/ProcessRenewalCandidateJob.php`

`type='renewal'` + `whereIn('status', ['pending', 'retrying'])` yolundaki toplu failure update model method'larına dönüştürülmeyecek.

Gerekçe:
- Çoklu satır güncellemesi yapıyor; model hydration + row iteration bu fazın hedefi değil.
- Mevcut query-builder `update([...])` semantiği model event tetiklemez; bu davranış burada bilinçli olarak korunacak.
- Gerekirse sadece ortak payload array'i local private helper'a çıkarılır; davranış aynı kalır.

---

### Phase B: Controller Duplication Elimination

`WebhookController` ve `PaymentCallbackController` arasında **birebir aynı** `resolveEventId()` ve `normalizeScalarId()` metodları var.

#### B1: Yeni Trait oluştur

**Dosya**: `src/Http/Concerns/ResolvesWebhookEventId.php` [YENİ]

Her iki controller'daki `resolveEventId()` ve `normalizeScalarId()` birebir kopyalanır.

#### B2: Controller'ları güncelle

**Dosyalar**:
- `src/Http/Controllers/WebhookController.php` → Trait `use` et, duplicate metotları sil
- `src/Http/Controllers/PaymentCallbackController.php` → Trait `use` et, duplicate metotları sil

Ek olarak: `$existingCall` retry kısmında `WebhookCall::resetForRetry()` kullanılacak.

---

### Phase C: Job Simplification via Model Methods

Phase A'da oluşturulan model metodları kullanılarak Job'lardaki inline bloklar sadeleştirilecek.

#### C1: `FinalizeWebhookEventJob.php`

5 adet inline `setAttribute+save` bloğu → tek satır çağrılar:

```diff
- $webhookCall->setAttribute('status', 'failed');
- $webhookCall->setAttribute('error_message', 'Unknown provider.');
- $webhookCall->setAttribute('processed_at', now());
- $webhookCall->save();
+ $webhookCall->markFailed('Unknown provider.');
```

Toplam: ~20 satır azalma.

#### C2: `PaymentChargeJob.php`

3 adet inline status bloğu model method'ları ile değiştirilecek:

- `processing` geçişi → `markProcessing()`
- `processed` geçişi → `markProcessed(..., providerResponse: $payload, replaceProviderResponse: true)`
- `failed` geçişi → `markFailed(..., providerResponse: $payload, replaceProviderResponse: true)`

> **Kural**: Provider response bazen `null` olabilir. Bu durumda stale `provider_response` bırakmamak için `replaceProviderResponse: true` named arg'ı explicit kullanılacak.

#### C3: `ProcessDunningRetryJob.php`

`status='retrying'` + `last_retry_at=now()` bloğu `Transaction::markRetrying()` ile değiştirilecek.

#### C4: `MeteredBillingProcessor.php`

2 adet Transaction status bloğu model method'ları ile değiştirilecek:

- Başarılı işleme → `markProcessed(...)`
- Hata/charge failure → `markFailed(...)`

`managesOwnBilling()` yolu domain boundary açısından değişmeyecek; sadece mevcut Transaction mutation kodu sadeleşecek.

#### C5: `ProcessScheduledPlanChangeJob.php`

3 adet inline status bloğu:

```diff
- $change->setAttribute('status', 'failed');
- $change->setAttribute('error_message', 'Subscription not found...');
- $change->setAttribute('processed_at', now());
- $change->save();
+ $change->markFailed('Subscription not found...');
```

---

### Phase D: LicenseManager Consistency

**Dosya**: `src/Licensing/LicenseManager.php`

#### D1: activate() — ikinci COUNT kaldır, pre-count'u reuse et

`lockForUpdate()` + transaction içinde olduğumuz için `$activeCount + 1` matematiksel olarak doğru.

```diff
 // activate() — satır 153 civarı
- $activeCount = LicenseActivation::query()
-     ->where('license_id', $license->getKey())
-     ->whereNull('deactivated_at')
-     ->count();
- $license->setAttribute('current_activations', $activeCount);
+ $license->setAttribute('current_activations', $activeCount + 1);
```

#### D2: deactivate() — COUNT korunur, arithmetic'e dönüştürülmez

`deactivate()` içinde mevcut recount davranışı bilerek korunacak.

Gerekçe:
- Persisted `current_activations` alanı daha önce drift olmuş olabilir; recount bu drift'i self-heal eder.
- Arithmetic decrement (`current_activations - 1`) DB gerçeğini değil cached alanı referans aldığı için bozuk state'i sürdürebilir.
- Bu fazın hedefi sadeleştirme olsa da, `deactivate()` tarafında doğruluk satır azaltmadan daha kritiktir.

#### D3: Counter drift regression testi ekle

**Dosya**: `tests/Feature/PhaseFourLicenseManagerTest.php`

Yeni senaryo:
- Aktif bir activation oluştur.
- `license.current_activations` alanını manuel olarak yanlış değere (örn. `99`) çek.
- `deactivate()` çağrısından sonra alanın `0`'a, aktif activation count'unun da `0`'a düştüğünü doğrula.

---

### Phase E: IyzicoProvider Decomposition (917 → ~520 satır)

#### E1: SDK Object Builder'ları Çıkar

**Dosya**: `src/Payment/Providers/Iyzico/IyzicoRequestBuilder.php` [YENİ]

Taşınacak metodlar (~200 satır):
- `buyer(array $details, ?string $ip = null): Buyer`
- `address(array $details, string $key): Address`
- `customer(array $details): Customer`
- `paymentCard(array $details): PaymentCard`
- `subscriptionPaymentCard(array $details): array`
- `basketItems(array $details): array`
- `money(int|float|string $amount): string`

> **Not**: `buyer()` şu anda gizli olarak `request()->ip()` kullanıyor. Yeni builder bu bağımlılığı scalar input ile alacak; request erişimi builder içine saklanmayacak.

#### E2: Paylaşılan Iyzico helper'larını tek support service'e çıkar

**Dosya**: `src/Payment/Providers/Iyzico/IyzicoSupport.php` [YENİ]

Taşınacak metodlar (~90 satır):
- `config(string $key, mixed $default = null): mixed`
- `mockMode(): bool`
- `missingCredentials(): bool`
- `options(): \Iyzipay\Options`
- `isSuccessfulResponse(object $response): bool`
- `decodeRawPayload(object $response): array`
- `responseError(object $response, string $fallback): string`

Bu dosya Phase E için tek helper source-of-truth olacak. Trait kullanılmayacak.

#### E3: Card Management'ı Çıkar

**Dosya**: `src/Payment/Providers/Iyzico/IyzicoCardManager.php` [YENİ]

Taşınacak metodlar (~170 satır):
- `ensureRemoteCardTokens(array $details): array`
- `listStoredCards(string $cardUserKey): array`
- `deleteStoredCard(string $cardUserKey, string $cardToken): bool`
- `cardPayload(array $details): array`

Constructor:

```php
public function __construct(
    private readonly IyzicoSupport $support,
) {}
```

Boundary kuralı: Bu sınıf sadece provider SDK konuşur; model save/query/event dispatch yapmaz.

> **Not**: `computeWebhookSignature()` kriptografik kod olduğu için olduğu yerde kalacak, dokunulmayacak.

#### E4: IyzicoProvider'ı güncelle

**Dosya**: `src/Payment/Providers/Iyzico/IyzicoProvider.php`

Constructor'a `IyzicoRequestBuilder`, `IyzicoCardManager` ve `IyzicoSupport` enjekte edilecek:

```php
public function __construct(
    private readonly IyzicoRequestBuilder $requestBuilder,
    private readonly IyzicoCardManager $cardManager,
    private readonly IyzicoSupport $support,
) {}
```

Provider içinde kalacak metotlar:
- `pay`, `refund`, `createSubscription`, `cancelSubscription`, `upgradeSubscription`, `chargeRecurring`
- `validateWebhook`, `processWebhook`, `computeWebhookSignature`
- webhook parse helper'ları ve `callbackUrl()`

Amaç: helper extraction yapmak, provider boundary'yi domain katmanına taşımamak.

> [!CAUTION]
> **KRİTİK: ServiceProvider Güncellemesi Zorunlu**
>
> Mevcut ServiceProvider (satır 86):
> ```php
> $this->app->singleton(IyzicoProvider::class, static fn (): IyzicoProvider => new IyzicoProvider);
> ```
> Bu hardcoded `new` çağrısı, constructor'a parametre eklendiğinde `ArgumentCountError` fırlatacak.
>
> **Çözüm**: `LaravelSubscriptionGuardServiceProvider.php` güncellenmeli:
> ```php
> // Yeni yardımcı sınıflar
> $this->app->singleton(IyzicoRequestBuilder::class, static fn (): IyzicoRequestBuilder => new IyzicoRequestBuilder);
> $this->app->singleton(IyzicoSupport::class, static fn (): IyzicoSupport => new IyzicoSupport);
> $this->app->singleton(IyzicoCardManager::class, fn ($app): IyzicoCardManager => new IyzicoCardManager(
>     $app->make(IyzicoSupport::class),
> ));
>
> // IyzicoProvider — artık container resolution
> $this->app->singleton(IyzicoProvider::class, fn ($app): IyzicoProvider => new IyzicoProvider(
>     $app->make(IyzicoRequestBuilder::class),
>     $app->make(IyzicoCardManager::class),
>     $app->make(IyzicoSupport::class),
> ));
> ```

---

### Phase F: SubscriptionService Decomposition (830 → ~580 satır)

#### F1: Coupon/Discount Logic → DiscountService

**Dosya**: `src/Subscription/DiscountService.php` [YENİ]

Taşınacak metodlar (~200 satır):
- `applyDiscount(int|string $subscriptionId, string $couponOrDiscountCode): DiscountResult`
- `resolveRenewalDiscount(Subscription $subscription, float $baseAmount): array`
- `markDiscountApplied(int|string $discountId): void`
- `isCouponCurrencyCompatible(Coupon, Subscription): bool`
- `isWithinPerUserLimit(Coupon, Subscription): bool`
- `appliesToSubscription(Coupon, Subscription): bool`
- `isDiscountApplicable(Discount): bool`
- `computeDiscountAmount(float, string, float, ?Coupon): float`

> [!CAUTION]
> **KRİTİK: Gizli PaymentManager Bağımlılığı**
>
> `resolveRenewalDiscount()` metodu (satır ~241) `$this->paymentManager->managesOwnBilling($provider)` çağrısı yapıyor. Bu bağımlılık `DiscountService` constructor'ına enjekte edilmeli:
>
> ```php
> final class DiscountService
> {
>     public function __construct(
>         private readonly PaymentManager $paymentManager,
>     ) {}
> }
> ```

#### F2: SubscriptionService'i güncelle

**Dosya**: `src/Subscription/SubscriptionService.php`

Constructor'a `DiscountService` enjekte edilecek:

```php
public function __construct(
    private readonly PaymentManager $paymentManager,
    private readonly ProviderEventDispatcherResolver $providerEventDispatchers,
    private readonly DiscountService $discountService,
) {}
```

`applyDiscount`, `resolveRenewalDiscount`, `markDiscountApplied` çağrıları `$this->discountService` üzerinden delegate edilecek.

Ek kurallar:
- `SubscriptionServiceInterface` bu fazda değiştirilmeyecek.
- `resolveRenewalDiscount()` ve `markDiscountApplied()` bu fazda `SubscriptionService` üzerinde **public thin-delegator** olarak kalacak; mevcut job çağrıcıları kırılmayacak.
- Private validation helper'ları yalnızca artık başka yerde kullanılmıyorsa silinecek.

> [!CAUTION]
> **KRİTİK: ServiceProvider Güncellemesi Zorunlu**
>
> Mevcut ServiceProvider (satır 97-100):
> ```php
> $this->app->singleton(SubscriptionService::class, fn (): SubscriptionService => new SubscriptionService(
>     $this->app->make(PaymentManager::class),
>     $this->app->make(ProviderEventDispatcherResolver::class),
> ));
> ```
>
> **Çözüm**: Güncellenmeli:
> ```php
> $this->app->singleton(DiscountService::class, fn ($app): DiscountService => new DiscountService(
>     $app->make(PaymentManager::class),
> ));
>
> $this->app->singleton(SubscriptionService::class, fn ($app): SubscriptionService => new SubscriptionService(
>     $app->make(PaymentManager::class),
>     $app->make(ProviderEventDispatcherResolver::class),
>     $app->make(DiscountService::class),
> ));
> ```

#### F3: Direct caller compatibility check

**Dosyalar**:
- `src/Jobs/ProcessRenewalCandidateJob.php`
- `src/Subscription/SubscriptionService.php`

Bu fazda job call site'ları `DiscountService`'e doğrudan taşınmayacak. `ProcessRenewalCandidateJob` mevcut `SubscriptionService` çağrılarını kullanmaya devam edecek; böylece public API churn engellenmiş olacak.

---

### Phase G: ServiceProvider Konsolidasyonu

**Dosya**: `src/LaravelSubscriptionGuardServiceProvider.php`

Bu faz diğer tüm fazlarla paralel olarak yürütülür — her constructor değişikliğinde eşzamanlı güncelleme yapılmalıdır.

**Değiştirilecek satırlar**:
- Satır 86: `IyzicoProvider` singleton → constructor injection
- Satır 97-100: `SubscriptionService` singleton → `DiscountService` eklenmesi
- Yeni singleton binding'ler: `IyzicoRequestBuilder`, `IyzicoSupport`, `IyzicoCardManager`, `DiscountService`

#### G1: Container smoke testi ekle

**Dosya**: `tests/Feature/PhaseSevenContainerResolutionTest.php` [YENİ]

Doğrulanacaklar:
- `app(\ByTIC\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider::class)` resolve olur.
- `app(\ByTIC\LaravelSubscriptionGuard\Subscription\SubscriptionService::class)` resolve olur.
- `app(\ByTIC\LaravelSubscriptionGuard\Payment\PaymentManager::class)->provider('iyzico')` resolve olur.

---

## 4) `computeWebhookSignature` Hakkında Not

Önceki planın F7-REP-003 maddesi (imza hesaplamasını birleştirme) bu plandan **çıkarılmıştır**.

Gerekçe:
- Kriptografik kodda her branch farklı mesaj formatı kullanıyor
- Branch sırası güvenlik açısından kritik (early-return pattern)
- Mevcut yapı zaten yeterince okunabilir
- Risk/kazanç oranı çok düşük

---

## 5) Etkilenen Dosyalar Özeti

| Dosya | İşlem | Faz |
|---|---|---|
| `src/Models/WebhookCall.php` | 3 method ekle | A |
| `src/Models/Transaction.php` | 4 method ekle | A |
| `src/Models/ScheduledPlanChange.php` | 2 method ekle | A |
| `src/Jobs/ProcessRenewalCandidateJob.php` | Bulk query update korunur / event semantiği notu | A |
| `src/Http/Concerns/ResolvesWebhookEventId.php` | YENİ | B |
| `src/Http/Controllers/WebhookController.php` | Trait use, method sil | B |
| `src/Http/Controllers/PaymentCallbackController.php` | Trait use, method sil | B |
| `src/Jobs/FinalizeWebhookEventJob.php` | Model method çağrı | C |
| `src/Jobs/PaymentChargeJob.php` | Model method çağrı | C |
| `src/Jobs/ProcessDunningRetryJob.php` | `markRetrying()` kullan | C |
| `src/Billing/MeteredBillingProcessor.php` | Model method çağrı | C |
| `src/Jobs/ProcessScheduledPlanChangeJob.php` | Model method çağrı | C |
| `src/Licensing/LicenseManager.php` | Counter tutarlılık | D |
| `tests/Feature/PhaseFourLicenseManagerTest.php` | Drift-regression test ekle | D |
| `src/Payment/Providers/Iyzico/IyzicoRequestBuilder.php` | YENİ | E |
| `src/Payment/Providers/Iyzico/IyzicoSupport.php` | YENİ | E |
| `src/Payment/Providers/Iyzico/IyzicoCardManager.php` | YENİ | E |
| `src/Payment/Providers/Iyzico/IyzicoProvider.php` | Decompose, constructor DI | E |
| `src/Subscription/DiscountService.php` | YENİ | F |
| `src/Subscription/SubscriptionService.php` | Decompose, constructor DI | F |
| `src/LaravelSubscriptionGuardServiceProvider.php` | Binding güncelle | G |
| `tests/Feature/PhaseSevenContainerResolutionTest.php` | YENİ | G |
| `tests/Unit/Models/WebhookCallStateTransitionTest.php` | YENİ | 6 (Verification) |
| `tests/Unit/Models/TransactionStateTransitionTest.php` | YENİ | 6 (Verification) |
| `tests/Unit/Models/ScheduledPlanChangeStateTransitionTest.php` | YENİ | 6 (Verification) |

---

## 6) Verification Plan

### Automated Tests

Sabit test/adet assertion sayısına bağlı kontrol yapılmayacak; bu faz yeni testler eklediği için başarı kriteri **davranışsal regresyon yokluğu + tam suite pass** olacak.

```bash
# Tam suite
composer test

# Statik analiz
composer analyse

# Hedefli regresyonlar
./vendor/bin/pest \
  tests/Feature/PhaseOneWebhookFlowTest.php \
  tests/Feature/PhaseOneBillingOrchestrationTest.php \
  tests/Feature/PhaseThreePreflightTest.php \
  tests/Feature/PhaseFourLicenseManagerTest.php \
  tests/Feature/PhaseFiveCouponDiscountClosureTest.php \
  tests/Feature/PhaseSixMassAssignmentHardeningTest.php \
  tests/Feature/PhaseTwoIyzicoProviderTest.php \
  tests/Feature/PhaseSevenContainerResolutionTest.php \
  tests/ArchTest.php
```

Her phase sonunda önce ilgili hedefli testler, sonra `composer analyse`, en sonda `composer test` çalıştırılacak.

### Yeni testler (zorunlu)

- `tests/Unit/Models/WebhookCallStateTransitionTest.php` → `markFailed`, `markProcessed`, `resetForRetry`
- `tests/Unit/Models/TransactionStateTransitionTest.php` → `markFailed`, `markProcessing`, `markRetrying`, `markProcessed`, özellikle `provider_response` clear/preserve semantiği
- `tests/Unit/Models/ScheduledPlanChangeStateTransitionTest.php` → `markFailed`, `markProcessed`
- `tests/Feature/PhaseSevenContainerResolutionTest.php` → DI binding smoke test

### Doğrudan Etkilenen Test Dosyaları

- `PhaseOneWebhookFlowTest.php` → Webhook intake, idempotency, FinalizeWebhookEventJob
- `PhaseTwoIyzicoProviderTest.php` → IyzicoProvider pay/refund/subscription/webhook
- `PhaseFourLicenseManagerTest.php` → License activate/deactivate
- `PhaseFourOperationsTest.php` → Subscription operations
- `PhaseFiveCouponDiscountClosureTest.php` → Discount/coupon logic
- `PhaseOneBillingOrchestrationTest.php` → Renewal, dunning, plan changes
- `PhaseThreePreflightTest.php` → PaymentChargeJob, renewal failure, webhook/payment sonuçları
- `PhaseSixMassAssignmentHardeningTest.php` → Mass-assignment guards
- `ArchTest.php` → Provider/domain boundary kuralları

---

## 7) Riskler ve Önlemler

| Risk | Etki | Olasılık | Önlem |
|---|---|---|---|
| ServiceProvider binding eksik/yanlış → `ArgumentCountError` | **Fatal** | Yüksek (eğer unutulursa) | Her constructor değişikliğinde ServiceProvider eşzamanlı güncelleme |
| DiscountService'in PaymentManager bağımlılığı unutulması | **Fatal** | Yüksek (eğer unutulursa) | Constructor'da explicit dependency, ServiceProvider'da binding |
| `Transaction` transition method'larında stale `provider_response` bırakılması | Yüksek | Orta | `replaceProviderResponse` parametresi + unit test |
| `ProcessRenewalCandidateJob` bulk update yolunun row-iteration'a çevrilmesi | Yüksek | Orta | Query-builder update out-of-scope notu + review checklist |
| Guard-aware olmayan update dönüşümü | Yüksek | Orta | Guard kontrol checklist + `PhaseSixMassAssignmentHardeningTest` regressions |
| `deactivate()` tarafında arithmetic decrement ile counter drift'in korunması | Yüksek | Orta | Recount davranışını koru + drift-regression test |
| Shared helper (`options()`, `config()`) duplikasyonu yeni sınıflarda | Orta | Orta | `IyzicoSupport` tek source-of-truth |
| `SubscriptionService` public API'nin istemeden daraltılması | Yüksek | Düşük | Thin-delegator pattern + direct caller compatibility check |

---

## 8) Readability Kabul Metrikleri

Her aday aşağıdaki metriklerle değerlendirilecek:
- **R1 (Niyet Netliği)**: Kodu ilk okuyan geliştirici 30 saniyede akışı anlayabiliyor mu?
- **R2 (Tekrar Azaltma)**: Aynı state transition veya return yapısı kaç yerde tekrar ediyor?
- **R3 (Branch Sadelik)**: Erken return/guard clause ile iç içe koşul derinliği azaldı mı?
- **R4 (Testlenebilirlik)**: Çıkarılan helper bağımsız testlenebilir mi?
- **R5 (Güvenlik Uyum)**: Faz 6 guard/idempotency/lock korumaları aynen korunuyor mu?
- **R6 (Sınır Koruma)**: Provider/domain/service sınırları ve mevcut public API'ler korunuyor mu?

Minimum kabul: R1, R5 ve R6 zorunlu geçer, toplam skor >= 22/30.

---

## 9) Faz 7 Artefaktları

- `docs/plans/phase-7-code-simplification/plan.md` (bu dosya)
- `docs/plans/phase-7-code-simplification/work-results.md`
- `docs/plans/phase-7-code-simplification/risk-notes.md`
- Güncellenmiş `docs/plans/master-plan.md`

---

## 10) Not

Bu döküman kod tabanındaki doğrulanmış guard, event, lock, DI ve boundary kısıtları ile hizalanmış uygulanabilir plan aşamasıdır. Bu adımda refactor kodu yazılmaz.
