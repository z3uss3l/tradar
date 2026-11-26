# Audit QuantTrend 12.0.4

- JavaScript: alle ausführbaren eingebetteten Script-Blöcke mit Node.js --check geprüft.
- PHP: events.php und relay.php mit php -l geprüft.
- Python: quanttrend_bridge.py mit py_compile geprüft.
- Quelltext-Invarianten: Versionskennung, unabhängiger Fortschrittszähler, Monitor-Snapshot und abgestufte Signalentscheidung geprüft.
- Keine externen Live-Kursabrufe und kein echter Browser-E2E-Test in dieser Umgebung; keine Aussage über produktive Feed-Erreichbarkeit oder reale Trefferquote.

## Entscheidungssemantik
Gültige Wahrscheinlichkeiten erzeugen STEIGEND/FALLEND/SEITWÄRTS. KAUFEN/VERKAUFEN nur bei erfüllten statistischen und operativen Qualitätsgates und eindeutiger Event-Freigabe. Andernfalls HALTEN mit Begründung. Ohne gültiges Modellresultat bleibt KEIN SIGNAL.
