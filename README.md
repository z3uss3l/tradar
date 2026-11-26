# QuantTrend AI v12.0.7 — Crash-Safe & Reload-Persistent Release

## Behobene Probleme (v12.0.7)
1. **Kein Neustart von vorne nach Reload:** `lastAutoCompletedAt` und `lastAutoBatchSignature` werden sofort atomar im lokalen Speicher abgelegt; beim Neuladen greift ein 12-Sekunden-Boot-Schutz sowie der 60-Minuten-Cooldown.
2. **Kein Zusammenbruch auf Android / bei Reload:** `inFlightCrashGuard` erkennt abgebrochene Läufe beim Neuladen und pausiert den Auto-Modus automatisch statt in eine Absturzschleife zu laufen.
3. **Single-Flight-Mutex & Sequenzieller ETF-Lauf:** Verhindert parallele Web-Worker beim Laden und Trainieren der priorisierten ETFs (`VWCE.DE`, `EUNL.DE`, `QQQ`).
4. **Kompakte Persistenz:** Speichert bis zu 400 Bars pro Asset sowie alle ETF-Ergebnisse ohne `localStorage`-Quota-Überlauf.
5. **Konsistentes Backend (`12.0.7`):** `events.php`, `relay.php`, `event_catalog.json` und `index.html` sind auf derselben Version inklusive `reclassify`, `knn`, `study`, `graph` und SSRF-Schutz.
