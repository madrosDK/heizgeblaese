HeizgeblaeseController v1.7 – IP-Symcon

Basiert auf v1.5 (nicht auf v1.6).

- Originaler nativer Wochenplan aus v1.5 bleibt unveraendert.
- Keine erzwungenen 00:00-Schaltpunkte.
- Stufe 1, 2, 3 bleiben erhalten. AUS ist sowohl Aktion 0 als auch als LETZTE Aktion 4 verfuegbar. Dadurch wird beim Aufziehen neuer Bereiche in WebFront, sofern IP-Symcon die letzte Aktion als Standard verwendet, AUS statt Stufe 3 vorgeschlagen.
- Bestehende Schaltpunkte werden NICHT automatisch umgeschrieben, zurueckgesetzt oder geloescht.
- Fruehere Zeitfenster-Variablen bleiben als Migrationsdaten bestehen, werden aber im Objektbaum versteckt.
- Schaltpunkte mit Aktion 4 werden als AUS interpretiert.
- Manuelle Uebersteuerung, Raum-/Vorlauftemperaturen, Pumpenlogik aus v1.5 bleiben erhalten.

EINSCHRAENKUNG: Das Standardverhalten des grafischen IP-Symcon-Wochenplan-Editors ist nicht ueber ein PHP-Modul frei konfigurierbar. Die zusaetzliche letzte AUS-Aktion soll den Default verbessern; ob dies bei deiner Symcon-Version tatsaechlich die automatische Farb-/Aktionswahl beeinflusst, muss im WebFront geprueft werden. Bestehende orange Stufe-3-Bloecke muessen manuell zu AUS geaendert werden, falls sie nicht absichtlich erstellt wurden.

Installation wie bisher als Update des vorhandenen Moduls. Vor Aktualisierung der produktiven Steuerung Sicherung anlegen und Relais sowie Pumpe unter Aufsicht testen.
