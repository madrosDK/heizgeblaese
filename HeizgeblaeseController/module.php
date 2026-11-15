<?php

class HeizgeblaeseController extends IPSModule
{
    // Betriebsarten: 0=AUS, 1=Manuell, 2=Zeitsteuerung, 3=Auto (noch nicht aktiv).
    public function Create()
    {
        parent::Create();
        foreach (['PumpSwitch', 'WorkshopOut1', 'WorkshopOut2', 'WorkshopValve', 'WorkshopTemp',
                  'GarageOut1', 'GarageOut2', 'GarageValve', 'GarageTemp'] as $property) {
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
            $this->RegisterVariableInteger($key . 'Temperature', $label . ' | Vorlauftemperatur', '~Temperature', 60);
        }
        $this->RegisterVariableBoolean('PumpRunning', 'Pumpe | Status', '~Switch', 70);
        $this->RegisterVariableString('LastError', 'Letzte Fehlermeldung', '', 80);
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
        if (!RequestAction($id, $value)) {
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
            if ($mode === 2) {
                $this->EvaluateSchedules();
            } else {
                SetValueString($this->GetIDForIdent($key . 'ActiveWindow'), '');
            }
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
        if ($this->GetMode($key) !== 1) {
            throw new Exception('Geblaesestufe kann nur im Modus Manuell direkt verstellt werden.');
        }
        try {
            $target = GetValueInteger($this->GetIDForIdent($key . 'ValveTarget'));
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
            if ($this->GetMode($key) === 1 && $this->CurrentStage($key) > 0) {
                $this->WriteIfDifferent($key . 'Valve', $target);
                SetValueInteger($this->GetIDForIdent($key . 'ActiveValve'), $target);
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
        // Pumpe ausschalten ausschliesslich wenn beide Geblaese verifizierbar AUS.
        if ($stage === 0 && $this->CurrentStage('Workshop') === 0 && $this->CurrentStage('Garage') === 0) {
            $this->WriteIfDifferent('PumpSwitch', false);
        }
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

    private function FindWindow($key, $now)
    {
        $rows = json_decode($this->ReadPropertyString($key . 'Schedule'), true);
        if (!is_array($rows)) {
            throw new Exception('Zeitfensterkonfiguration ungueltig: ' . $key);
        }
        $day = (int) date('N', $now);
        $yesterday = $day === 1 ? 7 : $day - 1;
        $minute = (int) date('G', $now) * 60 + (int) date('i', $now);
        $winner = null;
        // Bei Ueberschneidungen gewinnt das spaetere Fenster (hoehere Zeilennummer).
        foreach (array_slice($rows, 0, 4) as $index => $row) {
            if (!is_array($row) || empty($row['Enabled'])) {
                continue;
            }
            $start = $this->Minutes($row['Start'] ?? '');
            $end = $this->Minutes($row['End'] ?? '');
            if ($start === $end) {
                throw new Exception($key . ': Zeitfenster ' . ($index + 1) . ' hat gleiche Start- und Endzeit.');
            }
            $selector = (int) ($row['Days'] ?? 0);
            if ($selector < 0 || $selector > 9) {
                throw new Exception('Ungueltige Tagesauswahl im Zeitfenster.');
            }
            $active = $start < $end
                ? ($minute >= $start && $minute < $end && $this->DayMatch($selector, $day))
                : (($minute >= $start && $this->DayMatch($selector, $day))
                    || ($minute < $end && $this->DayMatch($selector, $yesterday)));
            if ($active) {
                $stage = (int) ($row['Stage'] ?? 0);
                $valve = (int) ($row['Valve'] ?? 0);
                if ($stage < 0 || $stage > 3 || $valve < 0 || $valve > 100) {
                    throw new Exception('Ungueltige Stufe oder Ventiloeffnung im Zeitfenster.');
                }
                $winner = ['Stage' => $stage, 'Valve' => $stage ? $valve : 0,
                           'Text' => 'Fenster ' . ($index + 1) . ': ' . ($row['Start'] ?? '') . '–' . ($row['End'] ?? '')];
            }
        }
        return $winner;
    }

    public function EvaluateSchedules()
    {
        // Einzeln je Heizgeblaese. Auto ist absichtlich deaktiviert, bis die Regelung fertig ist.
        foreach (['Workshop', 'Garage'] as $key) {
            if ($this->GetMode($key) !== 2) {
                continue;
            }
            try {
                $window = $this->FindWindow($key, time());
                $stage = $window === null ? 0 : $window['Stage'];
                $valve = $window === null ? 0 : $window['Valve'];
                $actual = $this->CurrentStage($key);
                $needChange = $actual !== $stage;
                if (!$needChange && $this->ReadPropertyInteger($key . 'Valve') > 0) {
                    $needChange = $this->ReadValve($key) !== $valve;
                }
                if ($needChange) {
                    $this->ApplyOutput($key, $stage, $valve);
                }
                SetValueInteger($this->GetIDForIdent($key . 'ActiveValve'), $valve);
                SetValueString($this->GetIDForIdent($key . 'ActiveWindow'), $window === null ? 'Kein aktives Fenster' : $window['Text']);
                $this->ClearError();
            } catch (Throwable $e) {
                // Bei fehlerhaften Zeitfenstern keine neue EIN-Schaltung. Bereits laufende
                // Heizgeblaese bewusst abschalten; Pumpenstatus bleibt im Fehlerfall sicher.
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
                SetValueInteger($this->GetIDForIdent($key . 'Temperature'), (int) round((float) GetValue($this->VariableID($key . 'Temp'))));
            } catch (Throwable $e) {
                // Temperatur ist optional; bevorzugt Integer.
            }
        }
        try {
            $pump = $this->ReadBool('PumpSwitch');
            SetValueBoolean($this->GetIDForIdent('PumpRunning'), $pump);
            if ($known && $on && !$pump && $this->ReadPropertyBoolean('PumpWatchdog')) {
                $this->Write('PumpSwitch', true);
                SetValueBoolean($this->GetIDForIdent('PumpRunning'), true);
            }
        } catch (Throwable $e) {
            if ($known && $on) {
                $this->Error('Pumpe bei laufendem Geblaese nicht bestaetigt: ' . $e->getMessage());
            }
        }
    }

    public function Refresh()
    {
        $this->EvaluateSchedules();
        $this->RefreshStatus();
    }
}
