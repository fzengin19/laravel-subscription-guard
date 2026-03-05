# Faz 4.1: Implementation Closure & Hardening

> **Sure**: 2 Hafta
> **Durum**: Tamamlandi (2026-03-05)
> **Bağımlılıklar**: Faz 1, 2, 3, 4 (tamamlanan dilimler)
> **Amaç**: Faz 5'e girmeden önce mock/placeholder bırakılan veya risk-notes'ta açık borç olarak duran kritik akışları production-ready seviyeye kapatmak

---

## Ozet

Bu faz, "entegrasyon ve test" fazına kirli teknik borc ile girilmesini engelleyen bir tampon fazdır.
Faz 3 ve Faz 4 dokumanlarinda kalan canli endpoint bosluklari, dunning/grace dogrulama eksikleri,
revocation/heartbeat operasyonel aciklari ve metered billing dayanıklilik gereksinimleri burada kapatilir.

---

## Kapsam

1. PayTR live endpoint akislari placeholder durumdan cikarilacak.
2. iyzico live/sandbox smoke gate'i net acceptance kriterleri ile calistirilacak.
3. Revocation sync ve heartbeat isletim aciklari tamamlanacak.
4. Grace period + dunning davranislari testle kapatilacak.
5. Metered billing provider-charge entegrasyonu sertlestirilecek.
6. Architecture contract ihlallerine karsi statik ve davranissal gate konulacak.

---

## Faz 4.1 Giris Kriterleri

- Faz 3 `work-results.md` ve `risk-notes.md` okunmus olmali.
- Faz 4 `work-results.md` ve `risk-notes.md` okunmus olmali.
- Mock/placeholder borclarin listesi path bazinda cikarilmis olmali.
- Faz 5'e henuz gecilmemis olmali.

---

## Faz 4.1 Cikis Kriterleri (Definition of Done)

- PayTR `pay`, `chargeRecurring`, `refund`, `createSubscription` akislari icin placeholder davranis kalmamali.
- Grace period ve dunning senaryolari testle kapatilmis olmali.
- Revocation remote sync (full/delta) calisiyor olmali.
- Heartbeat ingest/sync komutu uygulanmis ve test edilmis olmali.
- Metered billing charge akisi idempotent, retry-safe ve race-safe olmali.
- Provider adapter katmaninda DB mutation/event dispatch ihlali olmadigi dogrulanmali.
- Faz 5 planindaki architecture conformance ve kritik E2E maddelerine temiz giris saglanmali.

---

## Is Paketi Yapisi

### Paket A - Placeholder Burn-Down (PayTR + iyzico)

**Hedef**: Canli endpoint aciklarini kapatmak ve mock-only davranislari test izolasyonuna cekmek.

**Kapsam**:
- PayTR provider canli akislarda placeholder response donuslerinin kaldirilmasi
- iyzico canli/sandbox smoke gate checklisti
- Config tabanli mock davranisinin sadece test/disaster fallback olarak sinirlandirilmasi

**Basari Olcutleri**:
- Live mode akislari icin production-path testleri yeşil
- Risk notlarinda "not production-ready" maddesi kapanmis

---

### Paket B - Licensing Operations Hardening

**Hedef**: Revocation ve heartbeat operasyonlarini deterministik hale getirmek.

**Kapsam**:
- Revocation full/delta remote client implementasyonu
- Sequence/version bazli senkronizasyon guvencesi
- Heartbeat ingest/sync komutu
- Failure/retry gozlemlenebilirligi (log + status)

**Basari Olcutleri**:
- Offline validation'da revocation guncelligi SLA'ya baglanmis
- Heartbeat stale tespiti otomatik calisiyor

---

### Paket C - Dunning ve Grace Period Kapanisi

**Hedef**: Faz 4 checklistindeki acik maddeleri test odakli kapatmak.

**Kapsam**:
- payment_failed / insufficient_funds / hard_decline ayrimi
- retry pencereleri ve suspend gecisi
- recovery (kart guncelleme veya basarili retry) senaryolari

**Basari Olcutleri**:
- Dunning state transition matrisi testle dogrulanmis
- Grace period sonunda suspend davranisi deterministic

---

### Paket D - Metered Billing Reliability

**Hedef**: Usage -> charge donusumunu cift tahsilat riski olmadan guvenli yapmak.

**Kapsam**:
- idempotency key stratejisinin netlestirilmesi
- lock/single-writer ilkesi
- webhook + sync race senaryosu
- partial failure rollback/compensation davranisi

**Basari Olcutleri**:
- Ayni period icin duplicate charge olusmamasi
- Retry sonrasi state consistency korunmasi

---

### Paket E - Architecture Conformance Gate

**Hedef**: Faz 5 oncesi katmanlarin contract'a uygunlugunu resmi gate'e baglamak.

**Kapsam**:
- Provider adapter katmaninda model mutation taramasi
- Provider adapter katmaninda domain event dispatch taramasi
- Job katmaninda provider-specific domain branching taramasi
- Service orchestration tekilliginin dogrulanmasi

**Basari Olcutleri**:
- Uyum raporu Faz 4.1 kapanis artefakti olarak uretilmis
- Faz 5'e "architecture clean" giris onayi verilmis

---

## Slice Plani (2 Haftalik)

### Hafta 1
- Slice 1: Placeholder envanterinin dondurulmesi (baseline)
- Slice 2: PayTR live endpoint kapanislari
- Slice 3: iyzico smoke gate kesinlestirme
- Slice 4: Revocation full/delta sync implementasyonu

### Hafta 2
- Slice 5: Heartbeat ingest/sync komutu
- Slice 6: Dunning + grace period test kapanislari
- Slice 7: Metered billing reliability hardening
- Slice 8: Architecture conformance raporu + Faz 5 readiness sign-off

---

## Test ve Dogrulama Stratejisi

### Zorunlu Test Paketleri
- Provider-level integration testleri (iyzico + PayTR)
- Dunning/grace state machine senaryo testleri
- Revocation/heartbeat command testleri
- Idempotency ve race-condition testleri

### Zorunlu Komutlar
- `composer test`
- `composer analyse`
- `composer format -- --test`

### Kabul Sinirlari
- Yeni placeholder/mocked-live davranis eklenmesi yasak
- Failing test ile faz kapatilamaz
- Risk notlarinda kalan madde varsa Faz 5'e gecis yok

---

## Artefaktlar

Bu faz tamamlandiginda asagidaki dosyalar guncellenir:

- `docs/plans/phase-4-1-implementation-closure/work-results.md`
- `docs/plans/phase-4-1-implementation-closure/risk-notes.md`
- `docs/plans/master-plan.md` (faz sirasi ve durum guncellemesi)
- `docs/plans/phase-5-integration-testing/plan.md` (giris notu: Phase 4.1 completed)

---

## Riskler ve Azaltma Stratejisi

| Risk | Etki | Azaltma |
|------|------|---------|
| Live endpoint davranis farklari | Yuksek | Sandbox + controlled smoke + fallback observability |
| Dunning state complexity | Orta | State matrix + deterministic transition testleri |
| Race condition tekrar uretilememesi | Yuksek | Deterministic test fixtures + idempotency zorlamasi |
| Scope creep (Phase 5 islerinin bu faza sizmasi) | Orta | Faz 4.1 kapsam guard: sadece closure/hardening |

---

## Faz 5'e Devir Notu

Faz 4.1 tamamlandiginda Faz 5 artik yeni feature degil, yalnizca entegrasyon ve kalite kaniti fazi olarak ilerler.
Boylece Phase 5 test sonuclari, acik implementasyon borcunu degil sistem kalitesini olcer.
