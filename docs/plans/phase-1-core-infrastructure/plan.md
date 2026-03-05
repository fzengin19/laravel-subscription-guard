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
6. Execution kickoff için ilk implementation commit temelini hazırla (migrations + interfaces)
7. Faz 0 bootstrap tamamla (Orchestra Testbench + Pest + CI iskeleti)

---

## Veritabanı Şeması

### plans Tablosu
- Plan tanımları (name, slug, price, currency)
- Özellikler (features JSON)
- Limitler (limits JSON)
- Billing period (monthly, yearly)
- Provider mapping alanları (iyzico_product_reference, iyzico_pricing_plan_reference)

### licenses Tablosu
- Lisans kayıtları (key, status, expires_at)
- Plan ilişkisi
- Feature ve limit overrides
- max_activations, current_activations
- domain binding politikası için alanlar (veya ayrı activation tablosu)

### license_activations Tablosu
- License activation geçmişi
- domain, ip, activated_at, deactivated_at
- Cihaz/domain bazlı lifecycle takibi

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

### invoices Tablosu
- Faturalama kayıtları (transaction'dan bağımsız domain obje)
- invoice_number, issue_date, due_date, status
- subtotal, tax_amount, total_amount, currency
- transaction_id, subscribable polymorphic ilişki
- E-Fatura event tetikleme için referans alanlar

### payment_methods Tablosu
- Kayıtlı kartlar
- Card tokens (provider_card_token, provider_customer_token)
- Masked card info
- Soft deletes

### webhook_calls Tablosu
- Webhook logları
- Idempotency için event_id
- Payload ve headers
- State machine: pending -> processing -> processed/failed/ignored
- processed_at, error_message

### billing_profiles Tablosu
- Billable entity için fatura bilgileri
- billable_type, billable_id (polymorphic)
- company_name, tax_office, tax_id, billing_address
- city, country, zip, phone

### coupons Tablosu
- Kupon tanımları
- Discount types (percentage, fixed, free_trial)
- Usage limits

### discounts Tablosu
- Uygulanan indirimler
- Polymorphic relation
- `duration`: once | forever | repeating
- `duration_in_months`: repeating için zorunlu
- `applied_cycles`: renewal sırasında düşüm takibi

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
| Invoice | belongsTo(Transaction), morphTo(subscribable) |
| BillingProfile | morphTo(billable) |
| LicenseActivation | belongsTo(License) |

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
- applyDiscount(subscriptionId, couponOrDiscountCode): DiscountResult
- processRenewals(date): int
- processDunning(date): int
- processScheduledPlanChanges(date): int
- retryPastDuePayments(subscribableId): int
- handleWebhookResult(result, provider): void
- handlePaymentResult(result, subscription): void

### BillingProfileInterface
- getBillingProfile(): BillingProfileData
- hasBillingProfile(): bool

### Billable Trait / Concern
- Kullanıcı veya Team/Organization modeline eklenebilir
- `morphOne(BillingProfile::class, 'billable')`
- iyzico Buyer mapping için canonical veri kaynağı

### BillingProfile Senkronizasyon Kuralı
- Billable owner (`name`, `email`) alanları değiştiğinde BillingProfile snapshot alanları senkron güncellenir
- Senkronizasyon lifecycle: owner `saved` eventi sonrası `SyncBillingProfileJob`
- Buyer payload üretimi sırasında owner + profile merge edilerek stale veri riski engellenir

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

## Sorumluluk Sınırı (Service vs Provider)

### SubscriptionService (Orchestrator)
- Domain akışını yönetir: plan seçimi, local state, event dispatch
- Provider seçimini `PaymentManager` üzerinden yapar
- Redirect/hosted akışlarda response envelope üretir

### PaymentProvider (Gateway Adapter)
- Gateway spesifik API çağrılarını yapar
- İmza doğrulama ve provider response parse eder
- `createSubscription`/`upgradeSubscription` gateway kapasitesine göre davranır

### Zorunlu Mimari Kısıtlar (Faz 1 Contract)
- Provider adapter **DB state mutate etmez** (`Subscription`, `Transaction`, `PaymentMethod`)
- Provider adapter **domain event dispatch etmez**
- `processWebhook(payload)` sadece normalize DTO (`WebhookResult`) döndürür
- Domain state değişimleri sadece `SubscriptionService` orchestration katmanında yapılır
- `FinalizeWebhookEventJob` provider-agnostic kalır, provider-specific domain if/else içermez
- Generic billing event katmanı (`src/Events`) Faz 2/3 implementasyonunun zorunlu girdisidir

Bu sözleşmenin detay tasarımı: `docs/plans/2026-03-04-manages-own-billing-architecture-design.md`

### Akış Matrisi
- Direct charge: Service -> Provider pay -> sync response -> local transaction
- 3DS/Hosted: Service -> Provider init -> redirect/token response -> callback/webhook finalize
- Provider-managed recurring (iyzico): local renewal charge yok, webhook state sync var
- Self-managed recurring (PayTR): scheduler ile `chargeRecurring` çağrısı var

---

## Faz 0 Bootstrap (Zorunlu)

### Amaç
- Kodlamaya başlamadan test/harness altyapısını kurmak

### Yapılacaklar
- `orchestra/testbench` kur
- `pestphp/pest` kur ve baseline test çalıştır
- CI workflow iskeleti oluştur (PHP 8.4, Laravel 11/12 matrix)

### Çıkış Kriteri
- Testbench ile package boot test'i geçer
- `composer test` minimum smoke testi geçer

---

## Configuration

### subscription-guard.php
- Provider tanımları (iyzico, paytr, null)
- Default provider
- License settings (crypto, validation)
- Grace period settings
- Webhook settings
- Feature gates

### Logging Config
- `logging.channels.subguard_payments`
- `logging.channels.subguard_webhooks`
- `logging.channels.subguard_licenses`

### Provider Billing Strategy Config
- `providers.iyzico.manages_own_billing = true`
- `providers.paytr.manages_own_billing = false`
- `billing.renewal_command = subguard:process-renewals`
- `billing.dunning_command = subguard:process-dunning`
- `queue.connection = database|redis`
- `queue.queue = subguard-billing`
- `queue.webhooks_queue = subguard-webhooks`
- `queue.notifications_queue = subguard-notifications`

---

## Internal Billing Scheduler Temeli

### Komutlar
- `subguard:process-renewals`
  - `next_billing_date <= bugün` ve `managesOwnBilling=false` abonelikleri işler
  - Ağır tahsilat işi doğrudan komutta yapılmaz, queue job dispatch edilir
- `subguard:process-dunning`
  - `next_retry_at <= bugün` başarısız tahsilatları tekrar dener
  - Retry policy: 2 / 5 / 7 gün
  - Her retry denemesi queued job olarak çalışır
- `subguard:suspend-overdue`
  - grace süresi dolmuş abonelikleri suspend eder
  - lisans statüsünü subscription statüsü ile senkronlar
- `subguard:process-plan-changes`
  - `scheduled_plan_changes` kaydını dönem sonunda uygular
  - upgrade/downgrade state geçişini tek noktadan yönetir

### Domain Kuralları
- Provider-managed provider'lar (iyzico) renewal job kapsamı dışındadır
- Self-managed provider'lar (PayTR) renewal job kapsamına alınır
- `paused` durumundaki abonelikler renewal sürecinden geçmez
- Renewal job ve plan-change job aynı subscription için eşzamanlı mutasyon yapmaz
- Dunning retry ve suspend-overdue komutlarının sorumlulukları ayrıdır
- Banka taksiti (tek çekim) ile manuel taksit (çoklu çekim) ayrı akıştır
- Tek çekim yıllık planlarda `next_billing_date` plan periyoduna göre +1 yıl set edilir

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

### Job-Level Concurrency Koruması
- Scheduler komutları `withoutOverlapping()` ile çalışır
- Subscription bazlı distributed lock (`cache()->lock`) kullanılır
- Charge ve state update DB transaction + `SELECT ... FOR UPDATE` altında yürür

---

## Queue & Job Orchestration

### Job Türleri
- `ProcessRenewalCandidateJob`
- `PaymentChargeJob`
- `ProcessDunningRetryJob`
- `FinalizeWebhookEventJob`
- `DispatchBillingNotificationsJob`

### Prensipler
- Scheduler sadece adayları seçer, finansal mutasyonu queue job yapar
- Her job idempotent key ile çalışır
- Job retry/backoff politikası açıkça tanımlanır
- `afterCommit` kuralı: notification/event dispatch işlemleri commit sonrası tetiklenir

### Horizon Politikası
- Horizon v1 kapsam dışıdır (package dependency değil)
- Queue gözlemleme Laravel queue worker metrikleri ile yürütülür

### Single-Writer Webhook Prensibi
- Webhook endpoint'in tek görevi: signature doğrulama + kuyruğa alma + hızlı 200 dönüş
- Finansal state değişimi sadece tek işleyici akışında yapılır (queue worker / payment processor)
- Hem senkron job hem webhook aynı işlem id'sinde aynı mutasyon akışına yönlenir

---

## Webhook Route Kaydı Stratejisi

### Paket Tarafı Route Registration
- Paket, webhook endpointlerini otomatik kaydeder (prefix config ile)
- Örnek prefix: `/subguard/webhooks/{provider}`
- Varsayılan middleware: `api` (CSRF dışı)

### CSRF Politikası
- Webhook route'ları `web` middleware altında çalıştırılmaz
- v1'de webhook route modu sabittir: `api` middleware + CSRF hariç

### Override Seçeneği
- v1'de manuel route override desteklenmez
- Paket auto-route kaydı ile tek route sözleşmesi kullanır

---

## Exception Hiyerarşisi

- SubGuardException (base)
- ProviderException
- PaymentFailedException
- InsufficientFundsException
- WebhookSignatureException
- LicenseException
- LicenseRevokedException
- UnsupportedProviderOperationException

---

## Rate Limiting & Audit Baseline

### Rate Limiting
- License validation endpoint'leri rate-limit edilir
- Varsayılan: `throttle:license-validation` (configurable)

### Audit Logging
- Finansal state değişimleri için immutable audit trail tutulur
- Laravel native logging varsayılan
- `spatie/laravel-activitylog` v1 kapsam dışıdır

---

## Service Provider

### LaravelSubscriptionGuardServiceProvider
- Config publish
- Migrations publish
- Views publish (v1 kapsam dışı)
- Singleton bindings (PaymentManager, LicenseManager)
- Interface bindings
- Package route registration (webhook + callback)
- Install komutu kaydı (`subguard:install`)

---

## Multi-Tenant Hazırlığı

- `subscribable` ve `billable` polymorphic ilişkiler Team/Organization için hazır tutulur
- Team/Organization modeli v1 kapsam dışıdır; şema v2 geçişine hazır bırakılır

---

## Çıktılar

- [ ] Migration dosyaları (11 tablo)
- [ ] Model sınıfları (Invoice, BillingProfile, LicenseActivation dahil)
- [ ] Interface dosyaları (BillingProfileInterface dahil)
- [ ] Config dosyası
- [ ] Service provider
- [ ] Webhook route registration ve CSRF stratejisi
- [ ] Exception hiyerarşisi
- [ ] İlk implementation commit: migrations + interfaces

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

### Faz 1 -> Faz 2 Geçiş Kapısı (Retrofit Kuralı)
- Faz 2 sırasında Faz 1 contract ihlali tespit edilirse (provider mutation/event dispatch gibi), ilgili altyapı düzeltmesi Faz 2 kapanış kriterine dahil edilir
- "Faz 1 tamam" kararı, bu contract'ın aktif uygulanmasına engel değildir
