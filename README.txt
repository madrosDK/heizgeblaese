HeizgeblaeseController v1.3 - IP-Symcon

NEU
- Zeitfenster direkt im WebFront editierbar. Je 4 pro Bereich (Aktiv, Wochentage,
  Start HH:MM, Ende HH:MM, Stufe 0-3, Ventil 0-100%).
- Dummy-Module Werkstatt und Garage, darin jeweils ein Dummy Zeitsteuerung
  und je vier Zeitfenster-Dummys. Original-Modulvariablen bleiben für
  die internen RequestAction-Aktionen am Controller, WebFront-Links führen
  zu ihnen. Auch die Pumpe hat einen separaten Dummy.
- Neue optionale Raumtemperaturvariable je Raum, Darstellung in Grad Celsius
  als Integer; wenn nicht konfiguriert, wird der Wert nicht eingeblendet.
- Pumpenstatus wird bei jeder Aktualisierung geprüft. Ist bei beiden
  Lüftern der tatsächliche Status AUS und keine Einschaltanforderung aktiv,
  wird die Pumpe auch dann AUS angefordert, wenn die Lüfter schon vorher
  ausgeschaltet waren. Diagnosefeld im Dummy 'Gemeinsame Pumpe'.
- Verzögerte MQTT-Rückmeldungen: interne Soll-Stufen verhindern, dass die
  Pumpe unmittelbar nach einem Lüfter-EIN unbeabsichtigt ausgeschaltet wird.

BESTEHENDE V1.2-INSTANZEN
- Die bisherigen vier Zeitfenster der Konfiguration werden beim ersten Update
  einmalig in WebFront-Variablen importiert. Danach werden sie nur mehr
  dort editiert. Einstellungen der übrigen Variablen bleiben bestehen.
- Keine automatische Löschung bestehender Daten.

BETRIEBSARTEN
0 AUS: Lüfter AUS, Ventil 0%.
1 MANUELL: Lüfterstufe 0..3; gespeicherter Ventilsollwert wird bei EIN
  eingestellt und bei AUS NICHT überschrieben.
2 ZEITSTEUERUNG: unabhängig je Raum; außerhalb der aktiven Fenster AUS.
3 AUTO: bisher nicht implementiert, bleibt AUS.

WICHTIG
- PumpSwitch muss auf die tatsächlich SCHALTBARE Boolean-STATUSVARIABLE
  des Pumpenaktors zeigen, nicht auf dessen Instanz-ID.
- Beide Lüfterausgänge müssen schaltbare Boolean-Variablen sein.
- Bei unbekannten Lüfterzuständen wird Pumpe aus Sicherheitsgründen nicht
  automatisch ausgeschaltet; Diagnosefeld zeigt die Ursache.
- Die Modullogik allein überwacht keinen Durchfluss oder realen Pumpenanlauf.
- Vor dem Produktiveinsatz Hardwaretest durchführen, insbesondere AUS-Befehl,
  Verhalten bei MQTT-Verzögerungen und Relais-Stufenkombinationen.
- PHP Syntax und JSON-Dateien geprüft; kein IP-Symcon Hardwaretest erfolgt.
