# Faz 4 Risk Notes

> **Faz**: Licensing System
> **Durum**: Devam Ediyor
> **Güncelleme Tarihi**: 2026-03-05

## Karşılaşılan Riskler
- **Key materyali eksikliği riski**: Özel/public key config edilmezse signing/verification çalışmaz.
- **Format uyumsuzluğu riski**: Farklı base64 varyantları veya canonicalization farkı signature verify hatasına yol açar.
- **Replay/Tamper riski**: Payload alanları değiştirildiğinde signature sabit kalırsa key güvenliği ihlal edilir.
- **Süre yönetimi riski**: TTL yanlış yapılandırılırsa lisanslar çok erken veya geç expire olabilir.
- **Revocation sequence kayması**: Out-of-order delta uygulanırsa revoked durumları tutarsızlaşabilir.
- **Offline heartbeat staleness**: Uzun süre heartbeat gelmezse offline key kötüye kullanılabilir.
- **Activation drift riski**: Aynı lisansın farklı domainlerde kontrolsüz aktivasyonu lisans limit ihlaline yol açabilir.
- **Usage write race riski**: Yüksek eşzamanlı increment çağrılarında limit üstü kullanım yazılabilir.
- **Listener bridge coverage riski**: Generic eventlerden lisans statüsüne geçişte edge-case event sıraları tutarsız kalabilir.

## Uygulanan Çözümler
- Canonical format zorunlu hale getirildi: `SG.{payload}.{signature}`.
- `LicenseSignature` içinde URL-safe base64 no-padding ve Ed25519 detached verify ile tek doğrulama standardı sağlandı.
- Tamper ve malformed senaryoları için regresyon testleri eklendi.
- `exp` alanı üzerinden expiration kontrolü aktif edilerek süresi dolmuş key'ler invalid döndürülüyor.
- Revocation store monotonic sequence kuralları eklendi (`full snapshot` + `delta`), out-of-order delta reject ediliyor.
- Offline heartbeat stale kontrolü (`max_stale_seconds`) doğrulamaya dahil edildi.
- Revoked lisanslar için validate akışı deterministik `License revoked.` sonucu üretiyor.
- Aktivasyon/deaktivasyon akışında domain binding ve `max_activations` enforce edilerek drift riski azaltıldı.
- Aynı domain aktivasyonu idempotent hale getirilerek tekrar çağrılarda aktivasyon şişmesi engellendi.
- Feature/limit gate akışında override > signed payload önceliği netleştirilerek lisans plan istisnaları deterministik hale getirildi.
- Usage increment akışı transaction altında limit kontrolü + yazım yaptığı için aşım riski azaltıldı.
- Generic billing event -> lisans lifecycle listener bridge eklendi ve provider-agnostic senaryoda status senkronu sağlandı.

## Gelecek Fazlar İçin Notlar
- Online validation endpointi tamamlandı; bir sonraki adımda policy tarafında fail-open/fail-closed kararları dokümante edilmeli.
- Aktivasyon metadata (ip/device_fingerprint) zenginleştirme ve abuse tespit sinyalleri eklenmeli.
- ScheduleGate ve zaman bazlı feature pencereleri implement edildi; bir sonraki adımda takvim pencereleri için timezone/DST regresyon testleri artırılmalı.
- Event ordering ve duplicate event idempotency kuralları listener katmanında daha da sıkılaştırılmalı.

## Technical Debt
- Revocation feed senkronizasyonu Faz 4.1'de `subguard:sync-license-revocations` komutu ile remote full/delta destekleyecek şekilde kapatıldı.
- Heartbeat update akışı Faz 4.1'de `subguard:sync-license-heartbeats` komutu ile operasyonel sync yoluna kavuştu.
- Lisans persistence şu an owner/plan varlığına bağlı opportunistic akışta; key lifecycle için tam state machine henüz yok.
- Metered billing provider-charge hardening Faz 4.1'de self-managed provider charge yoluna taşındı; ileri adım olarak webhook/renewal race regresyonları Faz 5 E2E paketinde izlenecek.
