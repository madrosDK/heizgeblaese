HeizgeblaeseController v1.8 – Korrektur Wochenplan

- Behebt "Aktionsfarbe kollidiert mit ActionID 0" beim Erstellen/Anwenden.
- AUS (ActionID 0) = #777777; AUS (Standard, ActionID 4) = #777778.
- Beide AUS-Aktionen schalten Stufe 0 / Ventil 0 %.
- Die letzte Aktion AUS (Standard) soll im IP-Symcon-Wochenplan-Editor
  als Vorschlag statt Stufe 3 dienen; das Editor-Verhalten ist versionsabhaengig.
- Bestehende Wochenplan-Schaltpunkte bleiben unveraendert.
- Keine Daten/alten Variablen werden geloescht.
- Zeitsteuerung, Pumpenlogik und uebrige Einstellungen unveraendert.

WICHTIG: Vor einem Update des produktiven Systems eine Sicherung anlegen,
Schaltfunktionen unter Aufsicht testen.
