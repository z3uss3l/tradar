# QuantTrend 12.0.4

## Korrekturen
- Prognoserichtung wird bei gültiger, aktueller Fünf-Modell-Wahrscheinlichkeitsausgabe unabhängig von der Handlungsfreigabe angezeigt.
- KAUFEN/VERKAUFEN bleiben an statistische und operative Qualitäts- sowie Ereignisprüfungen gebunden. Wenn diese nicht erfüllt sind, lautet die Handlung HALTEN mit konkreter Begründung; fehlende/ungültige Modellwahrscheinlichkeiten bleiben KEIN SIGNAL.
- Der Fortschrittszähler zählt abgearbeitete ETFs unabhängig von erfolgreichen Modellbewertungen.
- Empfehlungen und Monitor-Snapshot werden in state.qt12Recommendations und state.qt12Monitor geführt und am Ende persistent gespeichert.
- Fehlerhafte oder nicht verfügbare Daten werden nicht durch synthetische Kursdaten ersetzt.

## Installation
Komplettpaket als vollständigen Ersatz der vorherigen Version auf dem Webserver einsetzen. Vorher vorhandene Nutzerdaten/Backups sichern. Lokale Browserdaten bleiben getrennt vom Webpaket.

## Grenzen
Ein valides Modellresultat ist weiterhin Voraussetzung für eine Richtungsprognose. Ohne echte Kursreihe oder fünf gültige Modelloutputs wird bewusst KEIN SIGNAL angezeigt. Live-Kursversorgung und vollständiger Browser-E2E-Test auf dem Produktionshost konnten hier nicht bestätigt werden.
