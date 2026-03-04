# Faz 3: PayTR Provider

> **Süre**: 3 Hafta
> **Durum**: Planlama
> **Bağımlılıklar**: Faz 1 (Core Infrastructure), Faz 2 (iyzico Provider)

---

## Özet

PayTR iFrame + CAPI entegrasyonu: ilk kart kaydı kullanıcı etkileşimli, recurring tahsilat self-managed billing scheduler ile backend tarafından yönetilir.

---

## Hedefler

1. PayTR iFrame API entegrasyonu
2. Card storage (CAPI)
3. Self-managed recurring billing engine entegrasyonu
4. Webhook/notification handling
5. Refund API
6. Marketplace/split payment (v1 kapsam dışı, v1.1 backlog)

---

## PayTR API Yapısı

### Authentication
- merchant_id
- merchant_key
- merchant_salt

### Hash Generation
- HMAC-SHA256
- base64_encode

---

## Ödeme Akışları

### iFrame API (Ana Akış)
1. Backend: POST /odeme/api/get-token → iframe_token
2. Frontend: Display iframe with token
3. PayTR: POST to callback URL
4. Response: Plain text 'OK'

**Not**: iFrame akışı recurring motoru değildir; ilk ödeme/kart kaydı için kullanılır.

### Direct API (Non3D)
- Özel izin gerekli
- Recurring payments için
- `non_3d=1` izni olmadan arka plan tahsilatı çalışmaz

---

## Kart Saklama (CAPI)

### Token Types
- utoken (user token)
- ctoken (card token)

### Operations
- store_card=1: Kart kaydet
- recurring_payment=1 + non_3d=1: Kayıtlı karttan çek
- List cards
- Delete card

### Önemli Not
- Recurring payments için Non3D permission gerekli (BDDK rule)
- Merchant PayTR'dan izin istemeli
- Bu izin kurulum checklist'inde zorunlu adım olarak dokümante edilir

---

## Webhook Handling

### Notification URL
- merchant_ok_url
- merchant_fail_url

### Payload Fields
- merchant_oid
- status (success/failed)
- total_amount
- payment_type
- installment_count
- failed_reason_code
- failed_reason_msg

### Normalized Event Mapping
- `status=success` -> subscription.payment_succeeded
- `status=failed` + `try_again=true` -> subscription.payment_retryable_failed
- `status=failed` + `try_again=false` -> subscription.payment_hard_failed
- `payment_type=subscription` -> subscription.renewed

### Sync Charge vs Async Webhook Çakışması (Race Condition)
- Renewal job senkron başarı döndüğünde DB güncellenmiş olabilir
- Aynı işlem için webhook daha sonra geldiğinde ikinci kez period uzatımı yapılmamalı
- Zorunlu dedupe kuralı:
  - `transactions.provider_transaction_id` unique
  - `transactions.idempotency_key` unique (`merchant_oid`)
  - webhook işleyici duplicate transaction bulursa state değiştirmeden 200 OK döner

### Hash Verification
```
hash = base64_encode(
  hash_hmac('sha256', 
    merchant_oid + merchant_salt + status + total_amount, 
    merchant_key, 
    true
  )
)
```

### Response
- Plain text 'OK'

---

## Refund API

### Endpoint
- POST /odeme/iade

### Parameters
- merchant_id
- merchant_oid
- return_amount
- paytr_token
- reference_no

### Response
- status
- merchant_oid
- return_amount
- reference_no

---

## Marketplace/Split Payment

### Platform Transfer API
- POST /odeme/platform/transfer

### Parameters
- merchant_id
- merchant_oid
- trans_id
- submerchant_amount
- total_amount
- transfer_name
- transfer_iban

### Önemli Notlar
- Aynı gün transfer yapılamaz
- Ertesi gün 10:00'a kadar istek atılmalı
- Her satıcı için ayrı request

---

## PaytrProvider Sınıfı

### Implements
- PaymentProviderInterface

### Methods
- managesOwnBilling(): false
- pay(): iFrame token generation
- createSubscription(): Card storage + recurring
- upgradeSubscription(): mode=now ise lokal proration+charge, mode=next_period ise schedule
- chargeRecurring(): CAPI Non3D tahsilat
- refund(): Refund API
- validateWebhook(): Hash check
- processWebhook(): Event handling

---

## Self-Managed Billing Engine Entegrasyonu

### Renewal Job Akışı
- `subguard:process-renewals` komutu sadece `managesOwnBilling=false` provider'larda çalışır
- `subscriptions.next_billing_date <= bugün` kayıtlarını alır
- Kayıtlı kart token'ı (utoken/ctoken) ile CAPI tahsilatı dener
- Başarılı tahsilatta `next_billing_date` bir sonraki döneme alınır
- İşlem `cache()->lock` + transaction + row lock ile korunur
- Ağır tahsilat işlemi queued `PaymentChargeJob` üzerinden yürütülür

### Trial Akışı (PayTR Self-Managed)
- Trial'lı abonelik başlatılırken amaç tahsilat değil kart doğrulama/saklamadır
- iFrame/CAPI ile kart saklama yapılır (gerekirse düşük tutar doğrulama + iade)
- `subscriptions.trial_ends_at` set edilir
- `subscriptions.next_billing_date = trial_ends_at` set edilir
- Renewal job, trial bitmeden charge denemesi yapmaz

### Dunning Akışı
- `retry_count`, `next_retry_at`, `last_retry_at` alanları üzerinden yönetilir
- Retry pencereleri: 2, 5, 7 gün
- `try_again=false` dönen hatalarda retry durdurulur ve kullanıcı kart güncellemeye yönlendirilir
- Retry denemeleri queue üzerinde izole job'larda yürütülür

### Kart Güncelleme Sonrası Anında Kurtarma
- Kullanıcı kart güncellediğinde `PaymentMethodUpdated` olayı fırlatılır
- Eğer abonelik `past_due` veya `grace_period` durumundaysa anında tahsilat denemesi yapılır
- Bu deneme, bir sonraki dunning penceresini beklemeden `retryPastDuePayments()` akışını tetikler
- Başarılı tahsilatta abonelik `active` durumuna döner ve retry sayaçları sıfırlanır

### Upgrade / Downgrade Davranışı (PayTR)
- Provider tarafında native plan-switch API varsayılmaz
- Plan değişimi lokal domain işlemidir (`scheduled_plan_changes`)
- Yeni fiyat bir sonraki `next_billing_date` tahsilatında uygulanır

### scheduled_plan_changes İşleyicisi
- `subguard:process-plan-changes` komutu tarafından işlenir
- `scheduled_at <= now()` kayıtları lock ile alınır
- Uygulama sonrası status: pending -> completed (veya failed)
- Renewal job ile aynı aboneliğe çakışmamak için subscription bazlı lock kullanılır

### Anında Upgrade (mode=now) - Self-Managed Kural
- Sadece `managesOwnBilling=false` provider'larda lokal proration uygulanır
- Hesap:
  - kullanılmamış eski plan kredisi
  - kalan dönem için yeni plan maliyeti
  - fark tutar = yeni maliyet - kredi
- İşlem güvenliği:
  - abonelik satırı lock edilerek işlem yapılır
  - deterministic idempotency key üretilir (`upgrade:{subscription_id}:{new_plan_id}:{period_anchor}`)
  - PayTR tarafında `merchant_oid` bu idempotency anahtarı ile eşlenir
- Fark tutar > 0 ise `chargeRecurring()` ile anında çekim denenir
- Çekim başarılıysa `plan_id` hemen güncellenir
- Çekim başarısızsa plan değişikliği iptal edilir, mevcut plan korunur
- Retryable hata durumunda fallback zorunludur:
  - `scheduled_plan_changes` kaydı ile dönem sonuna erteleme
  - kullanıcıya başarısızlık + tekrar deneme bildirimi
- Her durumda tek finansal kayıt kuralı korunur (idempotent transaction)

### Interface Uyum Notu
- `upgradeSubscription()` metodu PayTR'da unsupported değildir; lokal domain akışını yönetir
- Gateway-native upgrade beklenmez, fakat interface sözleşmesi korunur

---

## DTOs

### PaytrPaymentRequest
- merchant_oid
- amount
- currency (TRY)
- user info (email, name, address)
- basket items

### PaytrPaymentResponse
- success
- transactionId (merchant_oid)
- iframeToken
- iframeUrl
- providerResponse

### DTO Uygulama Stratejisi
- Varsayılan: manuel typed DTO'lar (v1)
- `spatie/laravel-data` v1 kapsam dışıdır
- DTO sözleşmesi manuel typed sınıflarla sabitlenir

---

## Events

| Event | Trigger |
|-------|---------|
| PaytrPaymentInitiated | Ödeme başlatıldı |
| PaytrPaymentCompleted | Ödeme başarılı |
| PaytrPaymentFailed | Ödeme başarısız |
| PaytrRefundProcessed | İade yapıldı |
| PaytrWebhookReceived | Webhook alındı |

---

## Çıktılar

- [ ] PaytrProvider sınıfı
- [ ] PaytrPaymentRequest/Response DTOs
- [ ] PaytrWebhookHandler
- [ ] PayTR config bölümü
- [ ] Events ve Listeners
- [ ] Unit tests
- [ ] Integration tests

---

## Test Kriterleri

- [ ] iFrame token generation çalışıyor
- [ ] iFrame display çalışıyor
- [ ] Callback handling çalışıyor
- [ ] Card storage çalışıyor
- [ ] Refund API çalışıyor
- [ ] Webhook handling çalışıyor
- [ ] Hash verification çalışıyor
- [ ] Idempotency çalışıyor
- [ ] next_billing_date tabanlı renewal job doğru çalışıyor
- [ ] retry_count/next_retry_at dunning akışı doğru çalışıyor
- [ ] `try_again=false` durumunda retry yapılmıyor
- [ ] Sync charge + async webhook aynı işlemde çift period uzatma yapmıyor
- [ ] Trial abonelikte `next_billing_date = trial_ends_at` doğru set ediliyor
- [ ] Trial bitmeden renewal charge denenmiyor
- [ ] `mode=now` upgrade'de lokal proration + anlık charge doğru çalışıyor
- [ ] Kart güncelleme sonrası past_due abonelikte anında recovery deneniyor

---

## Riskler ve Notlar

| Risk | Etki | Öneri |
|------|------|-------|
| Non3D permission gerekliliği | Yüksek | Dokümantasyonda belirt |
| Aynı gün transfer yok | Orta | Marketplace için planlama |
| Hash hesaplama hataları | Orta | Test suite ile doğrula |

---

## Sonraki Faz

Faz 4: Licensing System (bu faz tamamlandıktan sonra başlayacak)
