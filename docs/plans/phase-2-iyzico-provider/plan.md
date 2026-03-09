# Faz 2: iyzico Provider

> **Süre**: 4 Hafta
> **Durum**: Tamamlandı (2026-03-04)
> **Bağımlılıklar**: Faz 1 (Core Infrastructure)

---

## Özet

iyzico PHP SDK entegrasyonu: ödeme akışları, abonelik işlemleri, kart saklama, webhook handling.

---

## Hedefler

1. iyzico SDK entegrasyonu
2. Ödeme akışları (Non-3DS, 3DS, CheckoutForm)
3. Abonelik (recurring) işlemleri
4. Kart saklama (card storage)
5. Webhook handling
6. Plan upgrade/downgrade

---

## iyzico SDK Yapısı

### Bağımlılık
- `iyzico/iyzipay-php: ^2.x`

### Kullanılacak Sınıflar
- Options (API credentials)
- Payment (Non-3DS)
- ThreedsPayment (3D Secure)
- CheckoutForm (Form-based)
- Subscription\* (Recurring)
- CardStorage (Card tokens)

---

## Ödeme Akışları

### 1. Non-3DS Payment
- Direkt API üzerinden ödeme
- Kart bilgileri backend'e gelir
- Payment::create()

### 2. 3D Secure Payment
- 3D doğrulama gerekli
- ThreedsPayment::initialize() → redirect
- ThreedsPayment::auth() → callback

### 3. CheckoutForm
- iyzico hosted form
- CheckoutForm::initialize() → token
- CheckoutForm::retrieve() → callback

---

## Abonelik İşlemleri

### Subscription Create
- Subscription\SubscriptionCreateRequest
- Plan → iyzico PricingPlan mapping

### Provider Billing Model (iyzico)
- iyzico provider-managed billing kullanır
- Renewal tahsilat döngüsü iyzico tarafında yürür
- Paket tarafı webhook event'leri ile local state senkronize eder
- `subguard:process-renewals` iyzico aboneliklerini doğrudan charge etmez

### Subscription Upgrade
- Subscription\SubscriptionUpgrade::update()
- upgradePeriod: NOW / NEXT_PERIOD

### Subscription Cancel
- Subscription\SubscriptionCancel::cancel()

### Subscription Card Update
- Subscription card-update checkoutform
- 1 TL validation + refund

### Card Update ile Recovery (iyzico)
- iyzico provider-managed billing kullandığı için recovery provider tarafında yürür
- Kart güncelleme sonrası olası recovery sonucu `subscription.order.*` webhook event'leri ile local state'e yansıtılır
- Paket tarafında manuel recurring charge tetiklenmez

---

## Kart Saklama

### Card Storage API
- CardStorage::create()
- Returns: cardUserKey + cardToken

### Recurring Payment
- setCardUserKey() + setCardToken()
- Kayıtlı karttan çekim

---

## Webhook Handling

### Webhook Events
- subscription.created
- subscription.canceled
- subscription.order.success
- subscription.order.failure

### Önemli Ayrım
- `CHECKOUTFORM_AUTH`, `API_AUTH`, `THREE_DS_AUTH` ödeme event'leridir
- Abonelik yaşam döngüsü için `subscription.*` event'leri zorunlu olarak dinlenir
- Renewal başarı/başarısızlık state'i `subscription.order.*` event'lerinden türetilir

### Signature Verification
- X-Iyz-Signature-V3 header
- HMAC-SHA256 verification

### Idempotency
- paymentId veya iyziReferenceCode kullan
- webhook_calls tablosunda deduplication

---

## DTOs

### IyzicoPaymentRequest
- amount, currency, installment
- buyer, address, basketItems
- paymentCard (veya cardToken)

### IyzicoPaymentResponse
- success, transactionId
- redirectUrl (3DS için)
- checkoutFormContent (form için)
- providerResponse

### DTO Uygulama Stratejisi
- v1'de manuel typed DTO zorunludur
- `spatie/laravel-data` v1 kapsam dışıdır
- DTO validation ingress boundary'de yapılır (request/webhook parse)

### WebhookResult Genişletme (Zorunlu)
- Mevcut alanlar: `processed`, `eventId`, `duplicate`, `message`
- Faz 2 tamamlanma için eklenecek alanlar:
  - `eventType`
  - `subscriptionId`
  - `transactionId`
  - `amount`
  - `status`
  - `nextBillingDate`
- Bu alanlar service orchestration katmanına provider-agnostic veri taşımak için zorunludur

---

## IyzicoProvider Sınıfı

### Implements
- PaymentProviderInterface

### Methods
- managesOwnBilling(): true
- pay(): Non-3DS, 3DS, CheckoutForm
- createSubscription(): Recurring setup
- upgradeSubscription(): Plan change
- cancelSubscription(): Cancel
- chargeRecurring(): kullanılmaz (provider-managed)
- refund(): Cancel/Refund
- validateWebhook(): Signature check
- processWebhook(): Payload parse + `WebhookResult` DTO üretimi (state mutation yok)

### Provider Adapter Kısıtları (Non-Negotiable)
- Provider adapter DB state mutate etmez (`Subscription`, `Transaction`, `PaymentMethod`)
- Provider adapter domain event dispatch etmez
- Provider adapter sonucu typed DTO ile service katmanına iletir

---

## 3 Katmanlı Mimari Uygulaması (Faz 2 Zorunlu)

Referans: `docs/plans/2026-03-04-manages-own-billing-architecture-design.md`

### Katman 1 - Provider Adapter (iyzico)
- iyzico request/response ve webhook payload normalizasyonu
- Signature doğrulama ve callback güvenliği
- DTO dönüşü (`PaymentResponse`, `SubscriptionResponse`, `WebhookResult`)

### Katman 2 - Service Orchestration
- `SubscriptionService::handleWebhookResult(...)` state mutation'ın tek noktasıdır
- `SubscriptionService::handlePaymentResult(...)` ödeme sonucu işleme tek noktasıdır
- Generic + provider-specific event dispatch sadece bu katmanda yapılır

### Katman 3 - Event Hierarchy
- Generic eventler `src/Events/` altında üretilir
- iyzico eventleri provider klasöründe tutulur ve gerekiyorsa generic eventleri extend eder
- Cross-domain listener'lar generic eventlere bağlanır

### Job Kuralı
- `FinalizeWebhookEventJob` provider-agnostic olmalıdır
- Job içinde `if ($provider === 'iyzico')` tarzı domain davranış branching'i bulunamaz

---

## Mapping Logic

### Plan → iyzico PricingPlan
- Plan.name → PricingPlan.name
- Plan.price → PricingPlan.price
- Plan.currency → PricingPlan.currency
- Plan.billing_period → PricingPlan.paymentInterval

### Zorunlu Plan Senkronizasyonu
- `subguard:sync-plans` komutu ile local planlar iyzico product/pricingPlan referanslarına bağlanır
- Eşleşmeyen planlarda subscription create bloke edilir
- Senkronizasyon raporunda: eşleşen / eksik / çakışan referanslar listelenir

### sync-plans Eşleşme Stratejisi
- Canonical match key: `plans.slug`
- Mapping alanları: `plans.iyzico_product_reference`, `plans.iyzico_pricing_plan_reference`
- Mapping state: linked / conflict / missing_remote / missing_local

### sync-plans CRUD Davranış Matrisi
- Local yeni plan + remote yok -> remote create + local reference update
- Local güncel plan + remote var -> remote update (fiyat/periyot uyum kontrolü)
- Local pasif plan + remote aktif -> remote deactivate veya local blokaj notu
- Remote'da local karşılığı olmayan plan -> orphan raporu (auto-delete yok)
- Reference çakışması -> manuel müdahale gerektiren conflict raporu

### User → iyzico Buyer
- User.id → Buyer.id
- User.email → Buyer.email
- User.name → Buyer.name + surname
- User.tax_id → Buyer.identityNumber
- Buyer mapping kaynağı `Billable/BillingProfile` sözleşmesidir
- Billable entity User, Team veya Organization olabilir (polymorphic)

---

## Events

### Generic Events (`src/Events`)
| Event | Trigger |
|-------|---------|
| PaymentCompleted | Ödeme başarılı |
| PaymentFailed | Ödeme başarısız |
| SubscriptionCreated | Abonelik oluşturuldu |
| SubscriptionCancelled | Abonelik iptal edildi |
| SubscriptionRenewed | Renewal tahsilatı başarılı |
| SubscriptionRenewalFailed | Renewal tahsilatı başarısız |
| WebhookReceived | Webhook alındı |

### Provider Events (`src/Payment/Providers/Iyzico/Events`)
| Event | Trigger |
|-------|---------|
| IyzicoPaymentCompleted | iyzico ödeme başarılı (generic event üstüne provider metadata) |
| IyzicoPaymentFailed | iyzico ödeme başarısız |
| IyzicoSubscriptionCreated | iyzico abonelik oluşturuldu |
| IyzicoSubscriptionUpgraded | iyzico plan değişti |
| IyzicoSubscriptionCancelled | iyzico abonelik iptal edildi |
| IyzicoSubscriptionOrderSucceeded | iyzico renewal başarılı |
| IyzicoSubscriptionOrderFailed | iyzico renewal başarısız |
| IyzicoWebhookReceived | iyzico webhook alındı |

---

## Operasyonel Komutlar

- `subguard:sync-plans`
  - Local plan katalogunu iyzico product/pricingPlan referansları ile eşler
  - Faz 2 çıkış kriterlerinden biridir
- `subguard:reconcile-iyzico-subscriptions`
  - Webhook kaçırma durumunda remote/local abonelik durumlarını uzlaştırır
  - Frekans: saat başı lightweight kontrol + her gün 02:00 tam uzlaştırma
  - Tutarsızlıkta: önce rapor, ardından güvenli state düzeltmesi (idempotent update)

### Callback URL Yönetimi
- 3DS/CheckoutForm callback URL'leri paket route helper ile üretilir
- Varsayılan callback prefix config'ten gelir (`webhooks.prefix`)
- Callback URL override v1'de desteklenir: `providers.iyzico.callback_url` zorunlu format doğrulamasıyla uygulanır

---

## Çıktılar

- [ ] IyzicoProvider sınıfı
- [ ] IyzicoPaymentRequest/Response DTOs
- [ ] IyzicoWebhookHandler
- [ ] `WebhookResult` genişletmesi ve service katmanına uygun veri taşıma
- [ ] iyzico config bölümü
- [ ] Generic billing eventleri (`src/Events`) + iyzico provider event hiyerarşisi
- [ ] `SubscriptionService::handleWebhookResult` ve `handlePaymentResult` implementasyonu
- [ ] `FinalizeWebhookEventJob` provider-agnostic delegasyonu
- [ ] Unit tests
- [ ] Integration tests

---

## Test Kriterleri

- [ ] Non-3DS payment çalışıyor
- [ ] 3DS payment flow tam çalışıyor
- [ ] CheckoutForm flow tam çalışıyor
- [ ] Subscription create çalışıyor
- [ ] Subscription upgrade çalışıyor
- [ ] Subscription cancel çalışıyor
- [ ] Card storage çalışıyor
- [ ] Webhook handling çalışıyor
- [ ] Idempotency çalışıyor
- [ ] `subguard:sync-plans` create/update/conflict senaryoları doğrulanıyor
- [ ] `subguard:reconcile-iyzico-subscriptions` tutarsız state'i düzeltiyor
- [ ] Callback URL üretimi auto-route ve custom-route modlarında doğru çalışıyor
- [ ] Provider adapter katmanında doğrudan DB mutation yapılmadığı testle doğrulanıyor
- [ ] Provider adapter katmanında domain event dispatch yapılmadığı testle doğrulanıyor
- [ ] `FinalizeWebhookEventJob` içinde provider-specific domain branching olmadığı doğrulanıyor

---

## Riskler ve Notlar

| Risk | Etki | Öneri |
|------|------|-------|
| iyzico API değişiklikleri | Yüksek | SDK versiyonunu sabitle |
| 3DS flow karmaşıklığı | Orta | Detaylı dokümantasyon |
| Sandbox vs Production farkı | Orta | Config ile switch |
| Trailing zero removal | Düşük | Helper function |

---

## Sonraki Faz

Faz 3: PayTR Provider (bu faz tamamlandıktan sonra başlayacak)
