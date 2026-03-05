# Faz 2 Risk Notes

> **Faz**: iyzico Provider
> **Durum**: Faz Sonu Kontrol Sonucu: Tamamlandı
> **Güncelleme Tarihi**: 2026-03-04

## Karşılaşılan Riskler
- iyzico webhook signature formatı birden fazla yapıda olabiliyor (subscription vs payment vs checkout)
- Mock mode ile live mode arasındaki davranış farkları SDK çağrılarında dikkatle ayrılmalı
- Provider-ozel siniflarin generic klasorlere dagilmasi uzun vadede mimari karmasa yaratiyor
- Provider adapter içinde state mutation + event dispatch birikmesi PayTR fazında tekrar ve tutarsızlık riski yaratıyor
- `FinalizeWebhookEventJob` içinde provider-specific domain branching, ortak iş akışında ölçeklenebilirlik riski yaratıyor
- Generic billing event katmanı eksikliği (cross-provider listener standardı) lisans köprüsünde coupling riski doğuruyor

## Uygulanan Çözümler
- `computeWebhookSignature()` metodu 3 farklı payload formatını destekliyor (subscription ref codes, token-based, standard payment)
- `mockMode()` flag'i ile tüm provider metodları mock/live dual-mode çalışıyor
- `chargeRecurring()` provider-managed sözleşmeye uygun şekilde `UnsupportedProviderOperationException` fırlatıyor
- Provider siniflari moduler yapida tek catida toplandi:
  - `src/Payment/Providers/Iyzico/IyzicoProvider.php`
  - `src/Payment/Providers/Iyzico/Data/*`
  - `src/Payment/Providers/Iyzico/Events/*`
  - `src/Payment/Providers/Iyzico/Commands/*`
- Callback controller seviyesinde provider imza doğrulaması zorunlu hale getirildi (live mode'da imzasız/yanlış callback reddediliyor)
- `createSubscription()` için `iyzico_pricing_plan_reference` guard eklendi; sync edilmemiş planla abonelik açılışı engellendi
- Subscription create akışında provider DB mutation kaldırıldı; kart token persistence `SubscriptionService::persistProviderPaymentMethod` ile service katmanına taşındı
- Reconcile komutu metadata içindeki `iyzico_remote_status` snapshot'ını normalize edip local statüye uygulayacak şekilde genişletildi
- `FinalizeWebhookEventJob` provider-agnostic delegasyon modeline çekildi (signature doğrulama + DTO parse + service orchestration)
- `SubscriptionService` içinde `handleWebhookResult` / `handlePaymentResult` ile state mutation ve generic event dispatch tek noktaya taşındı
- Provider `processWebhook()` DTO-only parse modeline geçirildi; provider tarafındaki domain event dispatch kaldırıldı
- Generic billing event katmanı (`src/Events/*`) eklendi ve iyzico event sınıfları event hierarchy ile hizalandı
- iyzico live SDK branch'leri `pay/refund/createSubscription/cancel/upgrade` metodlarında aktive edildi
- `sync-plans` komutu remote-aware hale getirildi (iyzico Product/PricingPlan API + local fallback)
- `reconcile` komutu remote-aware hale getirildi (`SubscriptionDetails` status pull + metadata fallback)
- Kart lifecycle metotları eklendi (`Card::create`, `CardList::retrieve`, `Card::delete`)
- Provider-specific event dispatch if-zinciri kaldırıldı; resolver tabanlı dispatch yapısına geçildi
- Status typo/case riski `SubscriptionStatus` enum ile azaltıldı

## Gelecek Fazlar İçin Notlar
- `pay/refund/createSubscription/cancel/upgrade` live branch'leri eklendi; canlı sandbox credential'larla entegrasyon smoke doğrulaması yapılmalı
- Sandbox ortamında callback URL'lerinin erişilebilir HTTPS endpoint'e yönlendirildiği düzenli doğrulanmalı
- Callback URL custom override validation/fallback eklendi; Faz 2 kapanisi icin regression testleri korunmali

## Technical Debt
- IyzicoProvider ~400 satır — Faz 5'te webhook processing private metodlarının ayrı handler sınıfına taşınması düşünülebilir
- `extractString` / `extractFloat` helper'ları trait veya utility sınıfına çıkartılabilir
- Mock referansları (`iyz-prod-`, `iyz-price-`) gerçek API entegrasyonu sonrası kaldırılmalı
