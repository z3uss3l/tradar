# QuantTrend 12.2.0

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
- **Entscheidung:** Prognose und Handelsfreigabe sind getrennte Ausgaben. Bull/Bear-Konflikt, Drawdown, Volatilität, Liquidität und Reflection Memory dürfen die Handelsfreigabe sperren, aber niemals eine vorhandene BUY-/SELL-Richtungsprognose in HOLD umschreiben. Gespeicherte Modellwahrscheinlichkeiten und alte Prognoseeinträge bleiben unverändert.
- **Betrieb:** Kein LLM, keine API-Keys, keine CDN-Abhängigkeit, kein zusätzliches Backend. Reflection-Texte werden deterministisch aus gespeicherten Ergebnissen erzeugt.

Der Bereich „Research Debate · Reflection Memory · Risk Committee“ enthält einen integrierten Algorithmus-Selbsttest. Er prüft Konfliktberechnung, Drawdown, Volatilität, Liquiditätsabdeckung, Fail-closed-Verhalten und prospektive Fehlerdämpfung.

### Forschungsgrundlage

TradingAgents ist inzwischen über mehrere Entwicklungsstände zu unterscheiden: Das ursprüngliche Multi-Agent-Trading-Framework (bis v0.7.1) enthält unter anderem Bull-/Bear-Debatte, drei Risiko-Perspektiven, Point-in-Time-Datenregeln und ein persistentes Decision Log. Der aktuelle Hauptzweig wurde zum Tauric-Finance-Research-Produkt weiterentwickelt (v1.0.x); dessen README nennt einen Tauric-API-Key für den Tauric-Daten-/Modellpfad und führt Ollama als selbst gehostete Modelloption. QuantTrend übernimmt deshalb keine Laufzeitabhängigkeit und keine behauptete „kostenlose keyless Cloud-Inference“.

Referenzen:
- https://github.com/TauricResearch/TradingAgents
- https://github.com/TauricResearch/TradingAgents/releases
- https://github.com/TauricResearch/TradingAgents/blob/main/CHANGELOG.md

### QuantTrend 12.2.0 · Prognose und Handelsfreigabe getrennt

Die bisherige Logik hat Prognose und Handelsfreigabe vermischt: Sobald eine Qualitäts- oder Sicherheitsbedingung nicht erfüllt war, wurde die sichtbare Entscheidung auf HOLD gesetzt. Damit konnte eine klare negative oder positive Modellprognose vollständig verschwinden. Das ist korrigiert.

- **Richtungsprognose:** BUY bei P(steigend) mindestens 40 % und einem Vorsprung gegenüber P(fallend) von mindestens 5 Prozentpunkten; SELL spiegelbildlich. Andernfalls HOLD. Das sind explizite Signal-Schwellen, keine behauptete optimale Handelsstrategie.
- **Handelsfreigabe:** Bleibt separat und verlangt weiterhin die bisherigen Daten-, OOS-, Kalibrierungs-, Modellkonsens-, Event- und Regimebedingungen.
- **Research Debate / Risk Committee:** Konflikt-, Drawdown-, Volatilitäts-, Liquiditäts- und Memory-Vetos sperren nur die Handelsfreigabe. Sie überschreiben nicht mehr BUY/SELL.
- **Oberfläche:** ETF-Tabelle, aktive ETF-Karte, Dashboard und CSV unterscheiden jetzt Richtungsprognose und Handelsfreigabe einschließlich der konkreten Sperrgründe.
- **Semantik:** BUY/SELL bezeichnet eine Modell-Richtungshypothese. Nur „Handelsfreigabe: FREIGEGEBEN“ zeigt, dass die separaten Gates erfüllt sind. Daraus wird keine Order ausgeführt.

### Prüfung 12.2.0

- Vier zusätzliche Regressionstests prüfen BUY-, SELL-, HOLD- und NO_SIGNAL-Klassifikation unabhängig von der Handelsfreigabe.
- Zusammen mit den bisherigen 13 Research-/Memory-/Risk-Tests: 17 deterministische Selbsttests vorgesehen.
- Die geänderte HTML-Datei wurde vollständig im GitHub-Repository aktualisiert. Ein erneuter Parserlauf aller Inline-Skripte und ein Browser-E2E-Test müssen nach dem Quelltext-Update noch separat verifiziert werden.
- Keine neue Laufzeitabhängigkeit und kein API-Key erforderlich.
