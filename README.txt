HeizgeblaeseController v2.1

- Ventil-Istposition ist weiterhin ein direkter Link auf die Originalvariable.
- Nur der Istpositions-Link in der Modulvisualisierung wird mit IPS_SetDisabled auf
  nicht bedienbar gesetzt; Originalvariable und Aktionsfunktion bleiben unberuehrt.
- Der zusaetzliche Link "Ventil | Aktueller Sollwert" wird entfernt.
  Die interne Sollwertvariable wird von der Regelung weiterhin verwendet.
- Die Archivierung der Originalvariable wird nicht veraendert.

Hinweis: IPS_SetDisabled sperrt auch das Anklicken des Links. Abhaengig von
WebFront-Version und Darstellung kann dadurch auch der direkte Zugriff auf
die Historiengrafik ueber diesen Link entfallen. Die Archivdaten bleiben bei
der Originalvariable erhalten und sind dort weiterhin abrufbar.

Bestehende Wochenplaene und Sollwerte bleiben unangetastet.
