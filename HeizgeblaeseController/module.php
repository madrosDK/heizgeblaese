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
            // Temperaturen und Ventil-Istwert werden ausschliesslich als Links
            // auf die Originalvariablen angezeigt: inklusive deren Archivhistorie.
            $this->BuildWebfront($key, $label);
        }
        // Pumpen-Iststatus kommt direkt aus der zugeordneten Originalvariable.
        $this->RegisterVariableString('LastError', 'Letzte Fehlermeldung', '', 80);
        $this->RegisterVariableString('PumpDiagnostic', 'Pumpe | Diagnose', '', 75);
        $this->BuildPumpWebfront();
        $this->CleanupLegacyObjects();
        $this->DeleteLegacyChild($this->InstanceID, 'PumpRunning');
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

    // IP-Symcon-Wochenplan ist die alleinige Quelle der Zeitsteuerung.
    // Aktionen 1..3 verwenden die jeweiligen Ventil-Sollwerte.
    // Aktion 0 ist AUS. Ohne Schaltpunkt gilt AUS; bei Tageswechsel wird der
    // letzte Schaltpunkt der Vortage beruecksichtigt (ueber Mitternacht).
    private function FindWindow($key, $now)
    {
        $group = @IPS_GetObjectIDByIdent('Group' . $key, $this->InstanceID);
        if ($group === false) { return null; }
        $scheduleGroup = @IPS_GetObjectIDByIdent('Group' . $key . 'Schedule', $group);
        if ($scheduleGroup === false) { return null; }
        $id = @IPS_GetObjectIDByIdent('Weekplan', $scheduleGroup);
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
        }
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
        // Bestehende Wochenplan-Schaltpunkte niemals automatisch umschreiben.
        // Im WebFront wird beim Erstellen eines neuen Blocks je nach Version
        // oft die letzte Aktion vorgeschlagen: daher AUS zusaetzlich ans Ende.
        IPS_SetEventScheduleAction($id, 0, 'AUS', 0x777777, 'HGC_Refresh(' . $this->InstanceID . ');');
        foreach ([1 => 0x2879C8, 2 => 0x39A672, 3 => 0xE6A23C] as $index => $color) {
            IPS_SetEventScheduleAction($id, $index, 'Stufe ' . $index, $color, 'HGC_Refresh(' . $this->InstanceID . ');');
        }
        // Alte Zusatzaktion 4 nur entfernen, falls kein Schaltpunkt darauf verweist.
        // Andernfalls bleibt sie als AUS erhalten, um bestehende Plaene zu schuetzen.
        if (!$this->ScheduleUsesAction($id, 4)) {
            @IPS_SetEventScheduleAction($id, 4, '', 0, '');
        } else {
            IPS_SetEventScheduleAction($id, 4, 'AUS (Bestand)', 0x777778, 'HGC_Refresh(' . $this->InstanceID . ');');
        }
        if ($fresh) {
            IPS_SetEventActive($id, true);
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
        foreach (['Workshop', 'Garage'] as $key) {
            try {
                $stage = $this->CurrentStage($key);
                SetValueInteger($this->GetIDForIdent($key . 'Stage'), $stage);
            } catch (Throwable $e) {
            }
        }
        $this->ReconcilePump();
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

    // Nur vorhandene, passende Originalvariablen werden verlinkt.
    // Kein Duplikat, kein zwischengespeicherter Messwert und keine neue Archivserie.
    private function LinkOriginal($parent, $ident, $name, $property, $position, $allowedTypes)
    {
        $linkIdent = 'L_' . $ident;
        $old = @IPS_GetObjectIDByIdent($linkIdent, $parent);
        $target = $this->ReadPropertyInteger($property);
        if ($target > 0 && IPS_VariableExists($target)
            && in_array(IPS_GetVariable($target)['VariableType'], $allowedTypes, true)) {
            if ($old !== false && IPS_GetObject($old)['ObjectType'] !== 6) {
                $this->SendDebug('Link', 'Ident bereits durch anderes Objekt belegt: ' . $linkIdent, 0);
                return;
            }
            $this->EnsureLink($parent, $linkIdent, $name, $target, $position);
            IPS_SetHidden($this->GetIDForLink($parent, $linkIdent), false);
        } elseif ($old !== false) {
            // Bei nicht mehr zugeordneter Variable keinen veralteten Link anzeigen.
            IPS_SetHidden($old, true);
        }
    }

    private function GetIDForLink($parent, $ident)
    {
        return IPS_GetObjectIDByIdent($ident, $parent);
    }

    private function BuildWebfront($key, $label)
    {
        $dummy = $this->EnsureDummy('Group' . $key, $label, $this->InstanceID,
            $key === 'Workshop' ? 10 : 20);
        $items = [
            'Mode' => 'Betriebsart', 'Stage' => 'Luefterstufe', 'ValveTarget' => 'Ventil | Manuell',
            'ActiveValve' => 'Ventil | Aktueller Sollwert', 'ActiveWindow' => 'Aktives Zeitfenster'
        ];
        $pos = 10;
        foreach ($items as $suffix => $labelItem) {
            $this->LinkVariable($dummy, $key . $suffix, $labelItem, $pos);
            $link = @IPS_GetObjectIDByIdent('L_' . $key . $suffix, $dummy);
            if ($link !== false) { IPS_SetHidden($link, false); }
            $pos += 10;
        }
        $this->LinkOriginal($dummy, $key . 'ValvePosition', 'Ventil | Istposition',
            $key . 'Valve', 60, [1, 2]);
        $this->LinkOriginal($dummy, $key . 'TemperatureFloat', 'Vorlauftemperatur',
            $key . 'Temp', 70, [2]);
        $this->LinkOriginal($dummy, $key . 'RoomTemperature', 'Raumtemperatur',
            $key . 'RoomTemp', 80, [2]);

        // Alte Links auf kopierte Temperaturen duerfen nicht mehr sichtbar sein.
        foreach (['L_' . $key . 'Temperature', 'L_' . $key . 'TemperatureFloat',
                  'L_' . $key . 'RoomTemperature'] as $legacyLink) {
            // TemperatureFloat/RoomTemperature werden jetzt oben auf Originale umgestellt.
            if ($legacyLink === 'L_' . $key . 'TemperatureFloat'
                || $legacyLink === 'L_' . $key . 'RoomTemperature') { continue; }
            $id = @IPS_GetObjectIDByIdent($legacyLink, $dummy);
            if ($id !== false) { IPS_SetHidden($id, true); }
        }

        $scheduleDummy = $this->EnsureDummy('Group' . $key . 'Schedule', 'Zeitsteuerung', $dummy, 100);
        $this->EnsureWeekplan($key, $scheduleDummy);
        $presetDummy = $this->EnsureDummy('Group' . $key . 'Presets', 'Ventiloeffnung je Stufe', $scheduleDummy, 20);
        for ($n = 1; $n <= 3; $n++) {
            $this->LinkVariable($presetDummy, $key . 'Preset' . $n . 'Valve', 'Stufe ' . $n . ' | Ventil (%)', $n * 20);
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

    // Nur eindeutig identifizierte, vom Modul erzeugte Altobjekte entfernen.
    // Wochenplaene, aktuelle Ventilwerte und sonstige Nutzerdaten nicht loeschen.
    private function DeleteLegacyChild($parent, $ident)
    {
        if ($parent === false) { return; }
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) { return; }
        if (count(IPS_GetChildrenIDs($id)) > 0) {
            $this->SendDebug('Bereinigung', 'Nicht leer, uebersprungen: ' . $ident, 0);
            return;
        }
        $type = IPS_GetObject($id)['ObjectType'];
        try {
            if ($type === 2) { IPS_DeleteVariable($id); }
            elseif ($type === 6) { IPS_DeleteLink($id); }
            elseif ($type === 1) { IPS_DeleteInstance($id); }
        } catch (Throwable $e) {
            $this->SendDebug('Bereinigung', $ident . ': ' . $e->getMessage(), 0);
        }
    }

    private function ScheduleUsesAction($eventID, $actionID)
    {
        $event = IPS_GetEvent($eventID);
        foreach (($event['ScheduleGroups'] ?? []) as $group) {
            foreach (($group['Points'] ?? []) as $point) {
                if ((int) ($point['ActionID'] ?? -1) === $actionID) { return true; }
            }
        }
        return false;
    }

    private function CleanupLegacyObjects()
    {
        foreach (['Workshop', 'Garage'] as $key) {
            $group = @IPS_GetObjectIDByIdent('Group' . $key, $this->InstanceID);
            $schedule = $group === false ? false : @IPS_GetObjectIDByIdent('Group' . $key . 'Schedule', $group);
            $presets = $schedule === false ? false : @IPS_GetObjectIDByIdent('Group' . $key . 'Presets', $schedule);
            // Links zuerst, anschliessend die veralteten Variablen.
            for ($n = 1; $n <= 4; $n++) {
                $oldGroup = $schedule === false ? false : @IPS_GetObjectIDByIdent('Group' . $key . 'Window' . $n, $schedule);
                foreach (['Enabled', 'Days', 'Start', 'End', 'Stage', 'Valve'] as $field) {
                    $ident = $key . 'Window' . $n . $field;
                    $this->DeleteLegacyChild($oldGroup, 'L_' . $ident);
                    $this->DeleteLegacyChild($this->InstanceID, $ident);
                }
                $this->DeleteLegacyChild($schedule, 'Group' . $key . 'Window' . $n);
            }
            foreach ([1, 2, 3, 4] as $n) {
                $this->DeleteLegacyChild($presets, 'L_' . $key . 'Preset' . $n . 'Stage');
            }
            $this->DeleteLegacyChild($presets, 'L_' . $key . 'Preset4Valve');
            foreach ([1, 2, 3, 4] as $n) {
                $this->DeleteLegacyChild($this->InstanceID, $key . 'Preset' . $n . 'Stage');
            }
            $this->DeleteLegacyChild($this->InstanceID, $key . 'Preset4Valve');
            // Alte Ganzzahl-Temperaturen wurden bereits durch Float ersetzt.
            $this->DeleteLegacyChild($group, 'L_' . $key . 'Temperature');
            $this->DeleteLegacyChild($this->InstanceID, $key . 'Temperature');
            $this->DeleteLegacyChild($this->InstanceID, $key . 'RoomTemperatureLegacy');
            // Diese Werte waren Kopien ohne die Original-Archivhistorie.
            // Links im WebFront zeigen inzwischen auf die Originalvariablen.
            $this->DeleteLegacyChild($this->InstanceID, $key . 'TemperatureFloat');
            $this->DeleteLegacyChild($this->InstanceID, $key . 'RoomTemperature');
        }
    }

    private function BuildPumpWebfront()
    {
        $dummy = $this->EnsureDummy('GroupPump', 'Gemeinsame Pumpe', $this->InstanceID, 30);
        $this->LinkOriginal($dummy, 'PumpRunning', 'Pumpenstatus', 'PumpSwitch', 10, [0]);
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
