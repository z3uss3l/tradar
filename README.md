# QuantTrend 12.3.2

## Modellszenario: numerische Wahrscheinlichkeitsvalidierung
- Die fünf Modellfamilien und die Szenario-Kalibrierung verwenden jetzt dieselbe zentrale Prüfung für dreiklassige Wahrscheinlichkeitsvektoren.
- Reine IEEE-754-Rundungsabweichungen bis 1e-12 an den Grenzen 0 und 1 werden toleriert und auf den gültigen Intervallrand begrenzt. Die Summe muss weiterhin innerhalb 1e-8 bei 1 liegen. Das behebt legitime Fließkomma-Randfälle, ohne inhaltlich ungültige Wahrscheinlichkeiten zu kaschieren.
- Nicht-endliche Softmax-Logits, ungültige Rohverteilungen und ungültige kalibrierte Verteilungen werden mit konkretem Zahlenvektor abgewiesen. Es gibt keine Ersatzwahrscheinlichkeiten und kein stilles Umverteilen einer fehlerhaften Modellstimme.
- Die Feature-Schema-Prüfungen und das Erfordernis aller fünf gültigen Modellstimmen bleiben bestehen.
- Engine-/Ergebnisversion auf 12.3.2 angehoben; gespeicherte Ergebnisse älterer Versionen bleiben lesbar, können aber für manuelle Szenarien ein erneutes Training erfordern.

## Szenario-Engine: Feature-Schema und Wahrscheinlichkeitsdiagnose
- Der Was-wäre-wenn-Pfad übermittelt jetzt neben den Feature-Werten auch die vollständigen Feature-Namen. Der Worker vergleicht Anzahl und Reihenfolge mit dem Schema, das beim Training der aktuellen fünf Modelle verwendet wurde.
- Jedes Modell speichert die erwartete Feature-Anzahl. Der Scaler weist abweichende Vektorlängen und beschädigte Skalierungsmetadaten explizit zurück, statt fehlende Dimensionen unbemerkt in die Berechnung laufen zu lassen.
- Roh- und kalibrierte Wahrscheinlichkeiten werden pro Modell validiert. Fehler benennen das betroffene Modell und die konkrete Abweichung; das Ensemble wird nur bei fünf gültigen Verteilungen aggregiert.
- Szenarien aus einer anderen Engine-Version oder mit unvollständigem Feature-Schema werden gesperrt und verlangen ein erneutes Training. Wahrscheinlichkeiten werden weder geklemmt noch durch Ersatzwerte repariert.
- Regressionstest: Alle fünf Modellfamilien liefern bei 30 Merkmalen endliche, normierte Wahrscheinlichkeiten; alle fünf weisen einen absichtlich verkürzten 29-Merkmal-Vektor mit einer eindeutigen Schemafehlermeldung zurück.

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

- Fünf zusätzliche Regressionstests prüfen BUY-, SELL-, HOLD- und NO_SIGNAL-Klassifikation sowie explizit, dass ein Risk-Veto die Handelsfreigabe sperrt, aber ein BUY-Signal nicht überschreibt.
- Zusammen mit den bisherigen 13 Research-/Memory-/Risk-Tests: 18 deterministische Selbsttests.
- Die geänderte HTML-Datei wurde vollständig im GitHub-Repository aktualisiert. Ein erneuter Parserlauf aller Inline-Skripte und ein Browser-E2E-Test müssen nach dem Quelltext-Update noch separat verifiziert werden.
- Keine neue Laufzeitabhängigkeit und kein API-Key erforderlich.


## QuantTrend 12.3.0 · Historical Context & Volatility Regime Engine

### Ziel und Modellarchitektur

Die Prognoseengine verwendet jetzt zusätzlich eine zeitkausale Kontextrepräsentation. Historische Kursposition, Trendhorizonte und relatives Volatilitätsregime werden nicht als nachträgliche Kauf-/Verkaufsschwellen behandelt, sondern als Merkmale in denselben fünf separat trainierten Klassifikatoren. Dadurch können die Modelle lernen, ob ein Merkmal in einem bestimmten Regime mit der späteren oberen oder unteren Barriere zusammenhängt. Die Richtung wird nicht durch feste Regeln vorgegeben.

**14 zusätzliche Kurs-/Regimemerkmale pro historischer Beobachtung:**

1. Abstand zum bis dahin höchsten Schlusskurs des verfügbaren Datensatzes.
2. Abstand zum höchsten Schlusskurs der letzten 252 Bars.
3. Abstand zum bis dahin niedrigsten Schlusskurs.
4. Abstand zum rollierenden 252-Bar-Mittelwert.
5. Abstand zum rollierenden 252-Bar-Median.
6. Abstand zum SMA50.
7. Rendite über 60 Bars.
8. Rendite über 120 Bars.
9. Realisierte tägliche Volatilität über 60 Bars.
10. Realisierte tägliche Volatilität über 252 Bars.
11. Verhältnis kurzfristiger zu langfristiger Volatilität.
12. Perzentil der aktuellen 20-Bar-Volatilität gegenüber den vorangegangenen bis zu 252 Beobachtungen.
13. ATR-Perzentil gegenüber den vorangegangenen bis zu 252 Beobachtungen.
14. Zeit seit dem bisherigen Datenhoch, normiert auf 252 Handelstage.

Die bereits vorhandenen Merkmale bleiben vollständig erhalten: RSI, 1-/5-/20-Bar-Renditen, Volumenverhältnis, SMA20/50-Abstände, ATR, MACD, 20-Bar-Volatilität, Tagesrange, Close-Lage, Gap, Volumenänderung, Fractional Differencing, CUSUM sowie alle zeitlich verfügbaren externen Faktoren. Die fünf Modellfamilien und die bestehende OOS-/Kalibrierungslogik bleiben erhalten.

### Historische Kennzahlen in der Oberfläche

Das Dashboard zeigt für das aktive Asset:
- letzten Schlusskurs und Datenstand,
- höchsten Kurs im tatsächlich verfügbaren Datensatz samt Datum und Drawdown,
- arithmetischen Mittelwert über die verfügbare Historie,
- 252-Bar-Mittelwert und -Median,
- 252-Bar-Hoch und verfügbares historisches Tief,
- annualisierte realisierte Volatilität über 20 und bis zu 252 Bars,
- Perzentil der kurzfristigen Volatilität innerhalb der zurückliegenden Verteilung,
- Verhältnis kurzfristiger zu langfristiger Volatilität.

**Interpretationsgrenze:** „Höchster verfügbarer Kurs“ ist bewusst nicht als garantiertes Allzeithoch bezeichnet. Die geladene Zeitreihe kann vor dem tatsächlichen Börsenbeginn des ETF liegen oder unvollständig sein. Mittelwerte sind Referenzstatistiken, keine fair-value-Schätzung und kein automatisches Kursziel. Bei unbereinigten Kursen können Splits historische Vergleiche verfälschen; die bestehende Preisbasis-Prüfung bleibt deshalb bestehen.

### Volatilität und Regime: Forschungsstand

Die Implementierung orientiert sich an einem wichtigen Ergebnis der aktuellen Volatilitätsforschung: Komplexere neuronale Netze sind nicht automatisch überlegen. Kılıç (Federal Reserve, 2025) vergleicht ARFIMA, HAR, regime-switching HAR (THAR/STHAR/MSHAR), XGBoost und neuronale Netze anhand rollierender S&P-500-Prognosen 2006–2023. STHAR erzielt im Gesamtzeitraum die niedrigsten MSPE- und QLIKE-Verluste; regimeabhängige ökonometrische Modelle schneiden besonders bei begrenzter Prädiktorzahl robust ab. Ding, Kambouroudis und McMillan (2025) finden bei acht internationalen Aktienmärkten Vorteile von Markov-Switching-HAR insbesondere auf Wochen- und Monatshorizonten, während die tägliche Prognose gemischter ausfällt.

Daraus folgt für QuantTrend:
- Volatilität nicht nur als absolute Zahl, sondern relativ zur eigenen Historie modellieren.
- Kurz-, Mittel- und Langfristregime unterscheiden.
- Volatilitätsanstieg und -abkühlung als unterschiedliche Zustände erfassen.
- Ein einfaches, zeitkausales Merkmal nicht mit einem vollständig implementierten HAR- oder Markov-Switching-HAR-Modell verwechseln.

Die neue Engine ergänzt regimebezogene Features. Sie behauptet **nicht**, bereits einen vollständigen HAR-RV-, STHAR- oder MS-TVTP-HAR-Schätzer zu enthalten. Dafür wären intraday-basierte Realized-Volatility-Reihen und eine separate, horizon-spezifische Volatilitätsprognose erforderlich. Ebenso wird kein VIX-Wert erfunden: Implizite Volatilität muss als echte, zeitgestempelte Faktorhistorie importiert bzw. über eine künftig integrierte Quelle bezogen werden.

### Makroökonomie, News und Weltgeschehen

Die bestehende Faktor- und Ereignisarchitektur bleibt aktiv und wird durch die neuen Kursregime-Merkmale ergänzt:
- Numerische Makrofaktoren werden mit `observed_at` und `available_at` geführt. Für historische Trainingszeilen wird ein Faktor nur verwendet, wenn er zum jeweiligen Prognosezeitpunkt bereits verfügbar war.
- Nachrichten werden dedupliziert, zeitlich geprüft und nach Quellenklasse, Relevanz, Unsicherheit und Alter gewichtet.
- Aus erkannten Meldungen werden fünf numerische Kontextmerkmale gebildet: `news_pressure`, `news_conflict`, `news_coverage`, `news_uncertainty` und `news_quality`.
- Diese Merkmale werden erst ab ihrer tatsächlichen Erfassung verfügbar gemacht. Sie werden nicht rückwirkend in historische Zeitpunkte geschrieben.
- News-Features können im bestehenden UI über „Experimentelle Modellmerkmale“ aktiviert werden. Nach Aktivierung ist ein erneutes Training erforderlich.
- Ein Event-Guard mit unvollständiger Quellenabdeckung wird nicht als Entwarnung interpretiert. Ein erkannter Trigger kann die Handelsfreigabe blockieren, erzeugt aber nicht automatisch eine positive oder negative Kursrichtung.

Das ist absichtlich konservativ. Schlagzeilen enthalten oft mehrdeutige Kausalität, Wiederholungen und verzögerte Preisreaktionen. Bodilsen und Lunde (Journal of Applied Econometrics, 2025) berichten, dass makroökonomische News-Sentiment-Merkmale die Realized-Volatility-Prognose in ihrem Datensatz verbessern; unternehmensbezogene News-Sentiments waren im allgemeinen Modell deutlich schwächer, während die Zahl über Nacht veröffentlichter Unternehmensmeldungen bei der Ein-Tages-Prognose helfen konnte. Plíhal (2021) findet bei EUR/USD, GBP/USD und USD/JPY einen statistisch signifikanten Beitrag geplanter Makroankündigungen zur Volatilitätsprognose, mit bis zu 12,4 % Prognoseverbesserung in der untersuchten Stichprobe. Li et al. (2022) finden unter anderem für VIX und Equity-Market-Volatility-Indikatoren zusätzlichen Informationswert; der relative Nutzen einzelner Indizes variiert jedoch mit dem Regime. Boehm und Kroner (NBER, 2023) dokumentieren, dass US-Makronachrichten internationale Aktienindizes, VIX und Rohstoffe gleichzeitig bewegen können.

Diese Resultate rechtfertigen die Aufnahme solcher Daten als Kandidatenmerkmale, nicht die Übernahme ihrer Koeffizienten oder die Annahme eines universellen Effekts. QuantTrend lernt die Beziehung asset- und horizon-spezifisch aus den eigenen späteren Ergebnissen. News-Rulescores sind zunächst heuristische Messgrößen, keine kalibrierten Wahrscheinlichkeiten eines Kursereignisses.

### Validierung und Schutz vor Scheinerkenntnissen

- **Point-in-time:** Alle Kursmerkmale einer Zeile verwenden ausschließlich Bars bis einschließlich des damaligen Schlusskurses. Externe Daten werden über ihre tatsächlichen Verfügbarkeitszeitstempel gefiltert.
- **Warm-up:** 60-/120-Bar-Momentum bleibt fehlend, solange der jeweilige Horizont nicht vollständig vorliegt. Fehlende Werte werden im bestehenden Trainings-Scaler ausschließlich mit Trainingsmedians imputiert und separat als Missingness-Features markiert.
- **Versionierung:** Die Engine-Ergebnisversion wurde auf `12.3.0` angehoben. Ergebnisse älterer Featurevektoren werden dadurch nicht stillschweigend als aktuell akzeptiert; betroffene Assets müssen neu trainiert werden.
- **Keine künstliche Gewissheit:** Neue Merkmale verändern die Wahrscheinlichkeiten erst nach erneutem Training. Ein zusätzlicher Featureblock ist kein Beweis für bessere Prognosegüte.
- **Bewertung:** LogLoss, Brier Score, ECE, Basisratenvergleich, OOS-Abdeckung und realisierte Handelskennzahlen bleiben entscheidend. Feature-Ablation muss denselben zeitlichen Testplan verwenden: Basismodell gegen Basismodell plus historische Kontextfeatures gegen Basismodell plus Kontext und externe Faktoren.
- **Selektionsrisiko:** Hyperparameter- und Featureauswahl müssen die Zahl getesteter Varianten berücksichtigen. PBO/CSCV und Deflated Sharpe Ratio sind geeignete ergänzende Diagnosen, aber kein Ersatz für einen unangetasteten prospektiven Testzeitraum.

### Prüfung 12.3.0

- 13 ausführbare JavaScript-Inline-Blöcke syntaktisch erfolgreich geparst.
- Engine-Dataset mit 700 deterministischen Testbars ausgeführt: 650 Trainingszeilen erzeugt.
- 30 Featurepositionen (16 bestehende + 14 neue) stimmen mit der Merkmalsvektorbreite überein.
- Keine nicht-endlichen Merkmalswerte außer ausdrücklich fehlenden Werten.
- Kausalitätstest bestanden: Merkmalsvektor für denselben Stichtag ist identisch, wenn spätere Bars an den Datensatz angehängt werden.
- Das sind Engine-/Syntax-/Kausalitätstests, kein historischer Renditebeweis. Noch nicht durchgeführt: Browser-E2E, vollständiger ETF-Neulauf mit Livekursen und statistischer Ablation auf einem unberührten OOS-Zeitraum.

### Forschungsquellen

- Kılıç (2025), Federal Reserve FEDS 2025-061, *Linear and nonlinear econometric models against machine learning models: realized volatility prediction*: https://www.federalreserve.gov/econres/feds/files/2025061pap.pdf
- Ding, Kambouroudis & McMillan (2025), *Forecasting realised volatility using regime-switching models*: https://doi.org/10.1016/j.iref.2025.104171
- Bodilsen & Lunde (2025), *Exploiting News Analytics for Volatility Forecasting*: https://onlinelibrary.wiley.com/doi/full/10.1002/jae.3095
- Plíhal (2021), *Scheduled macroeconomic news announcements and Forex volatility forecasting*: https://onlinelibrary.wiley.com/doi/10.1002/for.2773
- Li et al. (2022), *Forecasting stock market volatility with a large number of predictors: MS-MIDAS-LASSO*: https://pmc.ncbi.nlm.nih.gov/articles/PMC9039984/
- Boehm & Kroner (2023), NBER Working Paper 30994, *The US, Economic News, and the Global Financial Cycle*: https://www.nber.org/papers/w30994
- López de Prado (2018), *Advances in Financial Machine Learning*, Purged/Embargoed CV: https://agorism.dev/book/finance/ml/Marcos%20Lopez%20de%20Prado%20-%20Advances%20in%20Financial%20Machine%20Learning-Wiley%20%282018%29.pdf
- Bailey et al., *The Probability of Backtest Overfitting*: https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf
- Dimitriadis et al. (2024), *Evaluating probabilistic classifiers: The triptych*: https://doi.org/10.1016/j.ijforecast.2023.09.007
