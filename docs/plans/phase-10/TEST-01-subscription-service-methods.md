# TEST-01: SubscriptionService Doğrudan Method Testleri

## Problem

`src/Subscription/SubscriptionService.php`'deki 6 public method doğrudan test edilmiyor. Testlerde abonelikler raw DB query ile oluşturuluyor ve service layer atlanıyor.

Test edilmemiş methodlar:
- `create(subscribableId, planId, paymentMethodId)` - satır 43-63
- `cancel(subscriptionId)` - satır 66-78
- `pause(subscriptionId)` - satır 80-92
- `resume(subscriptionId)` - satır 94-105
- `upgrade(subscriptionId, newPlanId, mode)` - satır 107-137
- `downgrade(subscriptionId, newPlanId)` - satır 139-142

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenSubscriptionServiceTest.php`

## Test Senaryoları

### create() testleri

```
it creates a subscription with pending status and correct attributes
```
- Plan oluştur (factory veya manual insert)
- `SubscriptionService::create($userId, $planId, $paymentMethodId)` çağır
- Dönen array'de `status = pending` olduğunu doğrula
- `subscribable_type`, `subscribable_id`, `plan_id`, `amount`, `currency`, `billing_period` doğrula
- `metadata.payment_method_id` doğrula
- DB'de kayıt oluştuğunu doğrula

```
it uses default provider from payment manager
```
- Config'de default provider = 'iyzico' olsun
- `create()` çağır
- Oluşan subscription'ın `provider = 'iyzico'` olduğunu doğrula

```
it throws exception when plan does not exist
```
- Var olmayan `planId` ile `create()` çağır
- `ModelNotFoundException` fırlatıldığını doğrula

### cancel() testleri

```
it cancels an active subscription
```
- Active subscription oluştur
- `cancel($id)` çağır → `true` döndüğünü doğrula
- DB'de `status = cancelled`, `cancelled_at` not null doğrula

```
it returns false when subscription does not exist
```
- Var olmayan ID ile `cancel()` çağır → `false` döndüğünü doğrula

```
it cancels a past_due subscription
```
- Past_due subscription oluştur
- `cancel($id)` çağır → `true`
- Status = cancelled doğrula

### pause() testleri

```
it pauses an active subscription
```
- Active subscription oluştur
- `pause($id)` çağır → `true`
- Status = paused doğrula

```
it returns false when subscription does not exist
```
- `pause(999999)` → `false`

### resume() testleri

```
it resumes a paused subscription
```
- Paused subscription oluştur (status=paused, resumes_at set)
- `resume($id)` çağır → `true`
- Status = active, resumes_at = null doğrula

```
it returns false when subscription does not exist
```
- `resume(999999)` → `false`

### upgrade() testleri

```
it creates a scheduled plan change for next_period mode
```
- Active subscription + iki plan oluştur
- `upgrade($subId, $newPlanId, 'next_period')` çağır → `true`
- `scheduled_plan_changes` tablosunda kayıt doğrula:
  - `from_plan_id`, `to_plan_id`, `change_type = 'upgrade'`
  - `scheduled_at` = subscription'ın `current_period_end` veya `next_billing_date`
  - `status = 'pending'`
- Subscription'ın `scheduled_change_id` set edildiğini doğrula

```
it creates immediate plan change for now mode
```
- `upgrade($subId, $newPlanId, 'now')` çağır
- `scheduled_at` ≈ now() doğrula

```
it returns false for invalid mode
```
- `upgrade($subId, $newPlanId, 'invalid_mode')` → `false`

```
it returns false when subscription does not exist
```
- `upgrade(999999, $planId)` → `false`

### downgrade() testleri

```
it delegates to upgrade with next_period mode
```
- `downgrade($subId, $newPlanId)` çağır
- `scheduled_plan_changes` tablosunda `change_type` değerini doğrula
- `scheduled_at` = subscription'ın period end date doğrula

### applyDiscount() testleri

```
it delegates to discount service and returns DiscountResult
```
- Coupon oluştur
- `applyDiscount($subId, $couponCode)` çağır
- Dönen `DiscountResult`'ın `success = true` olduğunu doğrula

### processRenewals() testleri

```
it dispatches renewal jobs for due subscriptions that are not provider-managed
```
- next_billing_date geçmiş, active, provider=paytr (manages_own_billing=false) subscription oluştur
- `processRenewals(now())` çağır
- `ProcessRenewalCandidateJob` dispatch edildiğini doğrula
- Return değeri = 1

```
it skips provider-managed subscriptions
```
- Provider=iyzico (manages_own_billing=true) subscription oluştur
- `processRenewals(now())` çağır
- Return değeri = 0
- Job dispatch edilmediğini doğrula

```
it skips subscriptions with future billing date
```
- next_billing_date = gelecek tarih, active subscription oluştur
- `processRenewals(now())` çağır → 0

### processDunning() testleri

```
it dispatches dunning jobs for failed transactions ready for retry
```
- Status=failed, next_retry_at <= now, retry_count < 3 transaction oluştur
- `processDunning(now())` çağır
- `ProcessDunningRetryJob` dispatch edildiğini doğrula

```
it skips transactions that exceeded max retries
```
- retry_count = 3 transaction oluştur
- `processDunning(now())` çağır → 0

### handlePaymentResult() testleri

```
it activates subscription and advances billing date on success
```
- Pending subscription oluştur
- Başarılı `PaymentResponse` ile `handlePaymentResult()` çağır
- Status = active, grace_ends_at = null, next_billing_date ilerlemiş doğrula
- `PaymentCompleted` event dispatch edildiğini doğrula
- `SubscriptionRenewed` event dispatch edildiğini doğrula

```
it sets past_due status and grace period on failure
```
- Active subscription oluştur
- Başarısız `PaymentResponse` ile `handlePaymentResult()` çağır
- Status = past_due, grace_ends_at set doğrula
- `PaymentFailed` event dispatch edildiğini doğrula

```
it is idempotent with same payment response
```
- Aynı `PaymentResponse`'u iki kez `handlePaymentResult()`'a ver
- İkinci çağrıda yeni transaction oluşmadığını doğrula (idempotency_key)

## Test Altyapısı

Her test `RefreshDatabase` trait'i kullanacak. `SubscriptionService` container'dan resolve edilecek:

```php
$service = app(SubscriptionServiceInterface::class);
```

Plan ve Subscription oluşturmak için `Subscription::unguarded()` ve `Plan::unguarded()` kullanılacak (mevcut test pattern'ini takip ederek).

## Doğrulama

1. Tüm yeni testler geçiyor
2. Mevcut testlerle çakışma yok
3. `composer test` → tüm testler geçiyor
