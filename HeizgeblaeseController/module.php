<?php

class HeizgeblaeseController extends IPSModule
{
    // Betriebsarten: 0=AUS, 1=Manuell, 2=Zeitsteuerung, 3=Auto (noch nicht aktiv).
    public function Create()
    {
        parent::Create();
        foreach (['PumpSwitch', 'WorkshopOut1', 'WorkshopOut2', 'WorkshopValve', 'WorkshopTemp',
                  'GarageOut1', 'GarageOut2', 'GarageValve', 'GarageTemp',
                  'WorkshopRoomTemp', 'GarageRoomTemp'] as $property) {
            $this->RegisterPropertyInteger($property, 0);
        }
        $this->RegisterPropertyInteger('WorkshopValveDefault', 100);
        $this->RegisterPropertyInteger('GarageValveDefault', 100);
        $this->RegisterPropertyInteger('RefreshSeconds', 10);
        $this->RegisterPropertyBoolean('PumpWatchdog', true);
        foreach (['Workshop', 'Garage'] as $key) {
            $default = [];
            for ($i = 0; $i < 4; $i++) {
                $default[] = ['Enabled' => false, 'Days' => 0,
                    'Start' => sprintf('%02d:00', 6 + 3 * $i),
                    'End' => sprintf('%02d:00', 8 + 3 * $i), 'Stage' => 1, 'Valve' => 100];
            }
            $this->RegisterPropertyString($key . 'Schedule', json_encode($default));
        }
        $this->RegisterTimer('Refresh', 0, 'HGC_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetupProfiles();
        foreach (['Workshop' => 'Werkstatt', 'Garage' => 'Garage'] as $key => $label) {
            $existed = @IPS_GetObjectIDByIdent($key . 'ValveTarget', $this->InstanceID) !== false;
            $modeExisted = @IPS_GetObjectIDByIdent($key . 'Mode', $this->InstanceID) !== false;
            $this->RegisterVariableInteger($key . 'Mode', $label . ' | Betriebsmodus', 'HGC.Mode', 10);
            $this->EnableAction($key . 'Mode');
            // Update von v1.1: bestehende manuelle Steuerung beibehalten.
            // Neue Installationen starten im Modus AUS.
            if (!$modeExisted) {
                SetValueInteger($this->GetIDForIdent($key . 'Mode'), $existed ? 1 : 0);
            }
            $this->RegisterVariableInteger($key . 'Stage', $label . ' | Heizgebläse', 'HGC.Stage', 20);
            $this->EnableAction($key . 'Stage');
            $this->RegisterVariableInteger($key . 'ValveTarget', $label . ' | Ventil-Sollwert Manuell (gespeichert)', 'HGC.ValvePercent', 30);
            $this->EnableAction($key . 'ValveTarget');
            if (!$existed) {
                SetValueInteger($this->GetIDForIdent($key . 'ValveTarget'), max(0, min(100, $this->ReadPropertyInteger($key . 'ValveDefault'))));
            }
            $this->RegisterVariableInteger($key . 'ActiveValve', $label . ' | Aktueller Ventil-Sollwert', 'HGC.ValvePercent', 40);
            $this->RegisterVariableString($key . 'ActiveWindow', $label . ' | Zeitfenster aktiv', '', 50);
            $this->RegisterVariableFloat($key . 'TemperatureFloat', $label . ' | Vorlauftemperatur', '~Temperature', 60);
            $oldRoom = @IPS_GetObjectIDByIdent($key . 'RoomTemperature', $this->InstanceID);
            if ($oldRoom !== false && IPS_GetVariable($oldRoom)['VariableType'] !== 2) {
                // Typ von Bestandsvariablen ist in IP-Symcon nicht aenderbar.
                IPS_SetIdent($oldRoom, $key . 'RoomTemperatureLegacy');
                IPS_SetHidden($oldRoom, true);
            }
            $this->RegisterVariableFloat($key . 'RoomTemperature', $label . ' | Raumtemperatur', '~Temperature', 65);
            $this->BuildScheduleVariables($key); // Nur als bestehende Daten fuer einmalige Migration behalten.
            $legacyTemp = @IPS_GetObjectIDByIdent($key . 'Temperature', $this->InstanceID);
            if ($legacyTemp !== false) { IPS_SetHidden($legacyTemp, true); }
            $this->BuildWebfront($key, $label);
        }
        $this->RegisterVariableBoolean('PumpRunning', 'Pumpe | Status', '~Switch', 70);
        $this->RegisterVariableString('LastError', 'Letzte Fehlermeldung', '', 80);
        $this->RegisterVariableString('PumpDiagnostic', 'Pumpe | Diagnose', '', 75);
        $this->BuildPumpWebfront();
        // Betriebs-Sollwerte nicht durch Konfigurationsspeicherung zuruecksetzen.
        // Beim Speichern oder Neustart keine Relais/Aktoren schalten.
        // Der Timer uebernimmt anschliessend die zeitabhaengige Regelung.
        $this->SetTimerInterval('Refresh', max(2, min(3600, $this->ReadPropertyInteger('RefreshSeconds'))) * 1000);
    }

    private function SetupProfiles()
    {
        if (!IPS_VariableProfileExists('HGC.Stage')) {
            IPS_CreateVariableProfile('HGC.Stage', 1);
        }
        IPS_SetVariableProfileValues('HGC.Stage', 0, 3, 1);
        foreach ([0 => ['AUS', 0x888888], 1 => ['Stufe 1', 0x70B8FF],
                  2 => ['Stufe 2', 0x4CDE9B], 3 => ['Stufe 3', 0xFF9D47]] as $index => $entry) {
            IPS_SetVariableProfileAssociation('HGC.Stage', $index, $entry[0], '', $entry[1]);
        }
        if (!IPS_VariableProfileExists('HGC.Mode')) {
            IPS_CreateVariableProfile('HGC.Mode', 1);
        }
        IPS_SetVariableProfileValues('HGC.Mode', 0, 3, 1);
        foreach ([0 => 'AUS', 1 => 'Manuell', 2 => 'Zeitsteuerung', 3 => 'Auto (vorbereitet)'] as $index => $caption) {
            IPS_SetVariableProfileAssociation('HGC.Mode', $index, $caption, '', 0);
        }
        if (!IPS_VariableProfileExists('HGC.Days')) {
            IPS_CreateVariableProfile('HGC.Days', 1);
        }
        IPS_SetVariableProfileValues('HGC.Days', 0, 9, 1);
        foreach ([0 => 'Täglich', 1 => 'Mo–Fr', 2 => 'Sa–So', 3 => 'Montag', 4 => 'Dienstag',
                  5 => 'Mittwoch', 6 => 'Donnerstag', 7 => 'Freitag', 8 => 'Samstag', 9 => 'Sonntag'] as $v => $name) {
            IPS_SetVariableProfileAssociation('HGC.Days', $v, $name, '', 0);
        }
        if (!IPS_VariableProfileExists('HGC.ValvePercent')) {
            IPS_CreateVariableProfile('HGC.ValvePercent', 1);
        }
        IPS_SetVariableProfileValues('HGC.ValvePercent', 0, 100, 5);
        IPS_SetVariableProfileText('HGC.ValvePercent', '', ' %');
    }

    public function RequestAction($Ident, $Value)
    {
        foreach (['Workshop', 'Garage'] as $key) {
            if ($Ident === $key . 'Mode') {
                $this->SetMode($key, (int) $Value);
                return;
            }
            if ($Ident === $key . 'Stage') {
                $this->SetStage($key, (int) $Value);
                return;
            }
            if (preg_match('/^' . $key . 'Preset([1-3])(Valve)$/', $Ident, $preset)) {
                $this->SetPreset($key, (int) $preset[1], $preset[2], (int) $Value);
                return;
            }
            if ($Ident === $key . 'ValveTarget') {
                $this->SetValveTarget($key, (int) $Value);
                return;
            }
        }
        if (preg_match('/^(Workshop|Garage)Window([1-4])(Enabled|Days|Start|End|Stage|Valve)$/', $Ident, $matches)) {
            $this->SetWindowField($matches[1], (int) $matches[2], $matches[3], $Value);
            return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    private function CheckKey($key)
    {
        if (!in_array($key, ['Workshop', 'Garage'], true)) {
            throw new Exception('Unbekanntes Heizgeblaese.');
        }
    }

    private function VariableID($property)
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            throw new Exception('Variable nicht konfiguriert: ' . $property);
        }
        return $id;
    }

    private function ReadBool($property)
    {
        $id = $this->VariableID($property);
        if (IPS_GetVariable($id)['VariableType'] !== 0) {
            throw new Exception($property . ' muss Boolean sein.');
        }
        return GetValueBoolean($id);
    }

    private function ReadValve($key)
    {
        $id = $this->VariableID($key . 'Valve');
        return (int) round((float) GetValue($id));
    }

    private function Write($property, $value)
    {
        $id = $this->VariableID($property);
        $type = IPS_GetVariable($id)['VariableType'];
        if ($type === 0) {
            $value = (bool) $value;
        } elseif ($type === 1) {
            $value = (int) $value;
        } elseif ($type === 2) {
            $value = (float) $value;
        } else {
            throw new Exception('Ungeeigneter Variablentyp: ' . $property);
        }
        $result = RequestAction($id, $value);
        if ($result === false) {
            throw new Exception('Schaltaktion fehlgeschlagen: ' . $property . ' (ID ' . $id . ')');
        }
    }

    private function WriteIfDifferent($property, $value)
    {
        $id = $this->VariableID($property);
        $current = GetValue($id);
        if (is_bool($value) ? ((bool) $current === $value) : (abs((float) $current - (float) $value) < 0.01)) {
            return;
        }
        $this->Write($property, $value);
    }

    private function CurrentStage($key)
    {
        return ($this->ReadBool($key . 'Out1') ? 1 : 0) + ($this->ReadBool($key . 'Out2') ? 2 : 0);
    }

    private function GetMode($key)
    {
        return GetValueInteger($this->GetIDForIdent($key . 'Mode'));
    }

    public function SetMode($key, $mode)
    {
        $this->CheckKey($key);
        if ($mode < 0 || $mode > 3) {
            throw new Exception('Betriebsmodus ausserhalb 0..3.');
        }
        $id = $this->GetIDForIdent($key . 'Mode');
        if (GetValueInteger($id) === $mode) {
            return;
        }
        try {
            // Umschalten aus Manuell/Zeit: aus Sicherheitsgruenden erst abschalten.
            // Auto besitzt noch keine Raumtemperatursteuerung und bleibt AUS.
            $this->ApplyOutput($key, 0, 0);
            SetValueInteger($id, $mode);
            $this->SetBuffer($key . 'ScheduleOverride', '');
            if ($mode === 2) {
                $this->EvaluateSchedules();
            } else {
                SetValueString($this->GetIDForIdent($key . 'ActiveWindow'), '');
            }
            $this->UpdateScheduleVisibility($key);
            $this->RefreshStatus();
            $this->ClearError();
        } catch (Throwable $e) {
            $this->Error($e->getMessage());
            throw $e;
        }
    }

    public function SetStage($key, $stage)
    {
        $this->CheckKey($key);
        if ($stage < 0 || $stage > 3) {
            throw new Exception('Ungueltige Geblaesestufe.');
        }
        $mode = $this->GetMode($key);
        if ($mode !== 1 && $mode !== 2) {
            throw new Exception('Geblaesestufe nur in Manuell oder Zeitsteuerung direkt einstellbar.');
        }
        try {
            $target = GetValueInteger($this->GetIDForIdent($key . 'ValveTarget'));
            if ($mode === 2) {
                // Nur bis zum naechsten tatsaechlichen Schaltpunkt des Wochenplans.
                $state = $this->FindWindow($key, time());
                $signature = $state === null ? 'none' : $state['Signature'];
                $this->SetBuffer($key . 'ScheduleOverride', json_encode([
                    'Signature' => $signature, 'Stage' => $stage, 'Valve' => $target
                ]));
            }
            $this->ApplyOutput($key, $stage, $stage > 0 ? $target : 0);
            $this->ClearError();
        } catch (Throwable $e) {
            $this->Error($e->getMessage());
            throw $e;
        }
    }

    public function SetValveTarget($key, $target)
    {
        $this->CheckKey($key);
        if ($target < 0 || $target > 100) {
            throw new Exception('Ventil-Sollwert muss zwischen 0 und 100 liegen.');
        }
        try {
            // Der manuelle Sollwert bleibt im Zeitmodus/Auto/AUS gespeichert, ohne den Fahrplan zu ueberschreiben.
            if (in_array($this->GetMode($key), [1, 2], true) && $this->CurrentStage($key) > 0
                && ($this->GetMode($key) === 1 || $this->GetBuffer($key . 'ScheduleOverride') !== '')) {
                $this->WriteIfDifferent($key . 'Valve', $target);
                SetValueInteger($this->GetIDForIdent($key . 'ActiveValve'), $target);
                if ($this->GetMode($key) === 2) {
                    $override = json_decode($this->GetBuffer($key . 'ScheduleOverride'), true);
                    if (is_array($override)) {
                        $override['Valve'] = $target;
                        $this->SetBuffer($key . 'ScheduleOverride', json_encode($override));
                    }
                }
            }
            SetValueInteger($this->GetIDForIdent($key . 'ValveTarget'), $target);
            $this->ClearError();
        } catch (Throwable $e) {
            $this->Error($e->getMessage());
            throw $e;
        }
    }

    private function ApplyOutput($key, $stage, $valve)
    {
        $this->CheckKey($key);
        $stage = max(0, min(3, (int) $stage));
        $valve = $stage > 0 ? max(0, min(100, (int) $valve)) : 0;
        $old = $this->CurrentStage($key);
        // Gewuenschte Stufe puffern: MQTT-Rueckmeldungen koennen verzoegert eintreffen.
        if ($stage > 0) {
            $this->SetBuffer($key . 'DemandStage', (string) $stage);
        }
        $other = $key === 'Workshop' ? 'Garage' : 'Workshop';
        if ($stage > 0) {
            // Vor jedem Relais-EIN Pumpe anfordern, Ventil auf Ziel bringen.
            $this->WriteIfDifferent('PumpSwitch', true);
            // Nachweisbare Pumpenrueckmeldung kann bei MQTT asynchron eintreffen.
            // Hier ist nur die erfolgreiche Schaltanforderung sichergestellt.
            $this->WriteIfDifferent($key . 'Valve', $valve);
        }
        // Erst abfallende Ausgaenge, dann anziehende (kein Zwischenzustand Stufe 3 bei 1->2).
        foreach ([1, 2] as $output) {
            $bit = $output === 1 ? 1 : 2;
            if (($old & $bit) && !($stage & $bit)) {
                $this->Write($key . 'Out' . $output, false);
            }
        }
        foreach ([1, 2] as $output) {
            $bit = $output === 1 ? 1 : 2;
            if (!($old & $bit) && ($stage & $bit)) {
                $this->Write($key . 'Out' . $output, true);
            }
        }
        if ($stage === 0) {
            $this->WriteIfDifferent($key . 'Valve', 0);
        }
        if ($stage === 0) {
            $this->SetBuffer($key . 'DemandStage', '0');
        }
        // Auch bei bereits ausgeschalteten Geblaesen Pumpenstatus zyklisch pruefen.
        $this->ReconcilePump();
        SetValueInteger($this->GetIDForIdent($key . 'Stage'), $this->CurrentStage($key));
        SetValueInteger($this->GetIDForIdent($key . 'ActiveValve'), $valve);
        $this->RefreshStatus();
    }

    // Zeitfenster: Start inklusiv, Ende exklusiv; bei Start > Ende ueber Mitternacht.
    // Days: 0=taeglich, 1=Mo-Fr, 2=Sa-So, 3..9=Mo..So.
    private function DayMatch($selector, $weekday)
    {
        $selector = (int) $selector;
        return $selector === 0 || ($selector === 1 && $weekday <= 5)
            || ($selector === 2 && $weekday >= 6) || ($selector >= 3 && $selector <= 9 && $selector - 2 === $weekday);
    }

    private function Minutes($s)
    {
        if (!is_string($s) || !preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $s, $match)) {
            throw new Exception('Ungueltige Uhrzeit: ' . (string) $s . ' (HH:MM erwartet).');
        }
        return (int) $match[1] * 60 + (int) $match[2];
    }

    // IP-Symcon-Wochenplan ist die alleinige Quelle der Zeitsteuerung.
    // Jede Aktion 1..4 ist ein einstellbares Profil aus Stufe und Ventiloeffnung.
    // Aktion 0 ist AUS. Ohne Schaltpunkt gilt AUS; bei Tageswechsel wird der
    // letzte Schaltpunkt der Vortage beruecksichtigt (ueber Mitternacht).
    private function FindWindow($key, $now)
    {
        $id = @IPS_GetObjectIDByIdent('Weekplan', $this->GetIDForIdent('Group' . $key));
        if ($id === false || !IPS_EventExists($id)) {
            return null;
        }
        $schedule = IPS_GetEvent($id);
        if (empty($schedule['EventActive'])) {
            return null;
        }
        $groups = $schedule['ScheduleGroups'] ?? [];
        // Bis zu 7 Tage zurueck, damit Schaltpunkte ueber Mitternacht wirken.
        for ($back = 0; $back < 7; $back++) {
            $date = strtotime('-' . $back . ' day', strtotime(date('Y-m-d', $now)));
            $daymask = 1 << ((int) date('N', $date) - 1);
            $limit = $back === 0 ? ((int) date('G', $now) * 3600 + (int) date('i', $now) * 60 + (int) date('s', $now)) : 86400;
            $bestTime = -1;
            $action = null;
            $bestPoint = -1;
            foreach ($groups as $group) {
                if (((int) ($group['Days'] ?? 0) & $daymask) === 0) {
                    continue;
                }
                foreach (($group['Points'] ?? []) as $point) {
                    $t = (int) ($point['Start']['Hour'] ?? 0) * 3600
                        + (int) ($point['Start']['Minute'] ?? 0) * 60
                        + (int) ($point['Start']['Second'] ?? 0);
                    if ($t <= $limit && $t >= $bestTime) {
                        $bestTime = $t;
                        $action = (int) ($point['ActionID'] ?? 0);
                        $bestPoint = (int) ($point['ID'] ?? $point['PointID'] ?? 0);
                    }
                }
            }
            if ($action !== null) {
                // Signatur wechselt bei jedem Zeit-Schaltpunkt, auch AUS -> AUS.
                $signature = date('Y-m-d', $date) . ':' . $bestTime . ':' . $action . ':' . $bestPoint;
                if ($action < 1 || $action > 3) {
                    return ['Stage' => 0, 'Valve' => 0, 'Text' => 'AUS (Wochenplan)', 'Signature' => $signature];
                }
                $stage = $action;
                $valve = GetValueInteger($this->GetIDForIdent($key . 'Preset' . $action . 'Valve'));
                return ['Stage' => $stage, 'Valve' => $valve,
                    'Text' => 'Stufe ' . $action . ' (Wochenplan)', 'Signature' => $signature];
            }
        }
        return null;
    }

    private function SetPreset($key, $slot, $field, $value)
    {
        $this->CheckKey($key);
        $limit = $field === 'Stage' ? 3 : 100;
        if ($value < 0 || $value > $limit) {
            throw new Exception('Ungueltiger Profilwert.');
        }
        SetValueInteger($this->GetIDForIdent($key . 'Preset' . $slot . $field), $value);
        if ($this->GetMode($key) === 2) {
            $this->EvaluateSchedules();
        }
    }

    // Vorhandene V1.3-Zeitfenster einmalig in den nativen Wochenplan uebertragen.
    // Bestehende Wochenplaene und die alten Zeitfenster bleiben unveraendert.
    private function MigrateLegacyWindows($key, $eventID)
    {
        $rows = $this->ReadSchedule($key);
        $any = false;
        foreach ($rows as $r) {
            if (!empty($r['Enabled'])) { $any = true; break; }
        }
        if (!$any) { return; }
        for ($n = 1; $n <= 3; $n++) {
            $row = $rows[$n - 1];
            $this->RegisterPresetValue($key, $n, 'Valve', (int) $row['Valve']);
        }
        for ($day = 1; $day <= 7; $day++) {
            $prev = 0;
            $pointID = 1;
            for ($minute = 0; $minute < 1440; $minute++) {
                $wanted = 0;
                $yesterday = $day === 1 ? 7 : $day - 1;
                foreach ($rows as $idx => $r) {
                    if (empty($r['Enabled'])) { continue; }
                    try {
                        $from = $this->Minutes((string) $r['Start']);
                        $to = $this->Minutes((string) $r['End']);
                    } catch (Throwable $e) { continue; }
                    if ($from === $to) { continue; }
                    $selector = (int) $r['Days'];
                    $match = $from < $to
                        ? ($minute >= $from && $minute < $to && $this->DayMatch($selector, $day))
                        : (($minute >= $from && $this->DayMatch($selector, $day))
                            || ($minute < $to && $this->DayMatch($selector, $yesterday)));
                    if ($match) { $wanted = max(0, min(3, (int) $r['Stage'])); }
                }
                if ($minute === 0 || $wanted !== $prev) {
                    IPS_SetEventScheduleGroupPoint($eventID, $day, $pointID++, intdiv($minute, 60), $minute % 60, 0, $wanted);
                    $prev = $wanted;
                }
            }
        }
    }

    private function RegisterPresetValue($key, $slot, $field, $value)
    {
        SetValueInteger($this->GetIDForIdent($key . 'Preset' . $slot . $field), $value);
    }

    private function EnsureWeekplan($key, $parent)
    {
        for ($n = 1; $n <= 3; $n++) {
            $ident = $key . 'Preset' . $n . 'Valve';
            $old = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $this->RegisterVariableInteger($ident, 'Stufe ' . $n . ' | Ventiloeffnung', 'HGC.ValvePercent', 200 + $n * 10);
            $this->EnableAction($ident);
            if ($old === false) {
                SetValueInteger($this->GetIDForIdent($ident), 100);
            }
            // V1.4-Stufenwahl nicht loeschen, nur verbergen.
            $oldStage = @IPS_GetObjectIDByIdent($key . 'Preset' . $n . 'Stage', $this->InstanceID);
            if ($oldStage !== false) { IPS_SetHidden($oldStage, true); }
        }
        $fourthStage = @IPS_GetObjectIDByIdent($key . 'Preset4Stage', $this->InstanceID);
        $fourthValve = @IPS_GetObjectIDByIdent($key . 'Preset4Valve', $this->InstanceID);
        if ($fourthStage !== false) { IPS_SetHidden($fourthStage, true); }
        if ($fourthValve !== false) { IPS_SetHidden($fourthValve, true); }
        $id = @IPS_GetObjectIDByIdent('Weekplan', $parent);
        $fresh = $id === false;
        if ($fresh) {
            $id = IPS_CreateEvent(2);
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, 'Weekplan');
            IPS_SetName($id, 'Wochenplan');
            IPS_SetPosition($id, 10);
            for ($day = 1; $day <= 7; $day++) {
                IPS_SetEventScheduleGroup($id, $day, 1 << ($day - 1));
            }
        }
        // Vorhandene Schaltpunkte von Profil 4 auf Stufe 3 umstellen;
        // der vierte Profilwert wird NICHT automatisch geloescht.
        if (!$fresh) {
            $schedule = IPS_GetEvent($id);
            foreach (($schedule['ScheduleGroups'] ?? []) as $group) {
                $gid = (int) ($group['ID'] ?? 0);
                foreach (($group['Points'] ?? []) as $point) {
                    if ((int) ($point['ActionID'] ?? -1) !== 4) { continue; }
                    $pid = (int) ($point['ID'] ?? -1);
                    if ($gid <= 0 || $pid < 0) { continue; }
                    IPS_SetEventScheduleGroupPoint($id, $gid, $pid,
                        (int) ($point['Start']['Hour'] ?? 0),
                        (int) ($point['Start']['Minute'] ?? 0),
                        (int) ($point['Start']['Second'] ?? 0), 3);
                }
            }
        }
        IPS_SetEventScheduleAction($id, 0, 'AUS', 0x777777, 'HGC_Refresh(' . $this->InstanceID . ');');
        foreach ([1 => 0x2879C8, 2 => 0x39A672, 3 => 0xE6A23C] as $index => $color) {
            IPS_SetEventScheduleAction($id, $index, 'Stufe ' . $index, $color, 'HGC_Refresh(' . $this->InstanceID . ');');
        }
        if ($fresh) {
            $this->MigrateLegacyWindows($key, $id);
            IPS_SetEventActive($id, true);
        } else {
            // Entfernen nur falls kein Schaltpunkt mehr auf Aktion 4 verweist.
            IPS_SetEventScheduleAction($id, 4, '', 0, '');
        }
        return $id;
    }

    public function EvaluateSchedules()
    {
        foreach (['Workshop', 'Garage'] as $key) {
            if ($this->GetMode($key) !== 2) {
                continue;
            }
            try {
                $window = $this->FindWindow($key, time());
                $signature = $window === null ? 'none' : $window['Signature'];
                $stage = $window === null ? 0 : $window['Stage'];
                $valve = $window === null ? 0 : $window['Valve'];
                $text = $window === null ? 'AUS (kein Schaltpunkt)' : $window['Text'];
                $override = json_decode($this->GetBuffer($key . 'ScheduleOverride'), true);
                if (is_array($override)) {
                    if (($override['Signature'] ?? null) === $signature) {
                        $stage = max(0, min(3, (int) ($override['Stage'] ?? 0)));
                        $valve = $stage > 0 ? max(0, min(100, (int) ($override['Valve'] ?? 0))) : 0;
                        $text = 'Manuelle Uebersteuerung bis zum naechsten Schaltpunkt';
                    } else {
                        $this->SetBuffer($key . 'ScheduleOverride', '');
                    }
                }
                $actual = $this->CurrentStage($key);
                $needChange = $actual !== $stage;
                if (!$needChange && $this->ReadPropertyInteger($key . 'Valve') > 0) {
                    $needChange = $this->ReadValve($key) !== $valve;
                }
                if ($needChange) {
                    $this->ApplyOutput($key, $stage, $valve);
                }
                SetValueInteger($this->GetIDForIdent($key . 'ActiveValve'), $valve);
                SetValueString($this->GetIDForIdent($key . 'ActiveWindow'), $text);
                $this->ClearError();
            } catch (Throwable $e) {
                try {
                    $this->ApplyOutput($key, 0, 0);
                } catch (Throwable $inner) {
                    $this->Error($key . ': Sicherheitsabschaltung fehlgeschlagen: ' . $inner->getMessage());
                }
                $this->Error($key . ': ' . $e->getMessage());
            }
        }
    }

    private function ClearError()
    {
        SetValueString($this->GetIDForIdent('LastError'), '');
    }

    private function Error($message)
    {
        $this->SendDebug('Fehler', $message, 0);
        SetValueString($this->GetIDForIdent('LastError'), $message);
    }

    private function RefreshStatus()
    {
        $on = false;
        $known = true;
        foreach (['Workshop', 'Garage'] as $key) {
            try {
                $stage = $this->CurrentStage($key);
                SetValueInteger($this->GetIDForIdent($key . 'Stage'), $stage);
                $on = $on || $stage > 0;
            } catch (Throwable $e) {
                $known = false;
            }
            try {
                SetValueFloat($this->GetIDForIdent($key . 'TemperatureFloat'), (float) GetValue($this->VariableID($key . 'Temp')));
            } catch (Throwable $e) {
                // Temperatur ist optional; bevorzugt Integer.
            }
        }
        try {
            $roomMap = ['Workshop' => 'WorkshopRoomTemp', 'Garage' => 'GarageRoomTemp'];
            foreach ($roomMap as $key => $property) {
                if ($this->ReadPropertyInteger($property) > 0) {
                    $temperature = (float) GetValue($this->VariableID($property));
                    SetValueFloat($this->GetIDForIdent($key . 'RoomTemperature'), $temperature);
                }
            }
        } catch (Throwable $e) {
            $this->Error('Raumtemperatur nicht lesbar: ' . $e->getMessage());
        }
        $this->ReconcilePump();
    }

    // Zeitfenster werden als editierbare Modulvariablen angelegt, keine Konfigurationseingaben.
    // Vorhandene V1.2-Zeitfenster werden bei der erstmaligen Anlage einmalig uebernommen.
    private function BuildScheduleVariables($key)
    {
        $defaults = json_decode($this->ReadPropertyString($key . 'Schedule'), true);
        if (!is_array($defaults)) {
            $defaults = [];
        }
        for ($n = 1; $n <= 4; $n++) {
            $row = $defaults[$n - 1] ?? [];
            $spec = [
                'Enabled' => [0, 'Aktiv', '', (bool) ($row['Enabled'] ?? false)],
                'Days' => [1, 'Wochentage', 'HGC.Days', (int) ($row['Days'] ?? 0)],
                'Start' => [3, 'Beginn (HH:MM)', '', (string) ($row['Start'] ?? '06:00')],
                'End' => [3, 'Ende (HH:MM)', '', (string) ($row['End'] ?? '08:00')],
                'Stage' => [1, 'Luefterstufe', 'HGC.Stage', (int) ($row['Stage'] ?? 1)],
                'Valve' => [1, 'Ventiloeffnung', 'HGC.ValvePercent', (int) ($row['Valve'] ?? 100)]
            ];
            $position = 100 + $n * 10;
            foreach ($spec as $field => $item) {
                $ident = $key . 'Window' . $n . $field;
                $exists = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
                $label = 'Zeitfenster ' . $n . ' | ' . $item[1];
                if ($item[0] === 0) {
                    $this->RegisterVariableBoolean($ident, $label, $item[2], $position++);
                } elseif ($item[0] === 1) {
                    $this->RegisterVariableInteger($ident, $label, $item[2], $position++);
                } else {
                    $this->RegisterVariableString($ident, $label, $item[2], $position++);
                }
                $this->EnableAction($ident);
                if (!$exists) {
                    SetValue($this->GetIDForIdent($ident), $item[3]);
                }
            }
        }
    }

    private function ReadSchedule($key)
    {
        $rows = [];
        foreach (range(1, 4) as $n) {
            $row = [];
            foreach (['Enabled', 'Days', 'Start', 'End', 'Stage', 'Valve'] as $field) {
                $row[$field] = GetValue($this->GetIDForIdent($key . 'Window' . $n . $field));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function SetWindowField($key, $n, $field, $value)
    {
        $this->CheckKey($key);
        $id = $this->GetIDForIdent($key . 'Window' . $n . $field);
        if (in_array($field, ['Start', 'End'], true)) {
            $this->Minutes((string) $value);
            $value = (string) $value;
        } elseif ($field === 'Enabled') {
            $value = (bool) $value;
        } else {
            $value = (int) $value;
            $maximum = ['Days' => 9, 'Stage' => 3, 'Valve' => 100][$field];
            if ($value < 0 || $value > $maximum) {
                throw new Exception('Zeitfenster: Wert ausserhalb des erlaubten Bereichs.');
            }
        }
        if (GetValue($id) === $value) {
            return;
        }
        // Keine ungueltige Zeitspanne aktivieren (Eingabe von Start/Ende bei inaktivem Fenster erlaubt).
        if ($field === 'Enabled' && $value) {
            $prefix = $key . 'Window' . $n;
            if (GetValueString($this->GetIDForIdent($prefix . 'Start')) === GetValueString($this->GetIDForIdent($prefix . 'End'))) {
                throw new Exception('Start und Ende muessen unterschiedlich sein.');
            }
        }
        SetValue($id, $value);
        if ($this->GetMode($key) === 2) {
            $this->EvaluateSchedules();
        }
    }

    // Modulvariablen bleiben direkt unter dem Controller, damit EnableAction/RequestAction
    // wie zuvor funktionieren. Sichtbare Links werden in Dummy-Modulen gruppiert.
    private function EnsureDummy($ident, $name, $parentID, $position)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id === false) {
            $id = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
            IPS_ApplyChanges($id);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        return $id;
    }

    private function EnsureLink($parent, $ident, $name, $target, $position)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) {
            $id = IPS_CreateLink();
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        IPS_SetLinkTargetID($id, $target);
    }

    private function LinkVariable($parent, $ident, $display, $position)
    {
        $variable = $this->GetIDForIdent($ident);
        IPS_SetHidden($variable, true);
        $this->EnsureLink($parent, 'L_' . $ident, $display, $variable, $position);
    }

    private function BuildWebfront($key, $label)
    {
        $dummy = $this->EnsureDummy('Group' . $key, $label, $this->InstanceID,
            $key === 'Workshop' ? 10 : 20);
        $oldTempLink = @IPS_GetObjectIDByIdent('L_' . $key . 'Temperature', $dummy);
        if ($oldTempLink !== false) { IPS_SetHidden($oldTempLink, true); }
        $items = [
            'Mode' => 'Betriebsart', 'Stage' => 'Luefterstufe', 'ValveTarget' => 'Ventil | Manuell',
            'ActiveValve' => 'Ventil | Aktueller Sollwert', 'ActiveWindow' => 'Aktives Zeitfenster',
            'TemperatureFloat' => 'Vorlauftemperatur', 'RoomTemperature' => 'Raumtemperatur'
        ];
        $pos = 10;
        foreach ($items as $suffix => $labelItem) {
            if ($suffix === 'RoomTemperature' && $this->ReadPropertyInteger($key . 'RoomTemp') <= 0) {
                IPS_SetHidden($this->GetIDForIdent($key . $suffix), true);
                $oldLink = @IPS_GetObjectIDByIdent('L_' . $key . $suffix, $dummy);
                if ($oldLink !== false) { IPS_SetHidden($oldLink, true); }
                continue;
            }
            $this->LinkVariable($dummy, $key . $suffix, $labelItem, $pos);
            $link = @IPS_GetObjectIDByIdent('L_' . $key . $suffix, $dummy);
            if ($link !== false) { IPS_SetHidden($link, false); }
            $pos += 10;
        }
        $scheduleDummy = $this->EnsureDummy('Group' . $key . 'Schedule', 'Zeitsteuerung', $dummy, 100);
        $this->EnsureWeekplan($key, $scheduleDummy);
        // Alte v1.3-Zeitfenster behalten, aber im WebFront ausblenden.
        for ($n = 1; $n <= 4; $n++) {
            $old = @IPS_GetObjectIDByIdent('Group' . $key . 'Window' . $n, $scheduleDummy);
            if ($old !== false) { IPS_SetHidden($old, true); }
        }
        $presetDummy = $this->EnsureDummy('Group' . $key . 'Presets', 'Ventiloeffnung je Stufe', $scheduleDummy, 20);
        for ($n = 1; $n <= 3; $n++) {
            $this->LinkVariable($presetDummy, $key . 'Preset' . $n . 'Valve', 'Stufe ' . $n . ' | Ventil (%)', $n * 20);
            $oldLink = @IPS_GetObjectIDByIdent('L_' . $key . 'Preset' . $n . 'Stage', $presetDummy);
            if ($oldLink !== false) { IPS_SetHidden($oldLink, true); }
        }
        foreach (['Stage', 'Valve'] as $field) {
            $oldLink = @IPS_GetObjectIDByIdent('L_' . $key . 'Preset4' . $field, $presetDummy);
            if ($oldLink !== false) { IPS_SetHidden($oldLink, true); }
        }
        $this->UpdateScheduleVisibility($key);
    }

    private function UpdateScheduleVisibility($key)
    {
        $dummy = @IPS_GetObjectIDByIdent('Group' . $key . 'Schedule',
            $this->GetIDForIdent('Group' . $key));
        if ($dummy !== false) {
            IPS_SetHidden($dummy, $this->GetMode($key) !== 2);
        }
        $link = @IPS_GetObjectIDByIdent('L_' . $key . 'ActiveWindow', $this->GetIDForIdent('Group' . $key));
        if ($link !== false) {
            IPS_SetHidden($link, $this->GetMode($key) !== 2);
        }
    }

    private function BuildPumpWebfront()
    {
        $dummy = $this->EnsureDummy('GroupPump', 'Gemeinsame Pumpe', $this->InstanceID, 30);
        $this->LinkVariable($dummy, 'PumpRunning', 'Pumpenstatus', 10);
        $this->LinkVariable($dummy, 'PumpDiagnostic', 'Pumpendiagnose', 20);
        $this->LinkVariable($dummy, 'LastError', 'Letzter Fehler', 30);
    }

    // Wesentlicher Fix gegen Dauerlauf: auch wenn beide Luefter schon AUS sind,
    // im zyklischen Refresh pumpenschaltzustand erneut pruefen und AUS anfordern.
    private function ReconcilePump()
    {
        try {
            $workshop = $this->CurrentStage('Workshop');
            $garage = $this->CurrentStage('Garage');
        } catch (Throwable $e) {
            SetValueString($this->GetIDForIdent('PumpDiagnostic'),
                'Pumpe nicht automatisch ausgeschaltet: Geblaesezustand unbekannt (' . $e->getMessage() . ')');
            return; // Im Zweifelsfall nie die Pumpe unter einem laufenden Luefter ausschalten.
        }
        try {
            $pump = $this->ReadBool('PumpSwitch');
            $demand = $workshop > 0 || $garage > 0
                || (int) $this->GetBuffer('WorkshopDemandStage') > 0
                || (int) $this->GetBuffer('GarageDemandStage') > 0;
            if (!$demand && $pump) {
                $this->Write('PumpSwitch', false);
                SetValueString($this->GetIDForIdent('PumpDiagnostic'), 'AUS angefordert: beide Luefter AUS');
            } elseif ($demand && !$pump && $this->ReadPropertyBoolean('PumpWatchdog')) {
                $this->Write('PumpSwitch', true);
                SetValueString($this->GetIDForIdent('PumpDiagnostic'), 'EIN angefordert: Luefter aktiv');
            } else {
                SetValueString($this->GetIDForIdent('PumpDiagnostic'),
                    $demand ? 'Pumpe erforderlich' : 'Kein Waermebedarf / Pumpe AUS');
            }
            // Tatsachenstatus (keine angenommene Sofortbestaetigung)
            SetValueBoolean($this->GetIDForIdent('PumpRunning'), $this->ReadBool('PumpSwitch'));
        } catch (Throwable $e) {
            $this->Error('Pumpensteuerung: ' . $e->getMessage());
            SetValueString($this->GetIDForIdent('PumpDiagnostic'), 'FEHLER: ' . $e->getMessage());
        }
    }

    public function Refresh()
    {
        $this->EvaluateSchedules();
        $this->RefreshStatus();
    }
}
