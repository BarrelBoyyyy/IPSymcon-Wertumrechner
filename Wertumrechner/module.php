<?php

class Wertumrechner extends IPSModule
{
    private const UNITS = ['W', 'kW', 'A'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Source', 0);
        $this->RegisterPropertyString('SourceUnit', 'W');
        $this->RegisterPropertyBoolean('OutputW', false);
        $this->RegisterPropertyBoolean('OutputkW', true);
        $this->RegisterPropertyBoolean('OutputA', false);
        $this->RegisterPropertyInteger('Phases', 1);
        $this->RegisterPropertyFloat('Voltage1', 230.0);
        $this->RegisterPropertyFloat('Voltage3', 400.0);
        $this->RegisterPropertyFloat('PowerFactor', 1.0);
        $this->RegisterAttributeInteger('SubscribedSource', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $previous = $this->ReadAttributeInteger('SubscribedSource');
        if ($previous > 0) {
            $this->UnregisterMessage($previous, VM_UPDATE);
            $this->UnregisterReference($previous);
        }
        $this->WriteAttributeInteger('SubscribedSource', 0);

        foreach (self::UNITS as $position => $unit) {
            $enabled = $this->ReadPropertyBoolean('Output' . $unit);
            $profile = 'Wertumrechner.' . $unit;
            if ($enabled && !IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileText($profile, '', ' ' . $unit);
                IPS_SetVariableProfileDigits($profile, $unit === 'kW' ? 3 : 2);
            }
            $this->MaintainVariable('Result' . $unit, 'Ergebnis ' . $unit, VARIABLETYPE_FLOAT, $profile, $position, $enabled);
        }

        $source = $this->ReadPropertyInteger('Source');
        if (!$this->IsNumericSource($source)) {
            $this->SetStatus(201);
            return;
        }

        $this->RegisterReference($source);
        $this->RegisterMessage($source, VM_UPDATE);
        $this->WriteAttributeInteger('SubscribedSource', $source);
        $this->UpdateResults($source);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE && $SenderID === $this->ReadAttributeInteger('SubscribedSource')) {
            $this->UpdateResults($SenderID);
        }
    }

    private function IsNumericSource(int $source): bool
    {
        if ($source <= 0 || !IPS_VariableExists($source) || IPS_GetParent($source) === $this->InstanceID) {
            return false;
        }
        return in_array(IPS_GetVariable($source)['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true);
    }

    private function UpdateResults(int $source): void
    {
        $sourceUnit = $this->ReadPropertyString('SourceUnit');
        if (!in_array($sourceUnit, self::UNITS, true)) {
            $this->SetStatus(202);
            return;
        }

        $needsFactor = $sourceUnit === 'A'
            ? $this->ReadPropertyBoolean('OutputW') || $this->ReadPropertyBoolean('OutputkW')
            : $this->ReadPropertyBoolean('OutputA');
        $factor = 1.0;
        if ($needsFactor) {
            $phases = $this->ReadPropertyInteger('Phases');
            $voltage = $this->ReadPropertyFloat($phases === 3 ? 'Voltage3' : 'Voltage1');
            $powerFactor = $this->ReadPropertyFloat('PowerFactor');
            $factor = ($phases === 3 ? sqrt(3.0) : 1.0) * $voltage * $powerFactor;
            if (!in_array($phases, [1, 3], true) || $voltage <= 0 || $powerFactor <= 0
                || $powerFactor > 1 || !is_finite($factor) || $factor <= 0) {
                $this->SetStatus(202);
                return;
            }
        }

        $value = (float) GetValue($source);
        $watts = $value;
        if ($sourceUnit === 'kW') {
            $watts *= 1000.0;
        } elseif ($sourceUnit === 'A') {
            $watts *= $factor;
        }

        $results = ['W' => $watts, 'kW' => $watts / 1000.0, 'A' => $watts / $factor];
        $results[$sourceUnit] = $value;
        $selected = [];
        foreach ($results as $unit => $result) {
            if ($this->ReadPropertyBoolean('Output' . $unit)) {
                if (!is_finite($result)) {
                    $this->SetStatus(203);
                    return;
                }
                $selected[$unit] = $result;
            }
        }
        foreach ($selected as $unit => $result) {
            $ident = 'Result' . $unit;
            if ($this->GetValue($ident) !== $result) {
                $this->SetValue($ident, $result);
            }
        }
        $this->SetStatus(102);
    }
}
