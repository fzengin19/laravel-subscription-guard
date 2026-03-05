# Faz 7: Code Simplification & Readability Hardening (Plan)

> **Durum**: Planlama
> **Hedef Başlangıç**: 2026-03-06
> **Tahmini Süre**: 3 hafta
> **Bağımlılıklar**: Faz 1-6 çıktıları, özellikle Faz 6 security hardening guardrail'leri

---

## 1) Faz Amacı

Bu fazın amacı, iş mantığını değiştirmeden kod okunabilirliğini ve bakım hızını artırmak; gereksiz sorguları, tekrar eden branch bloklarını ve aşırı dikey (boilerplate) yazım desenlerini sadeleştirmektir.

Bu fazın temel prensibi: **daha kısa kod** tek başına hedef değildir; hedef **daha anlaşılır, daha güvenli, daha test edilebilir kod** üretmektir.

---

## 2) Kullanıcıdan Gelen 3 Önerinin Doğrulama Sonucu

| ID | Öneri | Dosya | Karar | Not |
|---|---|---|---|---|
| F7-REP-001 | `LicenseManager::activate` içinde ikinci `count()` sorgusunu kaldır, `activeCount + 1` kullan | `src/Licensing/LicenseManager.php` | **Şartlı Onay** | Mevcut transaction + lock bağlamında mantıklı. Lock varsayımları bozulursa risk oluşur; concurrency regression test zorunlu. |
| F7-REP-002 | Webhook/Callback failed branch flatten + `update([...])` | `src/Http/Controllers/WebhookController.php`, `src/Http/Controllers/PaymentCallbackController.php` | **Onay** | Duplicate/dispatch semantiği korunarak okunabilirlik artar. `WebhookCall` guard kısıtı (yalnız `id`) ile uyumlu. |
| F7-REP-003 | `computeWebhookSignature` içinde hash çağrısını tek noktaya toplama | `src/Payment/Providers/Iyzico/IyzicoProvider.php` | **Şartlı Onay (Yüksek Dikkat)** | Branch önceliği ve mesaj birleştirme sırası birebir korunursa yapılabilir. En küçük sapma imza doğrulamasını bozabilir. Golden test seti şart. |

---

## 3) Repo Geneli Ek Sadeleştirme Adayları (Öncelikli)

| ID | Alan | Dosya | Problem Deseni | Risk | Okunabilirlik Kazancı |
|---|---|---|---|---|---|
| F7-CAND-001 | Webhook finalize | `src/Jobs/FinalizeWebhookEventJob.php` | Tekrarlayan status/error/processed_at set blokları | Düşük | 5/5 |
| F7-CAND-002 | Payment charge transitions | `src/Jobs/PaymentChargeJob.php` | Başarısız/processing transition boilerplate tekrarı | Orta | 5/5 |
| F7-CAND-003 | Scheduled change transitions | `src/Jobs/ProcessScheduledPlanChangeJob.php` | Aynı failed/processed update akışları | Düşük | 4/5 |
| F7-CAND-004 | Webhook+Callback ortak dedup bloğu | `src/Http/Controllers/*Callback*`, `src/Http/Controllers/WebhookController.php` | Aynı return sözlükleri + benzer branch gövdesi | Orta | 5/5 |
| F7-CAND-005 | Subscription payment outcome | `src/Subscription/SubscriptionService.php` | `handlePaymentResult` ve `recordWebhookTransaction` içinde benzer akışlar | Orta | 5/5 |
| F7-CAND-006 | Metered processor decomposition | `src/Billing/MeteredBillingProcessor.php` | Uzun monolitik transaction closure | Orta | 5/5 |
| F7-CAND-007 | License activation counter flow | `src/Licensing/LicenseManager.php` | Count-reuse fırsatı ve niyetin dağınık ifadesi | Düşük | 4/5 |
| F7-CAND-008 | Controller return tuple tekrarı | `src/Http/Controllers/WebhookController.php`, `src/Http/Controllers/PaymentCallbackController.php` | `duplicate/dispatch/webhook_call_id` payload tekrarı | Düşük | 4/5 |
| F7-CAND-009 | Status mutation helper eksikliği | `src/Subscription/SubscriptionService.php` | Çoklu setAttribute+save status lifecycle blokları | Orta | 4/5 |
| F7-CAND-010 | Signature branch readability | `src/Payment/Providers/Iyzico/IyzicoProvider.php` | Çoklu hash çağrısı + yoğun string concat | Yüksek | 3/5 |

---

## 4) Sadeleştirme İlkeleri (Faz 7 Kuralları)

1. **Readability First**: Her değişiklikte okunabilirlik etkisi açıklanır; salt satır azaltma yapılmaz.
2. **No Business Logic Drift**: Davranış eşdeğerliği testlerle kanıtlanmadan refactor kabul edilmez.
3. **Guard-Aware Refactor**: Faz 6 model guard sınırları korunur; `update()` geçişlerinde guard/forceFill/unguarded etkisi kontrol edilir.
4. **Event Semantics Preservation**: Model event tetikleme davranışı korunur (instance update vs query builder update farkı gözetilir).
5. **Lock-Safety**: `lockForUpdate` varsayımlarına dayanan query reuse değişiklikleri sadece transaction kapsamı içinde yapılır.

---

## 5) Uygulama Stratejisi (Implementation-Ready)

## WP-A: Baseline ve Güvenlik Kapıları (Gün 1)
- A1. Faz 7 adaylarını low/medium/high risk etiketle.
- A2. Mevcut test baseline'ını sabitle (`composer test`, `composer analyse`).
- A3. Her aday için "beklenen davranış" maddesi yaz (Given/When/Then).

**DoD**: Refactor öncesi doğrulanmış baseline raporu hazır.

## WP-B: Düşük Riskli Controller/Job Sadeleştirmeleri (Gün 2-4)
- B1. F7-REP-002 uygula: failed branch flatten + shared return tuple helper.
- B2. F7-CAND-001 ve F7-CAND-003 uygula: job status transition helper extraction.
- B3. Local readability regression testleri ekle/güncelle.

**DoD**: Davranış değişmeden branch karmaşıklığı ve tekrar azaltıldı.

## WP-C: Query Reuse ve Lisans Aktivasyon Netleştirmesi (Gün 5-6)
- C1. F7-REP-001 uygula: ikinci `count()` kaldır, `activeCount + 1` kullan.
- C2. Concurrency testleri ile lock varsayımı doğrula.
- C3. Kod içine gereksiz yorum eklemeden niyet netliği sağla (isimlendirme + küçük helper).

**DoD**: Gereksiz DB round-trip kaldırıldı, race-safe davranış korunuyor.

## WP-D: Yüksek Dikkatli Signature Refactor (Gün 7-9)
- D1. F7-REP-003 için önce golden test vektörleri üret (mevcut payload örnekleri).
- D2. Branch önceliğini birebir koruyarak tek hash çıkış noktasına taşı.
- D3. "Must-not-change" checklist maddelerini tek tek doğrula.

**DoD**: İmza doğrulama geriye dönük uyumlu; test vektörlerinin tamamı pass.

## WP-E: Service-Level Readability Decomposition (Gün 10-13)
- E1. `SubscriptionService` içinde ödeme sonucu uygulama akışlarını intent bazlı private helper'lara böl.
- E2. `PaymentChargeJob` transition helper'larını çıkar.
- E3. `MeteredBillingProcessor` için niyet odaklı method extraction (transaction sınırı korunarak).

**DoD**: Fonksiyon uzunlukları ve branch yoğunluğu düşürüldü; davranış parity testleri pass.

## WP-F: Konsolidasyon, Dokümantasyon ve Kapanış (Gün 14-15)
- F1. Basitleştirme karar günlüğü yaz (neden bu refactor güvenli).
- F2. `work-results.md` ve `risk-notes.md` doldur.
- F3. Master plan faz durumlarını güncelle.

**DoD**: Faz 7 kapanış artefaktları tamamlandı.

---

## 6) Readability Kabul Metrikleri

Her aday aşağıdaki metriklerle değerlendirilecek:
- **R1 (Niyet Netliği)**: Kodu ilk okuyan geliştirici 30 saniyede akışı anlayabiliyor mu?
- **R2 (Tekrar Azaltma)**: Aynı state transition veya return yapısı kaç yerde tekrar ediyor?
- **R3 (Branch Sadelik)**: Erken return/guard clause ile iç içe koşul derinliği azaldı mı?
- **R4 (Testlenebilirlik)**: Çıkarılan helper bağımsız testlenebilir mi?
- **R5 (Güvenlik Uyum)**: Faz 6 guard/idempotency/lock korumaları aynen korunuyor mu?

Minimum kabul: R1 ve R5 zorunlu geçer, toplam skor >= 18/25.

---

## 7) Test Stratejisi (Faz 7)

- Unit: Helper extraction sonrası karar fonksiyonları
- Feature: Webhook duplicate/failed-retry semantiği, callback semantiği
- Integration: Signature validation regression (iyzico payload matrix)
- Concurrency: License activation limit under lock (parallel attempt)
- Regression: `composer test` full suite
- Static: `composer analyse`

---

## 8) Riskler ve Önlemler

| Risk | Etki | Olasılık | Önlem |
|---|---|---|---|
| Signature refactor ile backward-compatibility kırılması | Yüksek | Orta | Golden test vektörleri + staged rollout |
| Guard-aware olmayan update dönüşümü | Yüksek | Orta | Guard kontrol checklist + strict test |
| Controller flatten sırasında duplicate semantiği bozulması | Orta | Orta | Before/after behavioral assertion testleri |
| Query reuse lock varsayımı gelecekte bozulur | Orta | Düşük | Lock-scope testi + kod inceleme notu |

---

## 9) Faz 7 Artefaktları

- `docs/plans/phase-7-code-simplification/plan.md` (bu dosya)
- `docs/plans/phase-7-code-simplification/work-results.md`
- `docs/plans/phase-7-code-simplification/risk-notes.md`
- Güncellenmiş `docs/plans/master-plan.md`

---

## 10) Not

Bu döküman uygulanmaya hazır plan aşamasıdır. Bu adımda refactor kodu yazılmaz.
