# Faz 1: Core Infrastructure

> **Süre**: 4 Hafta
> **Durum**: Planlama
> **Bağımlılıklar**: Yok

---

## Özet

Paketin temel altyapısını oluştur: veritabanı şeması, modeller, interface'ler, configuration.

---

## Hedefler

1. Veritabanı şemasını oluştur (tüm tablolar)
2. Temel modeller ve ilişkileri tanımla
3. Interface/contract tanımlarını oluştur
4. Service provider kayıtlarını yap
5. Configuration dosyasını oluştur

---

## Veritabanı Şeması

### plans Tablosu
- Plan tanımları (name, slug, price, currency)
- Özellikler (features JSON)
- Limitler (limits JSON)
- Billing period (monthly, yearly)

### licenses Tablosu
- Lisans kayıtları (key, status, expires_at)
- Plan ilişkisi
- Feature ve limit overrides
- Activation tracking

### subscriptions Tablosu
- Abonelik kayıtları
- Polymorphic relation (subscribable)
- Provider bilgileri
- `trial_ends_at` (deneme süresi bitişi)
- `next_billing_date` (self-managed provider renewals için)
- `resumes_at` (paused aboneliklerin otomatik devam tarihi)
- Status yönetimi
- Grace period desteği
- Soft deletes

### subscription_items Tablosu
- Çoklu plan desteği
- Quantity (seat-based billing)

### transactions Tablosu
- Ödeme işlemleri
- Tax bilgileri (KDV)
- Idempotency key
- `provider_transaction_id` ve `idempotency_key` üzerinde unique güvence
- PayTR için `merchant_oid` idempotency anahtarı olarak normalize edilir
- Dunning alanları: `retry_count`, `next_retry_at`, `last_retry_at`
- Provider response
- Soft deletes

### payment_methods Tablosu
- Kayıtlı kartlar
- Card tokens (provider_card_token, provider_customer_token)
- Masked card info
- Soft deletes

### webhook_calls Tablosu
- Webhook logları
- Idempotency için event_id
- Payload ve headers

### coupons Tablosu
- Kupon tanımları
- Discount types (percentage, fixed, free_trial)
- Usage limits

### discounts Tablosu
- Uygulanan indirimler
- Polymorphic relation

### scheduled_plan_changes Tablosu
- Plan değişiklik planlaması
- Proration strategy

### license_usages Tablosu
- Kullanım logları (metered billing için)

---

## Modeller

| Model | İlişkiler |
|-------|-----------|
| Plan | hasMany(License), hasMany(Subscription) |
| License | belongsTo(Plan), belongsTo(User), hasMany(LicenseUsage) |
| Subscription | belongsTo(Plan), morphTo(subscribable), hasMany(SubscriptionItem), hasMany(Transaction) |
| SubscriptionItem | belongsTo(Subscription), belongsTo(Plan) |
| Transaction | belongsTo(Subscription), morphTo(payable) |
| PaymentMethod | morphTo(payable) |
| WebhookCall | - |
| Coupon | hasMany(Discount) |
| Discount | morphTo(discountable) |
| ScheduledPlanChange | belongsTo(Subscription) |
| LicenseUsage | belongsTo(License) |

---

## Interface Tanımları

### PaymentProviderInterface
- getName(): string
- managesOwnBilling(): bool
- pay(amount, details): PaymentResponse
- refund(transactionId, amount): RefundResponse
- createSubscription(plan, details): SubscriptionResponse
- cancelSubscription(subscriptionId): bool
- upgradeSubscription(subscriptionId, newPlan): SubscriptionResponse
- chargeRecurring(subscription, amount): PaymentResponse
- validateWebhook(payload, signature): bool
- processWebhook(payload): WebhookResult

**Provider davranış sözleşmesi**:
- `managesOwnBilling() = true` -> provider renewal döngüsünü kendi yönetir (iyzico)
- `managesOwnBilling() = false` -> renewal döngüsü paket içinde scheduler ile yönetilir (PayTR)

### LicenseManagerInterface
- generate(planId, userId): License
- validate(licenseKey): ValidationResult
- activate(licenseKey, domain): bool
- deactivate(licenseKey, domain): bool
- checkFeature(licenseKey, feature): bool
- checkLimit(licenseKey, limit): int

### SubscriptionServiceInterface
- create(userId, planId, paymentMethodId): Subscription
- cancel(subscriptionId): bool
- pause(subscriptionId): bool
- resume(subscriptionId): bool
- upgrade(subscriptionId, newPlanId, mode): bool
- downgrade(subscriptionId, newPlanId): bool
- processRenewals(date): int
- processDunning(date): int
- retryPastDuePayments(subscribableId): int

**Upgrade mode sözleşmesi**:
- `mode = now` -> anlık plan geçişi denemesi
- `mode = next_period` -> dönem sonunda plan geçişi

**Self-managed provider (PayTR) için `mode=now` kuralı**:
- Lokal proration hesabı yapılır (kullanılmamış eski plan kredisi)
- Fark tutar `chargeRecurring()` ile anında tahsil edilir
- Tahsilat başarılıysa `plan_id` hemen güncellenir
- Tahsilat başarısızsa plan değişikliği uygulanmaz, mevcut plan korunur

### FeatureGateInterface
- can(user, feature): bool
- limit(user, limit): int
- incrementUsage(user, limit): bool

---

## Configuration

### subscription-guard.php
- Provider tanımları (iyzico, paytr, null)
- Default provider
- License settings (crypto, validation)
- Grace period settings
- Webhook settings
- Feature gates

### Provider Billing Strategy Config
- `providers.iyzico.manages_own_billing = true`
- `providers.paytr.manages_own_billing = false`
- `billing.renewal_command = subguard:process-renewals`
- `billing.dunning_command = subguard:process-dunning`

---

## Internal Billing Scheduler Temeli

### Komutlar
- `subguard:process-renewals`
  - `next_billing_date <= bugün` ve `managesOwnBilling=false` abonelikleri işler
  - Başarılı tahsilatta `next_billing_date` bir sonraki döneme kayar
- `subguard:process-dunning`
  - `next_retry_at <= bugün` başarısız tahsilatları tekrar dener
  - Retry policy: 2 / 5 / 7 gün

### Domain Kuralları
- Provider-managed provider'lar (iyzico) renewal job kapsamı dışındadır
- Self-managed provider'lar (PayTR) renewal job kapsamına alınır
- `paused` durumundaki abonelikler renewal sürecinden geçmez

### Trial Kuralı (Self-Managed Provider)
- Trial başlatılırken kart saklama yapılır, düzenli tahsilat yapılmaz
- `next_billing_date = trial_ends_at` olarak set edilir
- Renewal job, `next_billing_date` gelmeden charge denemesi yapmaz

### Race Condition Kuralı (Sync Charge vs Webhook)
- Synchronous charge sonucu başarılı işlense bile webhook tekrar aynı işlemi getirebilir
- Bu nedenle transaction işleme tekilliği zorunludur:
  - `provider_transaction_id` unique
  - `idempotency_key` unique
- Webhook handler duplicate işlem tespitinde state değiştirmeden 200 döner

### Single-Writer Webhook Prensibi
- Webhook endpoint'in tek görevi: signature doğrulama + kuyruğa alma + hızlı 200 dönüş
- Finansal state değişimi sadece tek işleyici akışında yapılır (queue worker / payment processor)
- Hem senkron job hem webhook aynı işlem id'sinde aynı mutasyon akışına yönlenir

---

## Service Provider

### LaravelSubscriptionGuardServiceProvider
- Config publish
- Migrations publish
- Views publish (opsiyonel)
- Singleton bindings (PaymentManager, LicenseManager)
- Interface bindings

---

## Çıktılar

- [ ] Migration dosyaları (11 tablo)
- [ ] Model sınıfları (11 model)
- [ ] Interface dosyaları (4 interface)
- [ ] Config dosyası
- [ ] Service provider

---

## Test Kriterleri

- [ ] Tüm migration'lar başarılı çalışıyor
- [ ] Model ilişkileri doğru tanımlanmış
- [ ] Interface'ler type-hint edilebilir
- [ ] Config publish edilebilir
- [ ] Service provider boot oluyor

---

## Riskler ve Notlar

| Risk | Etki | Öneri |
|------|------|-------|
| Migration çakışması | Orta | Prefix kullan: subscription_guard_ |
| Polymorphic relation karmaşıklığı | Düşük | Dokümantasyon ile açıklama |
| Soft delete unutulması | Yüksek | Migration template'lerde zorunlu kıl |

---

## Sonraki Faz

Faz 2: iyzico Provider (bu faz tamamlandıktan sonra başlayacak)
