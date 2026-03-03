# Faz 2: iyzico Provider

> **Süre**: 4 Hafta
> **Durum**: Planlama
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
- processWebhook(): Event handling

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

### User → iyzico Buyer
- User.id → Buyer.id
- User.email → Buyer.email
- User.name → Buyer.name + surname
- User.tax_id → Buyer.identityNumber

---

## Events

| Event | Trigger |
|-------|---------|
| IyzicoPaymentInitiated | Ödeme başlatıldı |
| IyzicoPaymentCompleted | Ödeme başarılı |
| IyzicoPaymentFailed | Ödeme başarısız |
| IyzicoSubscriptionCreated | Abonelik oluşturuldu |
| IyzicoSubscriptionUpgraded | Plan değişti |
| IyzicoSubscriptionCancelled | Abonelik iptal edildi |
| IyzicoSubscriptionOrderSucceeded | Renewal tahsilatı başarılı |
| IyzicoSubscriptionOrderFailed | Renewal tahsilatı başarısız |
| IyzicoWebhookReceived | Webhook alındı |

---

## Operasyonel Komutlar

- `subguard:sync-plans`
  - Local plan katalogunu iyzico product/pricingPlan referansları ile eşler
  - Faz 2 çıkış kriterlerinden biridir
- `subguard:reconcile-iyzico-subscriptions`
  - Webhook kaçırma durumunda remote/local abonelik durumlarını uzlaştırır

---

## Çıktılar

- [ ] IyzicoProvider sınıfı
- [ ] IyzicoPaymentRequest/Response DTOs
- [ ] IyzicoWebhookHandler
- [ ] iyzico config bölümü
- [ ] Events ve Listeners
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
