# FIX-07: Webhook/Callback Lock Race Condition İyileştirmeleri

## Problem

### Problem 1: Provider doğrulaması yetersiz
`src/Http/Controllers/WebhookController.php` satır 24'te `hasProvider()` kontrolü var ama payload parse'lanmadan önce provider'ın gerçekten aktif ve yapılandırılmış olduğu doğrulanmıyor.

### Problem 2: Event ID boş olabilir
`WebhookController` satır 108-115'te event type/id resolution fallback logic'i var. Payload'da `event_type` veya `event_id` yoksa:
- event_type: `'unknown'` döner
- event_id: payload'ın SHA256 hash'i döner

Bu durumda aynı provider'a farklı ama event_id'siz iki farklı webhook gelirse, payload farklıysa farklı hash üretilir (iyi). Ama aynı payload gönderilirse aynı hash üretilir (duplicate olarak işlenir - istenilen davranış). Asıl sorun: `'unknown'` event type'ın `FinalizeWebhookEventJob`'da nasıl işlendiği belirsiz.

### Problem 3: Lock timeout ve block timeout oranı
`WebhookController.php` satır 36, 39:
- Lock TTL: 10 saniye
- Block timeout: 5 saniye

Bu oranlar makul ama:
- Lock içindeki DB transaction + webhook insert süresi 5 saniyeyi geçerse, lock bitmeden ikinci bir request lock alabilir
- Config'den okunmuyor (FIX-04 ile çözülecek)

### Problem 4: PayTR hardcoded özel durum
`WebhookController.php` satır 96-98'de PayTR için hardcoded `'OK'` text response dönüyor. Bu provider adı değişirse veya yeni provider eklenirse kırılgan.

### Problem 5: Content-Type doğrulaması yok
Webhook endpoint'i herhangi bir Content-Type kabul ediyor. JSON olmayan payload'lar imza doğrulamasını geçemeyecek ama gereksiz işlem yapılıyor.

## Etkilenen Dosyalar

| Dosya | Satır | Sorun |
|-------|-------|-------|
| `src/Http/Controllers/WebhookController.php` | 24 | Provider aktiflik kontrolü yok |
| `src/Http/Controllers/WebhookController.php` | 96-98 | PayTR hardcoded özel durum |
| `src/Http/Controllers/WebhookController.php` | 108-115 | Unknown event type handling |
| `src/Http/Controllers/PaymentCallbackController.php` | 47 | Signature validation before lock |
| `src/Http/Controllers/PaymentCallbackController.php` | 57, 60 | Lock/block timeout |

## Çözüm Planı

### Adım 1: Provider aktiflik doğrulaması ekle

Dosya: `src/Http/Controllers/WebhookController.php`

Satır 24'ten sonra ek kontrol:

```php
if (! $this->paymentManager->hasProvider($provider)) {
    return response()->json(['error' => 'Unknown provider'], 404);
}

// Provider'ın mock modunda olmadığını ve credential'ların var olduğunu kontrol et
// (Opsiyonel - sadece production ortamında mantıklı)
```

Bu mevcut davranışı korur. Ek olarak `hasProvider()` zaten config'den kontrol ediyor.

### Adım 2: PayTR hardcoded response'u config-driven yap

Dosya: `src/Http/Controllers/WebhookController.php`

Mevcut (satır 96-98):
```php
if ($provider === 'paytr') {
    return response('OK', 200)->header('Content-Type', 'text/plain');
}
```

Yeni yaklaşım - provider config'ine `webhook_response_format` ekle:

Dosya: `config/subscription-guard.php`, her provider driver'ına:

```php
'iyzico' => [
    // ... mevcut config
    'webhook_response_format' => 'json', // default
],
'paytr' => [
    // ... mevcut config
    'webhook_response_format' => 'text',
    'webhook_response_body' => 'OK',
],
```

Dosya: `src/Http/Controllers/WebhookController.php`, satır 96-98'i değiştir:

```php
$providerConfig = config("subscription-guard.providers.drivers.{$provider}", []);
$responseFormat = (string) ($providerConfig['webhook_response_format'] ?? 'json');

if ($responseFormat === 'text') {
    $body = (string) ($providerConfig['webhook_response_body'] ?? 'OK');
    return response($body, $duplicate ? 200 : 200)
        ->header('Content-Type', 'text/plain');
}

// Mevcut JSON response
return response()->json([...], $duplicate ? 200 : 202);
```

### Adım 3: Unknown event type logging ekle

Dosya: `src/Http/Controllers/WebhookController.php`

Event type resolution'dan sonra (satır ~115):

```php
$eventType = $this->resolveEventType($provider, $payload);

if ($eventType === 'unknown') {
    Log::channel(
        (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
    )->warning('Webhook received with unknown event type', [
        'provider' => $provider,
        'event_id' => $eventId,
        'payload_keys' => array_keys($payload),
    ]);
}
```

### Adım 4: PaymentCallbackController'a payload doğrulaması ekle

Dosya: `src/Http/Controllers/PaymentCallbackController.php`

İmza doğrulamasından önce basit payload kontrolü:

```php
$payload = $request->all();

if ($payload === []) {
    return response()->json(['error' => 'Empty payload'], 400);
}
```

### Adım 5: Lock acquired logging ekle (debug level)

Dosya: `src/Http/Controllers/WebhookController.php`

Lock block timeout'u yakalandığında:

Mevcut pattern:
```php
$result = $lock->block(5, function () use (...) {
    // ...
});
```

Lock `block()` false döndüğünde (timeout):

```php
try {
    $result = $lock->block($blockTimeout, function () use (...) {
        // ...
    });
} catch (LockTimeoutException $e) {
    Log::channel(
        (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
    )->warning('Webhook lock timeout', [
        'provider' => $provider,
        'event_id' => $eventId,
    ]);

    return response()->json([
        'status' => 'retry',
        'message' => 'Server busy, please retry',
    ], 503);
}
```

Bu sayede:
- Lock timeout olduğunda 503 döner (provider retry eder)
- Sessiz başarısızlık yerine açık hata mesajı
- Log ile izlenebilirlik

### Adım 6: FinalizeWebhookEventJob'da unknown event type handling

Dosya: `src/Jobs/FinalizeWebhookEventJob.php`

`processWebhook()` sonucu `unknown` event type döndüğünde, webhook'u `failed` olarak işaretlemek yerine `processed` olarak işaretle ama hiçbir subscription güncellemesi yapma:

```php
$result = $providerAdapter->processWebhook($payload);

if ($result->eventType === 'unknown' || $result->eventType === null) {
    $webhookCall->markProcessed('Unknown event type - no action taken');
    return;
}
```

## Doğrulama

1. Bilinmeyen provider'a webhook gönderildiğinde 404 döndüğünü doğrula
2. PayTR webhook'unun text/plain response döndüğünü doğrula (config-driven)
3. iyzico webhook'unun JSON response döndüğünü doğrula
4. Unknown event type'ın loglandığını doğrula
5. Boş payload'ın 400 döndüğünü doğrula
6. Lock timeout'unun 503 döndüğünü ve loglandığını doğrula
7. Unknown event type webhook'unun `processed` olarak işaretlendiğini doğrula
8. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
