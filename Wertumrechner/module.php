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
        $this->RegisterPropertyString('Sources', '');
        $this->RegisterAttributeInteger('SubscribedSource', 0);
        $this->RegisterAttributeString('Subscriptions', '[]');
        $this->RegisterAttributeString('ManagedOutputs', '');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['elements'][1]['values'] = $this->GetSources();
        return json_encode($form);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $previous = json_decode($this->ReadAttributeString('Subscriptions'), true);
        $legacySubscription = $this->ReadAttributeInteger('SubscribedSource');
        if ($legacySubscription > 0) {
            $previous[] = $legacySubscription;
            $this->WriteAttributeInteger('SubscribedSource', 0);
        }

        $sources = [];
        $outputs = [];
        $status = 102;
        foreach ($this->GetSources() as $row) {
            $source = (int) ($row['Source'] ?? 0);
            if (isset($sources[$source]) || !$this->IsNumericSource($source)) {
                $status = 201;
                continue;
            }
            $sources[$source] = $row;
            foreach (self::UNITS as $unit) {
                if (!empty($row['Output' . $unit])) {
                    $ident = $this->OutputIdent($source, $unit);
                    $this->MaintainVariable($ident, $this->OutputName($source, $unit), VARIABLETYPE_FLOAT,
                        $this->EnsureProfile($unit), count($outputs), true);
                    $this->RenameOutput($source, $unit);
                    $outputs[] = $ident;
                }
            }
            $resultStatus = $this->UpdateResults($source, $row);
            if ($status === 102) {
                $status = $resultStatus;
            }
        }

        $managed = $this->ReadAttributeString('ManagedOutputs');
        $oldOutputs = $managed === '' ? ['ResultW', 'ResultkW', 'ResultA'] : json_decode($managed, true);
        foreach (array_diff($oldOutputs, $outputs) as $ident) {
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
                $this->UnregisterVariable($ident);
            }
        }
        $this->WriteAttributeString('ManagedOutputs', json_encode($outputs));

        $current = array_keys($sources);
        foreach (array_diff($previous, $current) as $source) {
            $this->UnregisterMessage($source, VM_UPDATE);
            $this->UnregisterMessage($source, OM_CHANGENAME);
            $this->UnregisterReference($source);
        }
        foreach ($current as $source) {
            $this->RegisterReference($source);
            $this->RegisterMessage($source, VM_UPDATE);
            $this->RegisterMessage($source, OM_CHANGENAME);
        }
        $this->WriteAttributeString('Subscriptions', json_encode($current));
        $this->SetStatus($status);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if (!in_array($Message, [VM_UPDATE, OM_CHANGENAME], true) || !$this->IsNumericSource($SenderID)) {
            return;
        }
        foreach ($this->GetSources() as $row) {
            if ((int) ($row['Source'] ?? 0) !== $SenderID) {
                continue;
            }
            if ($Message === VM_UPDATE) {
                $status = $this->UpdateResults($SenderID, $row);
                if ($status !== 102) {
                    $this->SetStatus($status);
                } elseif (IPS_GetInstance($this->InstanceID)['InstanceStatus'] === 203) {
                    $this->ApplyChanges();
                }
                return;
            }
            foreach (self::UNITS as $unit) {
                if (!empty($row['Output' . $unit])) {
                    $this->RenameOutput($SenderID, $unit);
                }
            }
            return;
        }
    }

    private function GetSources(): array
    {
        $configured = $this->ReadPropertyString('Sources');
        if ($configured !== '') {
            return json_decode($configured, true) ?? [];
        }
        $source = $this->ReadPropertyInteger('Source');
        if ($source <= 0) {
            return [];
        }
        return [[
            'Source' => $source,
            'SourceUnit' => $this->ReadPropertyString('SourceUnit'),
            'OutputW' => $this->ReadPropertyBoolean('OutputW'),
            'OutputkW' => $this->ReadPropertyBoolean('OutputkW'),
            'OutputA' => $this->ReadPropertyBoolean('OutputA'),
            'Phases' => $this->ReadPropertyInteger('Phases'),
            'Voltage1' => $this->ReadPropertyFloat('Voltage1'),
            'Voltage3' => $this->ReadPropertyFloat('Voltage3'),
            'PowerFactor' => $this->ReadPropertyFloat('PowerFactor')
        ]];
    }

    private function OutputIdent(int $source, string $unit): string
    {
        // Keep the original output IDs for the source configured in version 1.0.
        if ($source === $this->ReadPropertyInteger('Source')) {
            return 'Result' . $unit;
        }
        return 'Result' . $source . $unit;
    }

    private function OutputName(int $source, string $unit): string
    {
        return IPS_GetName($source) . ' ' . ($unit === 'A' ? 'Ampere' : $unit);
    }

    private function RenameOutput(int $source, string $unit): void
    {
        $id = @IPS_GetObjectIDByIdent($this->OutputIdent($source, $unit), $this->InstanceID);
        $name = $this->OutputName($source, $unit);
        if ($id !== false && IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
    }

    private function EnsureProfile(string $unit): string
    {
        $profile = 'Wertumrechner.' . $unit;
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText($profile, '', ' ' . $unit);
            IPS_SetVariableProfileDigits($profile, $unit === 'kW' ? 3 : 2);
        }
        return $profile;
    }

    private function IsNumericSource(int $source): bool
    {
        if ($source <= 0 || !IPS_VariableExists($source) || IPS_GetParent($source) === $this->InstanceID) {
            return false;
        }
        return in_array(IPS_GetVariable($source)['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true);
    }

    private function UpdateResults(int $source, array $row): int
    {
        $sourceUnit = $row['SourceUnit'] ?? '';
        if (!in_array($sourceUnit, self::UNITS, true)) {
            return 202;
        }

        $needsFactor = $sourceUnit === 'A'
            ? !empty($row['OutputW']) || !empty($row['OutputkW'])
            : !empty($row['OutputA']);
        $factor = 1.0;
        if ($needsFactor) {
            $phases = (int) ($row['Phases'] ?? 0);
            $voltage = (float) ($row[$phases === 3 ? 'Voltage3' : 'Voltage1'] ?? 0);
            $powerFactor = (float) ($row['PowerFactor'] ?? 0);
            $factor = ($phases === 3 ? sqrt(3.0) : 1.0) * $voltage * $powerFactor;
            if (!in_array($phases, [1, 3], true) || $voltage <= 0 || $powerFactor <= 0
                || $powerFactor > 1 || !is_finite($factor) || $factor <= 0) {
                return 202;
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
            if (!empty($row['Output' . $unit])) {
                if (!is_finite($result)) {
                    return 203;
                }
                $selected[$unit] = $result;
            }
        }
        foreach ($selected as $unit => $result) {
            $ident = $this->OutputIdent($source, $unit);
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false && $this->GetValue($ident) !== $result) {
                $this->SetValue($ident, $result);
            }
        }
        return 102;
    }
}
