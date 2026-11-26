# QuantTrend 12.0.5

## Fehlerbehebung
- Die aktive ETF-Empfehlung wird jetzt auch in der primären Dashboard-Übersicht (`#qt12DashboardSignal`) und im bestehenden Ensemble-Entscheidungsfeld (`#decision`) angezeigt. Beide Anzeigen verwenden dieselbe berechnete Empfehlung wie die ETF-Kommandozentrale.
- Trainingseinrichtungsfehler, Workerfehler und zu kurze Historien werden am betroffenen ETF gespeichert und mit konkretem Fehlertext in der Empfehlung angezeigt. Das pauschale „Kein aktuelles Modellresultat“ verdeckt diese Fehler nicht mehr.
- Bei fehlendem Kursdatensatz, fehlendem aktuellen Modellresultat und fehlgeschlagenem Training werden unterscheidbare Diagnosecodes und verfügbare Datenmetadaten gespeichert.
- Die bisherige Trennung bleibt erhalten: gültige Modellwahrscheinlichkeiten erzeugen eine Richtungshypothese; KAUFEN/VERKAUFEN benötigen weiterhin sämtliche Qualitäts- und Sicherheitsfreigaben. Ohne gültiges Modellresultat wird kein Signal erfunden.
- Prioritätsreihenfolge, vollständiges ETF-Universum, bestehende Trainingsengine und Datenquellen bleiben erhalten.

## Installation
Das vollständige ZIP-Paket als Ersatz der vorherigen Webversion deployen. Vorher ein Backup der vorhandenen Dateien und Daten anlegen. Browser-Lokaldaten werden durch den Austausch der Webdateien nicht automatisch gelöscht.

## Prüfung / Grenzen
- Eingebettete JavaScript-Blöcke mit `node --check` geprüft.
- PHP-Syntax für `events.php` und `relay.php` geprüft.
- Python-Bridge kompiliert.
- Bestehende 8 Entscheidungs-Regressionstests und 3 ETF-Prioritätstests bestanden.
- Live-Kursabruf, Browser-E2E und produktiver Hosting-Kontext sind in dieser Umgebung nicht verifizierbar. Die konkrete Laufzeitdiagnose wird jetzt im UI angezeigt, falls ein Feed oder Training auf dem Server/Client fehlschlägt.
