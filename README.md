# QuantTrend 12.0.5

## Fehlerbehebung
- Die aktive ETF-Empfehlung wird jetzt auch in der primären Dashboard-Übersicht (`#qt12DashboardSignal`) und im bestehenden Ensemble-Entscheidungsfeld (`#decision`) angezeigt. Beide Anzeigen verwenden dieselbe berechnete Empfehlung wie die ETF-Kommandozentrale.
- Trainingseinrichtungsfehler, Workerfehler und zu kurze Historien werden am betroffenen ETF gespeichert und mit konkretem Fehlertext in der Empfehlung angezeigt. Das pauschale „Kein aktuelles Modellresultat“ verdeckt diese Fehler nicht mehr.
- Bei fehlendem Kursdatensatz, fehlendem aktuellen Modellresultat und fehlgeschlagenem Training werden unterscheidbare Diagnosecodes und verfügbare Datenmetadaten gespeichert.
- Die bisherige Trennung bleibt erhalten: gültige Modellwahrscheinlichkeiten erzeugen eine Richtungshypothese; KAUFEN/VERKAUFEN benötigen weiterhin sämtliche Qualitäts- und Sicherheitsfreigaben. Ohne gültiges Modellresultat wird kein Signal erfunden.
- Prioritätsreihenfolge, vollständiges ETF-Universum, bestehende Trainingsengine und Datenquellen bleiben erhalten.

## Installation
Das vollständige ZIP-Paket als Ersatz der vorherigen Webversion deployen. Vorher ein Backup der vorhandenen Dateien und Daten anlegen. Browser-Lokaldaten werden durch den Austausch der Webdateien nicht automatisch gelöscht.

## Produktionsdiagnose der Kursversorgung

Nach dem Deployment von `relay.php` kann die tatsächliche ausgehende Datenverbindung des Webhostings ohne API-Schlüssel geprüft werden:

- Alle priorisierten ETFs plus SPY: `https://trade.erdgebunden.de/relay.php?diagnostics=1`
- Einzelnes Asset: `https://trade.erdgebunden.de/relay.php?diagnostics=1&asset=EUNL.DE` (zulässig: `EUNL.DE`, `XMAW.DE`, `IQQE.DE`, `SPY`)

Die Diagnose testet ausschließlich fest codierte Yahoo-Finance- und Stooq-Endpunkte, meldet HTTP-Status, Anzahl erkannter Tagesbars, Datumsbereich sowie PHP-cURL-/Stream-Verfügbarkeit. Es werden keine frei wählbaren Ziel-URLs angenommen und keine Zugangsdaten übertragen. Ein erfolgreicher Probeabruf belegt die Server-Erreichbarkeit der Anbieter, ersetzt aber nicht den vollständigen Browser- und Modell-E2E-Test.

## Prüfung / Grenzen
- Eingebettete JavaScript-Blöcke mit `node --check` geprüft.
- PHP-Syntax für `events.php` und `relay.php` geprüft.
- Python-Bridge kompiliert.
- Bestehende 8 Entscheidungs-Regressionstests und 3 ETF-Prioritätstests bestanden.
- Live-Kursabruf, Browser-E2E und produktiver Hosting-Kontext sind in dieser Umgebung nicht verifizierbar. Die konkrete Laufzeitdiagnose wird jetzt im UI angezeigt, falls ein Feed oder Training auf dem Server/Client fehlschlägt.

## QuantTrend 12.1.0 · deterministische TradingAgents-inspirierte Schicht

Die Anwendung ergänzt die vorhandene Fünf-Modell-Engine um eine lokale Research-Debate-, Reflection-Memory- und Risk-Committee-Schicht. Es wird kein Quellcode aus TradingAgents kopiert; die Verfahren sind eigenständig und passend zur vorhandenen Journal-/Backtest-Architektur implementiert.

- **Bull/Bear-Debatte:** Aggregation der fünf Modellwahrscheinlichkeiten für Aufwärts- und Abwärtsbarriere. Der Konfliktindex ist (1-|P_{bull}-P_{bear}|/(P_{bull}+P_{bear})). Ab 55 % Konflikt wird ein neues BUY-/SELL-Signal blockiert.
- **Reflection Memory:** Nur prospektiv gespeicherte und später vollständig bewertete Prognosen zählen. Die letzten maximal 30 Fälle je Asset liefern Fehlerquote und Konfidenzdämpfung (C_{adjusted}=C_{base}(1-ErrorRate)). Vor 20 Fällen wird daraus kein Veto abgeleitet.
- **Realisierte Rendite / Alpha:** Die Reflection speichert Asset-Rendite über den tatsächlich bewerteten Zeitraum. SPY-Alpha wird nur bei identischer Kurswährung berechnet; ohne FX-Zeitreihe wird ein EUR/USD-Vergleich bewusst ausgelassen.
- **Risk Committee:** Veto bei 252-Bar-Maximaldrawdown von mindestens 25 %, annualisierter 20-Bar-Volatilität ab 55 %, medianem 20-Bar-Tagesumsatz unter 250.000 in Kurswährung oder nicht ausreichend belegter Liquidität. Diese Schwellen sind transparente Schutzparameter und noch nicht empirisch optimiert.
- **Entscheidung:** Die Schicht darf BUY/SELL auf HOLD herabstufen. Sie verändert weder die gespeicherten Modellwahrscheinlichkeiten noch alte Prognoseeinträge.
- **Betrieb:** Kein LLM, keine API-Keys, keine CDN-Abhängigkeit, kein zusätzliches Backend. Reflection-Texte werden deterministisch aus gespeicherten Ergebnissen erzeugt.

Der Bereich „Research Debate · Reflection Memory · Risk Committee“ enthält einen integrierten Algorithmus-Selbsttest. Er prüft Konfliktberechnung, Drawdown, Volatilität, Liquiditätsabdeckung, Fail-closed-Verhalten und prospektive Fehlerdämpfung.

### Forschungsgrundlage

TradingAgents ist inzwischen über mehrere Entwicklungsstände zu unterscheiden: Das ursprüngliche Multi-Agent-Trading-Framework (bis v0.7.1) enthält unter anderem Bull-/Bear-Debatte, drei Risiko-Perspektiven, Point-in-Time-Datenregeln und ein persistentes Decision Log. Der aktuelle Hauptzweig wurde zum Tauric-Finance-Research-Produkt weiterentwickelt (v1.0.x); dessen README nennt einen Tauric-API-Key für den Tauric-Daten-/Modellpfad und führt Ollama als selbst gehostete Modelloption. QuantTrend übernimmt deshalb keine Laufzeitabhängigkeit und keine behauptete „kostenlose keyless Cloud-Inference“.

Referenzen:
- https://github.com/TauricResearch/TradingAgents
- https://github.com/TauricResearch/TradingAgents/releases
- https://github.com/TauricResearch/TradingAgents/blob/main/CHANGELOG.md

### Prüfung 12.1.0

- Alle 13 integrierten deterministischen Selbsttests bestanden.
- Alle 13 ausführbaren Inline-JavaScript-Blöcke mit dem JavaScript-Parser geprüft.
- Keine neue Laufzeitabhängigkeit und kein API-Key erforderlich.
- Ein echter Browser-E2E-Test und eine erneute produktive Kurs-/Trainingstestrunde sind nicht Bestandteil dieser lokalen Quelltextprüfung.
