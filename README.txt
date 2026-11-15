HeizgeblaeseController v1.5 – IP-Symcon

AENDERUNGEN GEGENUEBER v1.4
- Vorlauf- und Raumtemperatur werden als Float angezeigt. Bisherige Integer-Raumtemperatur-Variablen werden ausgeblendet und bleiben erhalten.
- Werkstatt-/Garage-Zeitsteuerung im WebFront nur sichtbar, wenn jeweils Betriebsart Zeitsteuerung ausgewaehlt ist. Das aktive Zeitfenster wird sonst ebenfalls verborgen.
- Der native Wochenplan kennt AUS, Stufe 1, Stufe 2 und Stufe 3. Die Stufen sind fest zugeordnet; je Stufe ist nur die Ventiloeffnung (0..100 %) konfigurierbar.
- Wo kein Zeit-Schaltpunkt gefunden wird, wird AUS verwendet. Um Luecken zwischen geplanten Zeiten zu erzeugen, im Wochenplan explizit AUS setzen; sonst bleibt die zuletzt aktive Wochenplanaktion bestehen (normales IPS-Wochenplanverhalten).
- Waehrend Zeitsteuerung ist eine manuelle Geblaesestufenwahl (0..3) moeglich. Diese uebersteuert voruebergehend die Zeitsteuerung und bleibt bis zum naechsten Schaltpunkt bestehen. Die manuell gespeicherte Ventiloeffnung wird dabei verwendet.
- Manuelle Ventil-Sollwertaenderung waehrend einer solchen Uebersteuerung wirkt sofort. Danach uebernimmt wieder der Wochenplan.
- Bestehende Wochenplan-Schaltpunkte mit Profil 4 werden bei der Umstellung auf Stufe 3 umgestellt, sofern die Ereignispunkte aktualisierbar sind. Die alten Profilvariablen bleiben verborgen erhalten. Vorhandene sonstige Zeitplanpunkte werden nicht geloescht.

BETRIEBSARTEN
AUS: Geblaese aus und Ventil zu.
MANUELL: Stufe und Ventil manuell.
ZEITSTEUERUNG: Wochenplan + voruebergehende manuelle Uebersteuerung.
AUTO: reserviert, derzeit AUS.

SICHERHEIT
Pumpe laeuft sobald eine Stufe aktiv ist. Die Steuerung bestaetigt weder realen Pumpenanlauf noch realen Durchfluss. Die bestehende zyklische Pumpenpruefung bleibt erhalten. Schaltfolge am Geraet unter Aufsicht testen. Es wurden PHP-Syntax und JSON-Struktur geprueft, kein realer IP-Symcon-Hardwaretest.

INSTALLATION
Modul im bestehenden IP-Symcon-Modul-Repository aktualisieren, Instanz uebernehmen. Vorhandene Instanzvariablen und Wochenplanpunkte bleiben erhalten, mit der dokumentierten Umstellung von Aktion 4 auf 3.
