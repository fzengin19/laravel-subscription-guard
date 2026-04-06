# TEST-03: PaymentCallbackController (3DS/Checkout) Testleri

## Problem

`src/Http/Controllers/PaymentCallbackController.php` 3DS ve checkout callback'lerini işliyor. Mevcut test coverage minimal:
- Temel callback kabul edilmesi test edilmiş
- Duplicate callback handling test edilmiş
- Ama aşağıdakiler test edilmemiş:
  - Geçersiz signature rejection
  - Boş payload handling
  - Lock timeout senaryosu
  - Farklı provider'lar arası callback davranışı
  - Failed webhook retry logic (satır 69-75)
  - Event ID resolution edge cases

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenPaymentCallbackTest.php`

## Test Senaryoları

### 3DS Callback testleri

```
it accepts a valid 3ds callback and creates webhook call
```
- POST `subguard/webhooks/iyzico/3ds/callback` ile valid payload
- 202 response doğrula
- `webhook_calls` tablosunda kayıt oluştuğunu doğrula
- `FinalizeWebhookEventJob` dispatch edildiğini doğrula
- Event type = `payment.3ds.callback` doğrula

```
it returns duplicate response for already processed 3ds callback
```
- Aynı event_id ile iki kez POST gönder
- İlk response: 202
- İkinci response: 200 (duplicate)
- `webhook_calls` tablosunda tek kayıt doğrula

```
it retries failed 3ds callback on re-delivery
```
- Webhook call oluştur (status=failed, event_id=X)
- Aynı event_id ile POST gönder
- Webhook call'ın `resetForRetry()` ile reset edildiğini doğrula
- `FinalizeWebhookEventJob` tekrar dispatch edildiğini doğrula

### Checkout Callback testleri

```
it accepts a valid checkout callback
```
- POST `subguard/webhooks/iyzico/checkout/callback`
- 202 response
- Webhook call kaydı doğrula

```
it handles checkout callback with token in payload
```
- Payload'da `token` alanı ile POST
- Event ID'nin token'dan derive edildiğini doğrula

### Validation testleri

```
it rejects callback with invalid signature in live mode
```
- Config: `iyzico.mock = false`
- Yanlış signature header ile POST
- 401 veya 403 response doğrula

```
it accepts callback without signature validation in mock mode
```
- Config: `iyzico.mock = true`
- Herhangi bir signature ile POST → 202

```
it returns 400 for empty payload
```
- Boş body ile POST
- 400 response doğrula (FIX-07 sonrası)

```
it returns 404 for unknown provider
```
- POST `subguard/webhooks/unknown_provider/3ds/callback`
- 404 response doğrula

### Event ID resolution testleri

```
it derives event_id from token field
```
- Payload: `{ "token": "abc123", "event_type": "payment.3ds.callback" }`
- Oluşan webhook call'ın event_id'sinin token'dan türetildiğini doğrula

```
it derives event_id from paymentId field when token is absent
```
- Payload: `{ "paymentId": "pay_123", "status": "SUCCESS" }`
- Event ID = paymentId bazlı doğrula

```
it falls back to payload hash when no identifiable field exists
```
- Payload: `{ "random_field": "value" }`
- Event ID = SHA256 hash doğrula

### Concurrent request testleri

```
it handles concurrent callbacks for same event_id gracefully
```
- Bu test cache lock mekanizmasını doğrular
- İlk request lock alır ve işlem yapar
- İkinci request lock bekler (block timeout içinde)
- Her iki request de başarılı döner ama tek webhook call kaydı oluşur
- Not: Bu test gerçek concurrent execution gerektirmez - sequential çağrı ile lock mekanizmasını doğrulayabiliriz

### Provider-specific behavior testleri

```
it returns plain text response for paytr callback
```
- POST `subguard/webhooks/paytr/3ds/callback`
- Response Content-Type = text/plain doğrula
- Response body = 'OK' doğrula (FIX-07 sonrası config-driven)

```
it returns json response for iyzico callback
```
- POST `subguard/webhooks/iyzico/3ds/callback`
- Response Content-Type = application/json doğrula

## Test Altyapısı

```php
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Queue::fake();
    // Provider config ayarla
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
});
```

Route registration: Paket service provider'ı test ortamında route'ları otomatik register etmeli. TestCase'de `getPackageProviders()` ile sağlanır.

## Doğrulama

1. Tüm yeni testler geçiyor
2. 3DS ve checkout callback'lerin her ikisi de kapsanmış
3. Signature validation (mock ve live) test edilmiş
4. Edge case'ler (boş payload, unknown provider, concurrent) test edilmiş
5. `composer test` → tüm testler geçiyor
