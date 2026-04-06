# TEST-02: iyzico Webhook Signature Validation Testleri

## Problem

`src/Payment/Providers/Iyzico/IyzicoProvider.php` satır 600-632'deki `computeWebhookSignature()` method'u 3 farklı HMAC-SHA256 pattern destekliyor. Hiçbiri unit test ile doğrulanmamış.

3 pattern:
1. **Subscription events** (satır 613-617): `merchantId + secret + eventType + subscriptionRef + orderRef + customerRef`
2. **Token-based** (satır 619-622): `secret + eventType + paymentId + token + conversationId + status`
3. **Payment-based** (satır 625-629): `secret + eventType + paymentId + conversationId + status`

Ayrıca `validateWebhook()` (satır 324-347):
- Mock modda her zaman `true` döner
- Secret boşsa `false` döner
- Signature boşsa `false` döner
- Computed vs provided signature karşılaştırması `hash_equals()` ile yapılıyor

## Test Dosyası

Yeni dosya: `tests/Unit/Payment/Providers/Iyzico/IyzicoWebhookSignatureTest.php`

## Test Senaryoları

### validateWebhook() genel testleri

```
it returns true in mock mode regardless of payload and signature
```
- Config: `iyzico.mock = true`
- Boş payload + boş signature ile `validateWebhook()` → `true`

```
it returns false when secret key is empty in live mode
```
- Config: `iyzico.mock = false`, `iyzico.secret_key = ''`
- `validateWebhook($payload, 'some-sig')` → `false`

```
it returns false when signature is empty in live mode
```
- Config: `iyzico.mock = false`, `iyzico.secret_key = 'test-secret'`
- `validateWebhook($payload, '')` → `false`

```
it uses timing-safe comparison via hash_equals
```
- Bu doğrudan test edilemez ama aşağıdaki pattern testleriyle dolaylı doğrulanır

### Pattern 1: Subscription events signature

```
it validates subscription event signature correctly
```
- Secret: `test-secret-key`
- Merchant ID: `merchant123` (config'den)
- Payload:
  ```php
  [
      'iyziEventType' => 'subscription.order.success',
      'subscriptionReferenceCode' => 'sub_ref_001',
      'orderReferenceCode' => 'order_ref_001',
      'customerReferenceCode' => 'cust_ref_001',
  ]
  ```
- Beklenen message: `merchant123test-secret-keysubscription.order.successsub_ref_001order_ref_001cust_ref_001`
- Beklenen signature: `bin2hex(hash_hmac('sha256', $message, 'test-secret-key', true))`
- `validateWebhook($payload, $expectedSignature)` → `true`

```
it rejects subscription event with wrong signature
```
- Aynı payload, yanlış signature → `false`

```
it rejects subscription event with missing merchant_id in config
```
- Config'de `merchant_id = null` veya boş
- Tüm 4 alan payload'da mevcut olsa bile, merchantId boş olduğu için bu pattern match etmez
- Sonraki pattern'e düşer veya boş string döner → `false`

### Pattern 2: Token-based signature

```
it validates token-based webhook signature correctly
```
- Payload:
  ```php
  [
      'iyziEventType' => 'payment.success',
      'paymentId' => 'pay_123',
      'token' => 'tok_abc',
      'paymentConversationId' => 'conv_456',
      'status' => 'SUCCESS',
  ]
  ```
- Message: `test-secret-keypayment.successpay_123tok_abcconv_456SUCCESS`
- Doğru signature ile `validateWebhook()` → `true`

```
it rejects token-based webhook with tampered payload
```
- Doğru signature oluştur, sonra payload'daki `status`'u değiştir → `false`

### Pattern 3: Payment-based signature

```
it validates payment-based webhook signature correctly
```
- Payload (token yok):
  ```php
  [
      'iyziEventType' => 'payment.success',
      'paymentId' => 'pay_789',
      'paymentConversationId' => 'conv_101',
      'status' => 'SUCCESS',
  ]
  ```
- Message: `test-secret-keypayment.successpay_789conv_101SUCCESS`
- Doğru signature ile → `true`

```
it rejects payment-based webhook with wrong secret
```
- Farklı secret ile oluşturulmuş signature → `false`

### Edge case testleri

```
it returns false when payload matches no signature pattern
```
- Payload'da sadece `event_type` var, diğer alanlar yok
- `computeWebhookSignature()` boş string döner → doğrulama `false`

```
it is case-insensitive for signature comparison
```
- Büyük harfle signature gönder (ABC123...)
- `strtolower()` ile normalize edildiği için doğrulama başarılı olmalı

```
it handles alternative field names in payload
```
- `event_type` yerine `eventType` kullanıldığında doğru çalıştığını doğrula
- `payment_id` yerine `paymentId` kullanıldığında doğru çalıştığını doğrula

## Test Altyapısı

`IyzicoProvider` doğrudan new ile oluşturulacak. Bağımlılıkları:
- `IyzicoRequestBuilder` - mock edilebilir
- `IyzicoCardManager` - mock edilebilir
- `IyzicoSupport` - gerçek instance (config okuma için gerekli)

Config override:
```php
config()->set('subscription-guard.providers.drivers.iyzico', [
    'api_key' => 'test-api-key',
    'secret_key' => 'test-secret-key',
    'merchant_id' => 'merchant123',
    'mock' => false, // Live mode - gerçek signature validation
]);
```

### Signature hesaplama helper

Test içinde signature üretmek için:
```php
function computeExpectedSignature(string $message, string $secret): string
{
    return bin2hex(hash_hmac('sha256', $message, $secret, true));
}
```

## Doğrulama

1. 3 pattern'in her biri için doğru signature → `true`
2. 3 pattern'in her biri için yanlış signature → `false`
3. Boş secret → `false`
4. Boş signature → `false`
5. Mock mode → her zaman `true`
6. Pattern match etmeyen payload → `false`
7. `composer test` → tüm testler geçiyor
