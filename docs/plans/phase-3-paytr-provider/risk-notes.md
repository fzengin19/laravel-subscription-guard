# Faz 3 Risk Notes

> **Faz**: PayTR Provider
> **Durum**: Tamamlandı
> **Güncelleme Tarihi**: 2026-03-05

## Karşılaşılan Riskler
- **Provider live endpoint boşluğu**: `pay`, `chargeRecurring`, `refund`, `createSubscription` için live entegrasyon henüz placeholder yanıt dönüyor.
- **Webhook/charge yarışı**: Senkron tahsilat ile asenkron webhook aynı işlemi iki kez mutasyona sokabilir.
- **PayTR self-managed retry kırılganlığı**: Başarılı tahsilat sonrası lokal commit hatasında tekrar charge riski.
- **Operasyonel izin riski**: Non3D recurring izin/merchant tarafı hazırlığı eksik kalırsa üretimde tahsilat akışı bloklanır.

## Uygulanan Çözümler
- Provider adapter katmanı DTO/parse/doğrulama sınırında tutuldu; domain mutation `SubscriptionService` ve ortak job katmanında bırakıldı.
- Webhook idempotency + duplicate yönetimi güçlendirildi; failed webhook retry senaryolarında kayıt yeniden `pending`e alınarak tekrar işlenebilir hale getirildi.
- Renewal charge akışında idempotency key (`transaction_id`) provider çağrısına taşındı ve retry güvenliği artırıldı.
- Phase 3 testleri webhook ingress, hash doğrulama, success/failure normalizasyonu, preflight renewal success/failure ve retry davranışlarını kapsayacak şekilde genişletildi.

## Gelecek Fazlar İçin Notlar
- Faz 4 lisans köprüsü için generic event hattı (`PaymentCompleted`, `PaymentFailed`, `SubscriptionRenewed`, `SubscriptionRenewalFailed`) hazır durumda.
- Faz 4 başında revocation/heartbeat ile dunning-grace senkronunun event listener seviyesinde netleştirilmesi gerekiyor.
- PayTR live API uçları devreye alınırken adapter sınırları korunmalı (DB mutation ve domain event dispatch provider katmanında kalmamalı).

## Technical Debt
- PayTR live charge/refund/createSubscription akışları henüz production-ready değil (placeholder yanıtlar mevcut).
- Non3D izin doğrulaması ve merchant onboarding kontrol listesi kod seviyesinde enforce edilmiyor, operasyonel dokümana bağımlı.
- Provider sınıfı ileride HTTP istemci/mapper/parsing bileşenlerine ayrıştırılarak sadeleştirilebilir.
