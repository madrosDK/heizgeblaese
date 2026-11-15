HeizgeblaeseController v1.6 – IP-Symcon

AENDERUNG GEGENUEBER v1.5
- Bei Erstellung eines Wochenplans wird an jedem Tag um 00:00 die Aktion AUS
  als Grundzustand angelegt. Bei bestehenden Wochenplaenen wird dieser
  Schaltpunkt nur ergaenzt, wenn am Tagesanfang noch kein Punkt existiert.
- Vorhandene Schaltpunkte und ausdruecklich eingestellte Stufen werden nicht
  veraendert oder automatisch zurueckgesetzt.
- Grund: IP-Symcons nativer Wochenplan hat keine leeren Zeitbereiche. Jede
  Aktion gilt bis zur naechsten. Ein gruen markierter Bereich ist Stufe 2,
  orange markierte Bereiche sind bereits als Stufe 3 hinterlegt und damit
  NICHT automatisch als AUS erkennbar. Bestehende orange Abschnitte, die AUS
  sein sollen, einmalig im Wochenplan auf AUS umstellen; auch am Ende eines
  Heizintervalls einen AUS-Schaltpunkt setzen.
- Alle anderen Funktionen aus v1.5 bleiben erhalten.

HINWEIS
Die Grundstellung AUS um 00:00 beendet absichtlich eine ueber Mitternacht
laufende Stufe. Bei geplanten Nachtintervallen muss die Stufe auch nach 00:00
explizit gesetzt werden.

SICHERHEIT
Keine geaenderten Stufen beim Update; Test in IP-Symcon und an der Hardware
noch erforderlich. Vor dem Update Konfiguration sichern.
