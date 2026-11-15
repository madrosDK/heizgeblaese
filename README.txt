HeizgeblaeseController v1.9 - Bereinigung

- Keine Zeitfenster-Variablen mehr: nur noch der native IP-Symcon-Wochenplan.
- Entfernt gezielt die 48 veralteten Fenster-Variablen und alte Links/Dummies sowie ausgediente Profilvariablen bei ApplyChanges.
- Bestehende Wochenplan-Schaltpunkte werden nicht veraendert.
- Zusatzaktion 4 wird nur geloescht, wenn sie nicht mehr von einem Schaltpunkt verwendet wird; andernfalls bleibt sie als AUS erhalten.
- Korrigiert Ermittlung des Wochenplans im Unterordner Zeitsteuerung.
- Temperaturanzeigen bleiben Float, die gemeinsame Pumpensteuerung unveraendert.

Bitte vor dem Update IP-Symcon sichern. Das Loeschen der Altvariablen ist gewuenscht und nicht rueckgaengig zu machen.
PHP-Syntax geprueft, kein Test mit realen Aktoren.
