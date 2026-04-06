# TEST-04: Refund End-to-End Flow Testleri

## Problem

iyzico refund akışı unit seviyede test edilmiş (mock mode, live sandbox) ama end-to-end olarak test edilmemiş:
- Refund sonrası transaction state güncellemesi
- Refund sonrası subscription durumu
- Partial refund senaryoları
- Aynı transaction'a birden fazla refund
- Refund idempotency
- refundByPaymentId fallback mekanizması

İlgili kod: `src/Payment/Providers/Iyzico/IyzicoProvider.php` satır 128-190

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenRefundFlowTest.php`

## Test Senaryoları

### Temel refund akışı

```
it processes a full refund in mock mode and returns success
```
- `IyzicoProvider::refund($transactionId, $amount)` çağır
- `RefundResponse` kontrolü:
  - `success = true`
  - `refundId` null değil (`rf_` prefix ile başlamalı)
  - `details` içinde `transaction_id` ve `amount` mevcut

```
it records refund transaction in database
```
- Processed transaction oluştur
- Refund yap
- `transactions` tablosunda yeni kayıt doğrula:
  - `type = 'refund'`
  - `provider_refund_id` set
  - `refunded_amount` doğru
  - `refunded_at` set

### Partial refund

```
it processes a partial refund for less than original amount
```
- Orijinal amount: 100.00 TRY
- Refund amount: 30.00 TRY
- `RefundResponse.success = true` doğrula
- Transaction'ın `refunded_amount = 30.00` olduğunu doğrula

```
it allows multiple partial refunds on same transaction
```
- İlk refund: 30.00 TRY → başarılı
- İkinci refund: 20.00 TRY → başarılı
- Toplam refunded amount = 50.00 TRY doğrula

### Refund hata senaryoları

```
it returns failure for refund with empty transaction id
```
- `refund('', 100.00)` çağır
- Live mode'da: credential check + empty id handling
- Mock mode'da: `rf_` hash üretir (mevcut davranış)

```
it returns failure when credentials are missing in live mode
```
- Config: `mock = false`, `api_key = ''`
- `refund($txnId, 100.00)` çağır
- `RefundResponse.success = false`
- `failureReason` credential hatası mesajı içermeli

```
it falls back to refundByPaymentId when transaction refund fails in live mode
```
- Bu senaryo live sandbox'ta test edilemez ama mock ile simüle edilebilir
- İlk refund attempt başarısız → fallback `AmountBaseRefund` çağrılır
- Fallback başarılı → `RefundResponse.success = true`

### Refund ve subscription ilişkisi

```
it does not change subscription status after refund
```
- Active subscription + processed transaction oluştur
- Transaction'ı refund et
- Subscription status hala `active` doğrula (refund, subscription'ı iptal etmez)

```
it records refund linked to correct subscription
```
- Subscription + transaction oluştur
- Refund yap
- Refund transaction'ın `subscription_id` doğru set edildiğini doğrula

### Mock vs live davranış tutarlılığı

```
it returns deterministic refund id in mock mode
```
- Aynı transactionId + amount ile iki kez refund çağır
- Her ikisinde de aynı `refundId` (SHA-based) döndüğünü doğrula

```
it includes transaction_id and amount in mock refund details
```
- Mock refund'ın `details` array'inde:
  - `transaction_id` = orijinal transaction ID
  - `amount` = refund miktarı

## Test Altyapısı

```php
beforeEach(function () {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
    config()->set('subscription-guard.providers.default', 'iyzico');
});
```

Provider'ı container'dan resolve et:
```php
$provider = app(PaymentManager::class)->provider('iyzico');
```

Transaction oluşturma helper:
```php
function createProcessedTransaction(int $subscriptionId, float $amount): Transaction
{
    return Transaction::unguarded(fn () => Transaction::create([
        'subscription_id' => $subscriptionId,
        'provider' => 'iyzico',
        'provider_transaction_id' => 'txn_' . Str::random(10),
        'type' => 'payment',
        'status' => 'processed',
        'amount' => $amount,
        'currency' => 'TRY',
        'processed_at' => now(),
        'idempotency_key' => 'test:' . Str::random(20),
    ]));
}
```

## Doğrulama

1. Tüm yeni testler geçiyor
2. Mock mode refund akışı kapsamlı test edilmiş
3. Partial refund ve multiple refund senaryoları kapsanmış
4. Hata senaryoları (boş ID, eksik credential) test edilmiş
5. Refund-subscription ilişkisi doğrulanmış
6. `composer test` → tüm testler geçiyor
