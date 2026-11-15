HeizgeblaeseController v1.2 — IP-Symcon PHP Modul

- Master für zwei Heizgebläse (Werkstatt und Garage) mit gemeinsamer Pumpe.
- Jedes Heizgebläse besitzt einen eigenen Betriebsmodus im Objektbaum:
    0 AUS: Gebläse AUS und Ventil auf 0 %
    1 MANUELL: Stufe 0..3 und gespeicherter Ventil-Sollwert
    2 ZEITSTEUERUNG: maximal vier Zeitfenster je Heizgebläse
    3 AUTO: Platzhalter, hält das Gebläse AUS. Spätere Raumtemperaturregelung.
- Im Modus MANUELL ist die Ventilöffnung auch bei AUS vorwählbar; beim
  Einschalten wird der gespeicherte Prozentwert angefahren.
- Zeitsteuerung in der Instanzkonfiguration: je vier editierbare Fenster für
  Werkstatt und Garage. Aktiviert, Tage (täglich/Mo-Fr/Sa-So/einzelne Tage),
  Start HH:MM, Ende HH:MM, Stufe 0..3, Ventilöffnung 0..100 %.
  Beginn inklusive, Ende exklusiv. Zeitfenster über Mitternacht möglich.
  Bei Überschneidungen hat die später gelistete Zeile Vorrang.
  Außerhalb aktiver Zeitfenster schaltet die entsprechende Einheit AUS.
  Zeitschaltprüfung erfolgt mit dem eingestellten Refresh-Timer (Standard 10s).
- Pumpe wird vor dem Einschalten der Lüfter-Relais angefordert und erst
  ausgeschaltet, wenn beide Lüfter nach Statusabfrage AUS sind.
- Relaiskombination Stufe 1 = Ausgang 1, Stufe 2 = Ausgang 2,
  Stufe 3 = beide Ausgänge.
- Vorlauftemperaturen werden als Integer dargestellt.
- Bestehende manuelle Ventil-Sollwerte aus v1.1 bleiben gespeichert.
  Bei Upgrade bestehender v1.1-Instanzen wird der Modus erstmals auf
  MANUELL gesetzt; bei Neuinstallationen ist er zunächst AUS.
- Bei ApplyChanges selbst werden keine Aktoren betätigt. Der Timer
  übernimmt zeitgesteuerte Schaltungen nach der Konfigurationsübernahme.

WICHTIG:
- Ventilposition muss eine schaltbare, beschreibbare Prozentvariable sein.
- Shelly Ausgänge müssen Boolean-Variablen mit RequestAction sein.
- Die Ausgabe eines RequestAction-Befehls ist keine sichere physische
  Pumpen-/Durchflussrückmeldung. Elektrische und hydraulische
  Schutzmaßnahmen sind unabhängig vom Modul vorzusehen.
- Vor Einsatz unbedingt mit realen Hardware-Zuständen testen.
- Keine Temperaturautomatik in v1.2.
