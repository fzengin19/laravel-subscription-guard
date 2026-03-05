# Faz 5 Risk Notes

> **Faz**: Integration & Testing
> **Durum**: Tamamlandi
> **Guncelleme Tarihi**: 2026-03-05

## Karşılaşılan Riskler
- `spatie/laravel-pdf` runtime bagimliligi ortamlara gore farkli davranabilir (browser engine gereksinimi).
- Webhook simulator komutu gercek provider callback edge-case davranislarini birebir kapsamayabilir; E2E regression ile desteklenmeli.
- Notification database channeli, host uygulamada `notifications` tablosu yoksa mail-only fallbacke dusuyor.

## Uygulanan Çözümler
- PDF renderer, package mevcutsa Spatie facade kullanir; degilse deterministic local artifact fallback yazar.
- Notification siniflari queue-isolated calisacak sekilde `subguard-notifications` kuyruuna alinmistir.
- Cancellations ve payment-completed akislarinda notification dispatch noktasi service/job katmanina yerlestirilmistir (provider purity korunur).

## Gelecek Fazlar İçin Notlar
- Security/performance/architecture audit raporlari Faz 5 sonunda eklendi.
- E2E matrix temel kritik kombinasyonlarla kapatildi; ileri genisletmeler v1.1 kapsaminda izlenecek.

## Technical Debt
- Repository genelindeki static analysis bulgulari (config env policy + unused trait) ayrica kapatilacak.
