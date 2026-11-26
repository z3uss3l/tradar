# Audit QuantTrend 12.0.5

## Behobene Integrationsfehler
1. Primäres Dashboard hatte keine Verbindung zur ETF-Empfehlungslogik. `#qt12DashboardSignal` wurde ergänzt; `#decision` wird für aktive ETFs ebenfalls aus `QT12.assess()` aktualisiert.
2. Fehler beim Aufsetzen des Trainings und asynchrone Workerfehler wurden bislang nicht in die ETF-Diagnose übernommen. `state.qt12TrainingErrors` speichert nun den konkreten Fehler je Asset; fehlgeschlagene Trainingsläufe übernehmen diesen Text und einen Diagnosecode.
3. Der vorherige Status „Kein aktuelles Modellresultat“ unterschied nicht zwischen fehlenden Kursdaten und Trainingsfehlern. Die Ausgabe enthält nun `NO_PRICE_DATA`, `TRAINING_FAILED` oder `NO_CURRENT_MODEL` plus Bars, Quelle und Kursbasis soweit verfügbar.

## Verifikation
- JavaScript-Syntax: PASS (alle ausführbaren eingebetteten Blöcke).
- PHP `events.php`: PASS.
- PHP `relay.php`: PASS.
- Python-Bridge `py_compile`: PASS.
- `tests/qt12_decision_tests.js`: 8/8 PASS.
- `tests/qt12_priority_tests.js`: 3/3 PASS.
- ZIP-Integrität und SHA-256 werden nach Paketierung geprüft.

## Nicht verifiziert
- Live-ETF-Kursabrufe vom Produktionsserver.
- Vollständiger Browser-End-to-End-Lauf im produktiven Hosting.
- Prognosegüte / zukünftige Trefferquote.

## Konsequenz
Wenn weiterhin „KEIN SIGNAL“ erscheint, ist das ab dieser Version nicht mehr ohne Ursache: Die betroffene ETF-Zeile zeigt den konkreten Daten- oder Trainingsfehler. KAUFEN/VERKAUFEN werden nicht durch künstliche Wahrscheinlichkeiten oder abgesenkte Qualitätsgrenzen erzwungen.
