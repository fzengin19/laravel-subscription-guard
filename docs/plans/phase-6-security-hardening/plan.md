# Faz 6: Security Hardening & Debug Reliability (Plan)

> **Durum**: Tamamlandı (2026-03-05)
> **Hedef Başlangıç**: 2026-03-06
> **Tahmini Süre**: 3 hafta
> **Bağımlılıklar**: Faz 1-5 çıktıları, `phase-5-integration-testing/security-audit-report.md`, `phase-5-integration-testing/architecture-conformance-report.md`

---

## 1) Faz Amacı

Bu fazın amacı, repository için bildirilen güvenlik/debug bulgularını kod tabanında doğrulayıp, gerçekten geçerli riskleri Laravel best-practice yaklaşımıyla kapatmaktır. Bu faz sadece güvenlik doğruluğu, idempotency dayanıklılığı, concurrency güvenliği ve model güvenlik sınırlarını sertleştirmeye odaklanır.

---

## 2) Doğrulanmış Bulgular (Triyaj Sonucu)

| ID | Başlık | İlk Rapor | Doğrulama Sonucu | Nihai Öncelik |
|---|---|---|---|---|
| F6-SEC-001 | License limit middleware TOCTOU yarış durumu | Critical | **Gerçek (kısmi etki düzeltmesiyle)**: Quota commit post-response olduğu için side-effect önce çalışabiliyor; counter overflow kısmı FeatureGate lock/transaction ile kısmen korunuyor | P0 |
| F6-SEC-002 | Modellerde toplu atama riski (`$guarded = []`) | High | **Gerçek**: `src/Models` altındaki 14 modelde allowlist yok, integratör hatasına açık | P0 |
| F6-SEC-003 | Webhook fallback event id collision | Medium | **Gerçek (koşullu)**: Provider event id yoksa raw-body hash fallback yanlış duplicate sınıflandırmasına neden olabilir | P1 |
| F6-SEC-004 | Payment callback fallback event id collision | Medium | **Gerçek (koşullu)**: Callback tarafında benzer hash fallback davranışı mevcut | P1 |

---

## 3) Kapsam

### Dahil
- License limit enforcement akışında reserve/commit/release deseninin tasarlanması
- Tüm model katmanında toplu atama güvenlik politikasının netleştirilmesi
- Webhook ve callback için provider-odaklı deterministic event-id stratejisinin tasarlanması
- Yarış durumu, duplicate ve rollback senaryoları için test planının oluşturulması
- Dokümantasyon/audit raporlarının Faz 6 bulgularına göre güncellenme planı

### Hariç
- Yeni provider eklemek
- Lisans/ödeme alanı dışında yeni feature geliştirmek
- Operasyonel altyapı değişikliği (Horizon vb.)

---

## 4) Tasarım İlkeleri (Faz 6)

1. **Atomicity first**: Kritik limit kontrolünde check/use ayrışması kaldırılacak.
2. **Least write surface**: Model bazında sadece güvenli alanlar yazılabilir olacak.
3. **Deterministic idempotency**: Event kimliği provider-spesifik stabil alanlardan türetilecek.
4. **No silent fallback debt**: Zayıf fallback yolları ölçümlenecek, alarm üretilecek.
5. **Architecture conformance**: Faz 2-5 ownership ve sınır kuralları korunacak.

---

## 5) İş Paketleri (Adım Adım)

## WP-A: Güvenlik Tasarım Freeze ve Etki Analizi (Gün 1-2)
- A1. Her bulgu için kod yolu, veri yolu, yan etki yüzeyi ve exploit precondition matrisi çıkar.
- A2. Etkilenen tablo/alan envanteri oluştur (subscription, license, transaction, payment_methods, webhook_calls).
- A3. Geriye dönük uyumluluk etkisi (package consumer code) sınıflandırılır: breaking/non-breaking.
- A4. Faz 6 için uygulanacak migration gereksinimi var mı yok mu netleştirilir.

**Çıktı**
- `docs/plans/phase-6-security-hardening/plan.md` içinde onaylanmış tasarım kararları
- Uygulama öncesi risk kabul/ret listesi

## WP-B: Quota Enforcement Concurrency Hardening Tasarımı (Gün 3-5)
- B1. `LicenseLimitMiddleware` için reserve-before-execute akışı tasarlanır.
- B2. Başarısız response veya exception durumunda release/refund akışı tanımlanır.
- B3. Lock kapsamı ve lock süresi kuralları belirlenir (sadece kritik bölüm).
- B4. 429 üretim koşulları ve side-effect öncesi red davranışı standardize edilir.
- B5. Aynı lisans/metrik için parallel request acceptance limit davranışı netleştirilir.

**DoD**
- TOCTOU penceresi tasarım seviyesinde kapatılmıştır.
- İşlem başarısızlığında quota geri-alım senaryosu deterministiktir.

## WP-C: Model Mass Assignment Hardening Tasarımı (Gün 6-9)
- C1. 14 model için güvenli yazılabilir alanlar (allowlist) çıkarılır.
- C2. Kritik alanlar (status, expires_at, monetary fields, provider tokens, provider ids) explicit korunur.
- C3. Domain servisleri için güvenli data mapping kuralları dokümante edilir.
- C4. Consumer entegrasyon rehberinde unsafe usage anti-pattern listesi eklenir.

**DoD**
- Model bazlı güvenlik politikası tablo halinde tamamlanmıştır.
- P0 modeller için önceliklendirilmiş uygulanma sırası belirlenmiştir.

## WP-D: Webhook/Callback Idempotency Anahtar Tasarımı (Gün 10-12)
- D1. Webhook ve callback için ortak event key resolution stratejisi tanımlanır.
- D2. Provider-specific identifier öncelik sırası yazılır (iyzico, PayTR).
- D3. Raw-body hash fallback sadece son çare ve ölçümlenebilir modda bırakılır/iyileştirilir.
- D4. `(provider, event_id)` unique kuralı ile uyumlu duplicate kabul/no-op semantiği standardize edilir.
- D5. "failed -> retry" geçişinde yanlış duplicate riskleri için guard kuralları tasarlanır.

**DoD**
- Collision riski düşürülmüş deterministic kimlik stratejisi belgelenmiştir.
- Duplicate/retry davranışı test edilebilir acceptance kriterine bağlanmıştır.

## WP-E: Test ve Doğrulama Planı (Gün 13-15)
- E1. Concurrency test senaryoları: aynı anda burst istek, başarı/başarısız karışık akış, rollback.
- E2. Security regression test senaryoları: mass assignment negative testleri.
- E3. Idempotency testleri: same-event retry, missing-id fallback, near-duplicate payload.
- E4. Performans etkisi için benchmark planı (p95 latency ve lock contention gözlemi).
- E5. Test komutları ve pass/fail eşiği netleştirilir.

**DoD**
- Faz 6 için test matrisi ve kabul kriterleri tamamlanmıştır.

## WP-F: Dokümantasyon ve Kapanış Planı (Gün 16-17)
- F1. Security audit raporu ile Faz 6 triyaj sonuçları mutabık hale getirilir.
- F2. Master plan ve faz durumları güncellenir.
- F3. Implementation sonrası doldurulacak `work-results.md` ve `risk-notes.md` şablonları finalize edilir.
- F4. Faz 7 için (varsa) bağımlılık ve giriş kapıları tanımlanır.

---

## 6) Test/Doğrulama Stratejisi (Plan Seviyesi)

- Unit: quota reservation ve event key resolution karar kuralları
- Integration: webhook/callback duplicate + retry akışları
- Feature: middleware altında limit aşımı ve rollback davranışları
- Concurrency: burst istek ile side-effect öncesi red garantisi
- Static: security odaklı model write-surface doğrulaması

Plan sonrası implementation fazında aşağıdaki komutlar zorunlu kabul edilir:
- `composer test`
- `composer analyse`
- `composer format`

---

## 7) Kabul Kriterleri

1. Dört bulgunun her biri için doğrulama sonucu ve kapanış yöntemi yazılı ve ölçülebilir olmalı.
2. P0 bulgular için uygulanma sırası ve test kriteri net olmalı.
3. Idempotency stratejisi provider-spesifik ve deterministic olmalı.
4. Master plan, Faz 6'yı resmi yol haritasına eklemiş olmalı.
5. Faz dosya disiplini korunmalı: `plan.md`, `work-results.md`, `risk-notes.md` mevcut olmalı.

---

## 8) Riskler ve Önlemler

| Risk | Etki | Olasılık | Önlem |
|---|---|---|---|
| Reserve-before-execute tasarımı mevcut akışla çakışır | Yüksek | Orta | Feature-flag ve aşamalı rollout planı |
| Model allowlist değişikliği consumer tarafında kırılma yaratır | Orta | Yüksek | Upgrade guide + migration notları + sürümleme disiplini |
| Provider payload varyasyonları event key çözümlemesini zorlaştırır | Orta | Orta | Provider başına fallback hiyerarşisi + telemetry |
| Lock/transaction maliyeti performansı düşürür | Orta | Orta | Benchmark ve lock contention metriği ile tuning |

---

## 9) Faz Sonu Artefaktları (Planlanan)

- `docs/plans/phase-6-security-hardening/plan.md` (bu dosya)
- `docs/plans/phase-6-security-hardening/work-results.md` (faz tamamlanınca doldurulacak)
- `docs/plans/phase-6-security-hardening/risk-notes.md` (faz tamamlanınca doldurulacak)
- Güncellenmiş `docs/plans/master-plan.md`

---

## 10) Not

Bu faz dokümanı sadece planlama kapsamındadır. Bu aşamada kod implementasyonu yapılmaz.
