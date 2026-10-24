<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VisuStyle.php';
require_once __DIR__ . '/../libs/WeeklySchedule.php';
require_once __DIR__ . '/../libs/ThermalModel.php';
require_once __DIR__ . '/../libs/EnergyTracker.php';
require_once __DIR__ . '/../libs/PVOptimizer.php';
require_once __DIR__ . '/../libs/SafetyController.php';
require_once __DIR__ . '/../libs/TemperatureController.php';

use ModuleSHC\EnergyTracker;
use ModuleSHC\PVOptimizer;
use ModuleSHC\SafetyController;
use ModuleSHC\TemperatureController;
use ModuleSHC\ThermalModel;
use ModuleSHC\WeeklySchedule;
use ModuleVisu\Style;

class SmartHeatingControl extends IPSModuleStrict
{
    private const MODE_AUTOMATIC = 0;
    private const MODE_MANUAL = 1;
    private const MODE_OFF = 2;
    private const HEATER_STATE_OFF = 0;
    private const HEATER_STATE_SOCKET_ON = 1;
    private const HEATER_STATE_ELECTRICALLY_ACTIVE = 2;
    private const HEATER_STATE_FEEDBACK_FAULT = 3;
    private const SWITCH_CONFIRMATION_TIMEOUT_SECONDS = 30;
    private const MINIMUM_PLAUSIBLE_TEMPERATURE = -20.0;
    private const MAXIMUM_PLAUSIBLE_TEMPERATURE = 60.0;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('RoomTemperatureID', 0);
        $this->RegisterPropertyInteger('SecondaryTemperatureID', 0);
        $this->RegisterPropertyInteger('HeaterSwitchID', 0);
        $this->RegisterPropertyInteger('PowerVariableID', 0);
        $this->RegisterPropertyInteger('EnergyVariableID', 0);
        $this->RegisterPropertyInteger('NominalHeatingPower', 900);
        $this->RegisterPropertyFloat('TemperatureOffset', 0.0);
        $this->RegisterPropertyFloat('SecondaryTemperatureOffset', 0.0);
        $this->RegisterPropertyFloat('TargetTemperature', 24.0);
        $this->RegisterPropertyFloat('Hysteresis', 0.3);
        $this->RegisterPropertyFloat('MaximumRoomTemperature', 28.0);
        $this->RegisterPropertyInteger('SensorTimeoutSeconds', 900);
        $this->RegisterPropertyInteger('MinimumOnSeconds', 300);
        $this->RegisterPropertyInteger('MinimumOffSeconds', 180);
        $this->RegisterPropertyInteger('ControlIntervalSeconds', 30);
        $this->RegisterPropertyFloat('PowerThreshold', 10.0);
        $this->RegisterPropertyInteger('PowerTimeoutSeconds', 600);
        $this->RegisterPropertyBoolean('WeeklyScheduleEnabled', true);
        $this->RegisterPropertyBoolean('AdaptivePreheatingEnabled', true);
        $this->RegisterPropertyInteger('MaximumPreheatMinutes', 180);
        $this->RegisterPropertyInteger('PreheatSafetyBufferMinutes', 15);
        $this->RegisterPropertyInteger('MinimumHeatingSamples', 3);
        $this->RegisterPropertyFloat('InitialHeatingRateKPerHour', 0.3);
        $this->RegisterPropertyFloat('BoostTemperature', 25.0);
        $this->RegisterPropertyInteger('BoostDurationMinutes', 45);
        $this->RegisterPropertyFloat('MaximumPVTemperatureOffset', 1.0);
        $this->RegisterPropertyInteger('PVPlanningHorizonHours', 24);
        $this->RegisterPropertyInteger('PVPermissionTimeoutSeconds', 0);
        $this->RegisterPropertyFloat('MaximumSensorDifference', 5.0);
        $this->RegisterPropertyInteger('MaximumContinuousOnMinutes', 0);
        $this->RegisterPropertyBoolean('StopAfterMaximumContinuousOnTime', false);
        $this->RegisterPropertyBoolean('ArchiveLoggingEnabled', true);

        $this->RegisterAttributeString('ObservedVariableIDs', '[]');
        $this->RegisterAttributeString('WeeklySchedule', json_encode(WeeklySchedule::default(), JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString('ThermalModel', json_encode(ThermalModel::create(), JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString('EnergyTracker', json_encode(EnergyTracker::create(), JSON_THROW_ON_ERROR));
        $this->RegisterAttributeInteger('ScheduleEditorDay', 1);
        $this->RegisterAttributeBoolean('LastPVHeatingActive', false);
        $this->RegisterAttributeInteger('PVEnableUpdatedAt', 0);
        $this->RegisterAttributeBoolean('BoostActive', false);
        $this->RegisterAttributeInteger('BoostStartedAt', 0);
        $this->RegisterAttributeInteger('BoostExpiresAt', 0);
        $this->RegisterAttributeBoolean('CommandPending', false);
        $this->RegisterAttributeBoolean('LastCommandedSwitchState', false);
        $this->RegisterAttributeInteger('LastSwitchCommandTime', 0);
        $this->RegisterAttributeInteger('SwitchMismatchSince', 0);
        $this->RegisterAttributeInteger('SocketOnSince', 0);
        $this->RegisterAttributeBoolean('RuntimeLockout', false);
        $this->RegisterAttributeString('HistoryChartCache', '{}');
        $this->RegisterAttributeInteger('HistoryChartCacheAt', 0);
        $this->RegisterAttributeString('ArchivedVariableIDs', '[]');

        $this->RegisterTimer('ControlTimer', 0, 'SHC_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BoostTimer', 0, 'SHC_ExpireBoost($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainFloatVariable(
            'RoomTemperature',
            $this->Translate('Room temperature'),
            '°C',
            1,
            -20.0,
            60.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'SecondaryTemperature',
            $this->Translate('Secondary temperature'),
            '°C',
            1,
            -20.0,
            60.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'TemperatureDifference',
            $this->Translate('Temperature difference'),
            ' K',
            1,
            -20.0,
            20.0,
            'temperature-difference'
        );
        $this->MaintainFloatVariable(
            'EffectiveTargetTemperature',
            $this->Translate('Effective target temperature'),
            '°C',
            1,
            5.0,
            35.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'ScheduledTargetTemperature',
            $this->Translate('Scheduled target temperature'),
            '°C',
            1,
            5.0,
            35.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'ManualTargetTemperature',
            $this->Translate('Manual desired temperature'),
            '°C',
            1,
            5.0,
            35.0,
            'temperature-input'
        );
        $this->MaintainFloatVariable(
            'PVTargetTemperature',
            $this->Translate('PV target temperature'),
            '°C',
            1,
            5.0,
            35.0,
            'temperature-input'
        );
        $this->MaintainFloatVariable(
            'PVTargetCeiling',
            $this->Translate('Allowed PV target'),
            '°C',
            1,
            5.0,
            40.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'ExpectedComfortTemperature',
            $this->Translate('Expected temperature at comfort time'),
            '°C',
            1,
            -20.0,
            60.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'CurrentHeatingPower',
            $this->Translate('Current heating power'),
            ' W',
            0,
            0.0,
            10000.0,
            'power'
        );
        $this->MaintainFloatVariable(
            'EnergyTotal',
            $this->Translate('Total energy'),
            ' kWh',
            2,
            0.0,
            10000000.0,
            'energy'
        );
        foreach ([
            'EnergyToday'              => [$this->Translate('Energy today'), 2],
            'EnergyYesterday'          => [$this->Translate('Energy yesterday'), 2],
            'EnergyWeek'               => [$this->Translate('Energy this week'), 2],
            'EnergyMonth'              => [$this->Translate('Energy this month'), 2],
            'RegularHeatingEnergy'     => [$this->Translate('Regular heating energy'), 2],
            'EstimatedPVHeatingEnergy' => [$this->Translate('PV heating energy'), 2],
        ] as $ident => [$name, $digits]) {
            $this->MaintainFloatVariable($ident, $name, ' kWh', $digits, 0.0, 10000000.0, 'energy');
        }
        $this->MaintainFloatVariable(
            'ElectricalHeatingDurationHours',
            $this->Translate('Electrical heating duration'),
            ' h',
            1,
            0.0,
            1000000.0,
            'clock'
        );
        $this->MaintainFloatVariable(
            'SocketOnDurationHours',
            $this->Translate('Socket on duration'),
            ' h',
            1,
            0.0,
            1000000.0,
            'clock'
        );
        $this->MaintainFloatVariable(
            'EstimatedHeatingDuration',
            $this->Translate('Estimated heating duration'),
            ' min',
            0,
            0.0,
            43200.0,
            'clock'
        );
        $this->MaintainFloatVariable(
            'EstimatedHeatingRate',
            $this->Translate('Estimated heating rate'),
            ' K/h',
            2,
            0.0,
            3.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'EstimatedCoolingRate',
            $this->Translate('Estimated cooling rate'),
            ' K/h',
            2,
            0.0,
            3.0,
            'temperature'
        );
        $this->MaintainFloatVariable(
            'EstimatedResidualHeating',
            $this->Translate('Estimated residual heating'),
            ' K',
            2,
            0.0,
            3.0,
            'temperature-difference'
        );
        $this->MaintainFloatVariable(
            'PredictionConfidence',
            $this->Translate('Prediction confidence'),
            '%',
            0,
            0.0,
            100.0,
            'chart-line'
        );

        $this->MaintainBooleanVariable('HeatingDemand', $this->Translate('Heating demand'), false);
        $this->MaintainBooleanVariable('HeatingActive', $this->Translate('Heater active'), false);
        $this->MaintainBooleanVariable('BoostActive', $this->Translate('Boost active'), false);
        $this->MaintainBooleanVariable('SecondarySensorAvailable', $this->Translate('Secondary sensor available'), false);
        $this->MaintainBooleanVariable('PVHeatingPossible', $this->Translate('PV heating possible'), false);
        $this->MaintainBooleanVariable('PVHeatingActive', $this->Translate('PV heating active'), false);
        $this->MaintainBooleanVariable('PVHeatingEnable', $this->Translate('PV heating enable'), false);
        $this->MaintainBooleanVariable('PVPermissionFresh', $this->Translate('PV permission fresh'), false);
        $this->MaintainBooleanVariable('FaultActive', $this->Translate('Fault active'), false);
        $this->MaintainBooleanVariable('SensorDiscrepancy', $this->Translate('Sensor discrepancy'), false);
        $this->MaintainBooleanVariable('PowerAvailable', $this->Translate('Power reading available'), false);
        $this->MaintainBooleanVariable('EnergyReadingEstimated', $this->Translate('Energy reading estimated'), false);
        $this->MaintainBooleanVariable('RuntimeLockoutActive', $this->Translate('Runtime safety lockout'), false);
        $this->MaintainBooleanVariable('MaximumRuntimeExceeded', $this->Translate('Maximum runtime exceeded'), false);
        $this->MaintainBooleanVariable('SwitchFeedbackFault', $this->Translate('Switch feedback fault'), false);

        if ($this->RegisterVariableInteger(
            'OperatingMode',
            $this->Translate('Operating mode'),
            $this->GetOperatingModePresentation(),
            30
        )) {
            $this->SetValue('OperatingMode', self::MODE_AUTOMATIC);
        }
        $this->MaintainAction('OperatingMode', true);

        if ($this->RegisterVariableInteger(
            'HeatingState',
            $this->Translate('Heating state'),
            $this->GetHeatingStatePresentation(),
            32
        )) {
            $this->SetValue('HeatingState', self::HEATER_STATE_OFF);
        }

        if ($this->RegisterVariableString(
            'EnergySource',
            $this->Translate('Energy source'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'bolt'],
            53
        )) {
            $this->SetValue('EnergySource', $this->Translate('Unavailable'));
        }

        if ($this->RegisterVariableString(
            'ArchiveStatus',
            $this->Translate('Archive status'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'database'],
            56
        )) {
            $this->SetValue('ArchiveStatus', $this->Translate('Archive logging has not been checked.'));
        }

        if ($this->RegisterVariableInteger(
            'EnergyMeterResetAt',
            $this->Translate('Energy meter reset time'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'rotate'],
            57
        )) {
            $this->SetValue('EnergyMeterResetAt', 0);
        }

        if ($this->RegisterVariableInteger(
            'EnergyLastGapSeconds',
            $this->Translate('Last energy measurement gap'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock'],
            58
        )) {
            $this->SetValue('EnergyLastGapSeconds', 0);
        }

        if ($this->RegisterVariableInteger(
            'SafetyCommand',
            $this->Translate('Safety control'),
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'DISPLAY'      => 2,
                'LAYOUT'       => 1,
                'OPTIONS'      => json_encode([
                    ['Value' => 0, 'Caption' => $this->Translate('No action'), 'IconActive' => true, 'IconValue' => 'circle'],
                    ['Value' => 1, 'Caption' => $this->Translate('Reset safety lockout'), 'IconActive' => true, 'IconValue' => 'rotate'],
                ], JSON_THROW_ON_ERROR),
            ],
            54
        )) {
            $this->SetValue('SafetyCommand', 0);
        }
        $this->MaintainAction('SafetyCommand', true);

        if ($this->RegisterVariableString(
            'ControllerStatus',
            $this->Translate('Controller status'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'info'],
            40
        )) {
            $this->SetValue('ControllerStatus', $this->Translate('Waiting for configuration'));
        }

        if ($this->RegisterVariableString(
            'LastError',
            $this->Translate('Last error'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'triangle-exclamation'],
            55
        )) {
            $this->SetValue('LastError', '');
        }

        if ($this->RegisterVariableInteger(
            'NextComfortTime',
            $this->Translate('Next comfort time'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock'],
            43
        )) {
            $this->SetValue('NextComfortTime', 0);
        }

        if ($this->RegisterVariableInteger(
            'PredictedHeatingStart',
            $this->Translate('Predicted heating start'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock-rotate-left'],
            44
        )) {
            $this->SetValue('PredictedHeatingStart', 0);
        }

        if ($this->RegisterVariableString(
            'LearningStatus',
            $this->Translate('Learning status'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'brain'],
            45
        )) {
            $this->SetValue('LearningStatus', $this->Translate('Learning has not started yet.'));
        }

        $this->MaintainAction('ManualTargetTemperature', true);
        $this->MaintainAction('PVTargetTemperature', true);
        $this->MaintainAction('PVHeatingEnable', true);

        if ($this->RegisterVariableString(
            'ScheduleCommand',
            $this->Translate('Weekly schedule command'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT],
            80
        )) {
            $this->SetValue('ScheduleCommand', '');
        }
        $this->MaintainAction('ScheduleCommand', true);

        if ($this->RegisterVariableInteger(
            'BoostRemainingSeconds',
            $this->Translate('Boost remaining time'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'hourglass-half', 'SUFFIX' => ' s'],
            37
        )) {
            $this->SetValue('BoostRemainingSeconds', 0);
        }

        if ($this->RegisterVariableInteger(
            'BoostCommand',
            $this->Translate('Boost control'),
            $this->GetBoostCommandPresentation(),
            38
        )) {
            $this->SetValue('BoostCommand', 0);
        }
        $this->MaintainAction('BoostCommand', true);

        $maximumTemperature = $this->ReadPropertyFloat('MaximumRoomTemperature');
        $maximumTarget = max(5.0, $maximumTemperature);
        foreach (['ManualTargetTemperature', 'PVTargetTemperature'] as $targetIdent) {
            $currentTarget = (float) $this->GetValue($targetIdent);
            if ($currentTarget < 5.0) {
                $currentTarget = $targetIdent === 'ManualTargetTemperature'
                    ? $this->ReadPropertyFloat('TargetTemperature')
                    : min($maximumTarget, max($this->ReadPropertyFloat('TargetTemperature'), 25.0));
            }
            $normalizedTarget = min($maximumTarget, max(5.0, $currentTarget));
            if ($normalizedTarget !== (float) $this->GetValue($targetIdent)) {
                $this->SetValue($targetIdent, $normalizedTarget);
            }
        }

        $this->SyncVariableReferences();

        $configurationError = $this->GetConfigurationError();
        $configurationValid = $configurationError === '';
        $this->SetTimerInterval(
            'ControlTimer',
            $configurationValid ? $this->ReadPropertyInteger('ControlIntervalSeconds') * 1000 : 0
        );

        if (!$configurationValid) {
            $this->SetValue('LastError', $this->Translate($configurationError));
            $this->SetValue('ControllerStatus', $this->Translate($configurationError));
            $this->SetStatus($configurationError === 'Select a primary room temperature variable.' ? 104 : 202);
            $this->SwitchHeaterOffIfPossible();
            $this->ConfigureArchiveLogging();
            $this->SendVisualizationPayload();
            return;
        }

        $this->RestoreBoostTimer();
        $this->ConfigureArchiveLogging();
        $this->SetStatus(102);
        $this->RunControlCycle();
    }

    public function MessageSink(int $timestamp, int $senderID, int $message, array $data): void
    {
        if ($message === VM_UPDATE && in_array($senderID, $this->GetObservedVariableIDs(), true)) {
            $this->RunControlCycle();
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'OperatingMode':
                if (!is_int($value) || !in_array($value, [self::MODE_AUTOMATIC, self::MODE_MANUAL, self::MODE_OFF], true)) {
                    throw new InvalidArgumentException('OperatingMode expects 0 (automatic), 1 (manual), or 2 (off).');
                }
                if ($value !== (int) $this->GetValue('OperatingMode')) {
                    $this->CancelBoostState();
                }
                $this->SetValue('OperatingMode', $value);
                break;

            case 'ManualTargetTemperature':
                $this->SetValue($ident, $this->ValidateTemperatureActionValue($value, $ident));
                break;

            case 'PVTargetTemperature':
                $this->SetValue($ident, $this->ValidateTemperatureActionValue($value, $ident));
                break;

            case 'PVHeatingEnable':
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('PVHeatingEnable expects a boolean value.');
                }
                $this->SetValue($ident, $value);
                $this->WriteAttributeInteger('PVEnableUpdatedAt', $this->getTime());
                if (!$value) {
                    $this->WriteAttributeBoolean('LastPVHeatingActive', false);
                }
                break;

            case 'BoostCommand':
                if (!is_int($value) || !in_array($value, [0, 1], true)) {
                    throw new InvalidArgumentException('BoostCommand expects 0 (stop) or 1 (start).');
                }
                if ($value === 1) {
                    $this->BeginBoost();
                } else {
                    $this->CancelBoostState();
                }
                $this->SetValue($ident, $value);
                break;

            case 'ScheduleCommand':
                if (!is_string($value)) {
                    throw new InvalidArgumentException('ScheduleCommand expects a JSON command string.');
                }
                $this->ApplyScheduleCommand($value);
                break;

            case 'SafetyCommand':
                if (!is_int($value) || !in_array($value, [0, 1], true)) {
                    throw new InvalidArgumentException('SafetyCommand expects 0 (no action) or 1 (reset lockout).');
                }
                $this->SetValue($ident, 0);
                if ($value === 1) {
                    $this->ResetSafetyLockout();
                    return;
                }
                break;

            default:
                throw new InvalidArgumentException('Invalid Ident: ' . $ident);
        }

        $this->RunControlCycle();
    }

    public function Update(): void
    {
        $this->RunControlCycle();
    }

    public function ResetLearning(): void
    {
        $this->WriteAttributeString('ThermalModel', json_encode(ThermalModel::create(), JSON_THROW_ON_ERROR));
        $this->RunControlCycle();
    }

    public function StartBoost(): void
    {
        $this->BeginBoost();
        $this->RunControlCycle();
    }

    public function StopBoost(): void
    {
        $this->CancelBoostState();
        $this->RunControlCycle();
    }

    public function ResetSafetyLockout(): void
    {
        $switchID = $this->ReadPropertyInteger('HeaterSwitchID');
        if ($switchID > 0 && IPS_VariableExists($switchID) && (bool) GetValue($switchID)) {
            throw new InvalidArgumentException('Turn the heater socket off before resetting the runtime safety lockout.');
        }
        $this->WriteAttributeBoolean('RuntimeLockout', false);
        $this->WriteAttributeInteger('SocketOnSince', 0);
        $this->RunControlCycle();
    }

    public function ExpireBoost(): void
    {
        if ($this->CancelBoostStateIfExpired($this->getTime())) {
            $this->RunControlCycle();
            return;
        }

        $this->RestoreBoostTimer();
    }

    public function GetVisualizationTile(): string
    {
        return $this->RenderVisualization();
    }

    protected function getTime(): int
    {
        return time();
    }

    private function RunControlCycle(): void
    {
        if ($this->GetBuffer('ControlCycleRunning') === '1') {
            return;
        }

        $this->SetBuffer('ControlCycleRunning', '1');

        try {
            $configurationError = $this->GetConfigurationError();
            if ($configurationError !== '') {
                $this->SetValue('HeatingDemand', false);
                $this->SetValue('PVHeatingPossible', false);
                $this->SetValue('PVHeatingActive', false);
                $this->SetValue('FaultActive', true);
                $this->SetValue('LastError', $this->Translate($configurationError));
                $this->SetValue('ControllerStatus', $this->Translate($configurationError));
                $this->SetStatus($configurationError === 'Select a primary room temperature variable.' ? 104 : 202);
                $this->SwitchHeaterOffIfPossible();
                $this->SendVisualizationPayload();
                return;
            }

            $temperature = $this->ReadTemperature(
                $this->ReadPropertyInteger('RoomTemperatureID'),
                $this->ReadPropertyFloat('TemperatureOffset')
            );
            $now = $this->getTime();
            $nowLocal = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $schedule = $this->GetWeeklySchedule();
            $scheduleEnabled = $this->ReadPropertyBoolean('WeeklyScheduleEnabled');
            $scheduledTarget = $scheduleEnabled
                ? WeeklySchedule::targetAt($schedule, $nowLocal)
                : $this->ReadPropertyFloat('TargetTemperature');
            $this->SetValue('ScheduledTargetTemperature', $scheduledTarget);
            $nextComfort = $scheduleEnabled ? WeeklySchedule::nextComfort($schedule, $nowLocal) : null;
            $this->SetValue('NextComfortTime', $nextComfort['timestamp'] ?? 0);
            $timeout = $this->ReadPropertyInteger('SensorTimeoutSeconds');
            $temperatureIsUsable = SafetyController::isTemperatureUsable(
                $temperature['value'] ?? null,
                (int) ($temperature['updated'] ?? 0),
                $now,
                $timeout,
                self::MINIMUM_PLAUSIBLE_TEMPERATURE,
                self::MAXIMUM_PLAUSIBLE_TEMPERATURE
            );

            if ($temperature !== null) {
                $this->SetValue('RoomTemperature', $temperature['value']);
            }

            $secondary = $this->ReadOptionalTemperature(
                $this->ReadPropertyInteger('SecondaryTemperatureID'),
                $this->ReadPropertyFloat('SecondaryTemperatureOffset'),
                $now,
                $timeout
            );
            $this->SetValue('SecondarySensorAvailable', $secondary !== null);
            $this->SetValue('SecondaryTemperature', $secondary ?? 0.0);
            $this->SetValue(
                'TemperatureDifference',
                $secondary === null || $temperature === null ? 0.0 : $secondary - $temperature['value']
            );
            $sensorDiscrepancy = SafetyController::hasSensorDiscrepancy(
                $temperature['value'] ?? null,
                $secondary,
                $this->ReadPropertyFloat('MaximumSensorDifference')
            );
            $this->SetValue('SensorDiscrepancy', $sensorDiscrepancy);

            $switchID = $this->ReadPropertyInteger('HeaterSwitchID');
            $heaterIsOn = (bool) GetValue($switchID);
            $this->SetValue('HeatingActive', $heaterIsOn);

            $powerReading = $this->ReadNumericReading($this->ReadPropertyInteger('PowerVariableID'));
            $power = $powerReading['value'] ?? null;
            $powerIsUsable = $power !== null
                && is_finite($power)
                && $power >= 0.0
                && (int) ($powerReading['updated'] ?? 0) > 0
                && (int) $powerReading['updated'] <= $now
                && ($now - (int) $powerReading['updated']) <= $this->ReadPropertyInteger('PowerTimeoutSeconds');
            $this->SetValue('PowerAvailable', $powerIsUsable);
            $this->SetValue('CurrentHeatingPower', $powerIsUsable ? $power : 0.0);

            $energyID = $this->ReadPropertyInteger('EnergyVariableID');
            $energyReading = $energyID > 0 ? $this->ReadNumericReading($energyID) : null;
            $energy = $energyReading['value'] ?? null;
            $energyIsUsable = $energy !== null && is_finite($energy) && $energy >= 0.0;
            $meterUpdateTime = (int) ($energyReading['updated'] ?? 0);
            $meterStaleWhileHeating = $energyID > 0
                && $energyIsUsable
                && $heaterIsOn
                && $powerIsUsable
                && $power > $this->ReadPropertyFloat('PowerThreshold')
                && ($meterUpdateTime <= 0 || $meterUpdateTime > $now || ($now - $meterUpdateTime) > $this->ReadPropertyInteger('PowerTimeoutSeconds'));
            $meterConfiguredForTracking = $energyID > 0 && $energyIsUsable && !$meterStaleWhileHeating;
            $energyModel = EnergyTracker::observe(
                json_decode($this->ReadAttributeString('EnergyTracker'), true),
                $now,
                $meterConfiguredForTracking ? $energy : null,
                $meterConfiguredForTracking,
                $powerIsUsable ? $power : null,
                $heaterIsOn,
                (bool) $this->GetValue('PVHeatingActive'),
                $this->ReadPropertyFloat('PowerThreshold'),
                date_default_timezone_get(),
                $this->ReadPropertyInteger('PowerTimeoutSeconds')
            );
            $this->WriteAttributeString('EnergyTracker', json_encode($energyModel, JSON_THROW_ON_ERROR));
            $this->WriteEnergyTrackerValues($energyModel, $now);

            if (!$temperatureIsUsable) {
                $this->CancelBoostState();
                $this->SetValue('HeatingDemand', false);
                $this->SetValue('PVHeatingPossible', false);
                $this->SetValue('PVHeatingActive', false);
                $this->SetValue('BoostActive', false);
                $this->SetValue('BoostRemainingSeconds', 0);
                $this->SetValue('FaultActive', true);
                $this->SetValue('SensorDiscrepancy', false);
                $this->SetValue('PredictedHeatingStart', 0);
                $this->SetValue('LastError', $this->Translate('Primary temperature sensor is invalid or stale.'));
                $this->SetValue('ControllerStatus', $this->Translate('Heating stopped because the primary sensor is invalid or stale.'));
                $this->SetValue(
                    'LearningStatus',
                    $this->Translate('Learning paused because the primary temperature sensor is invalid or stale.')
                );
                $this->SetStatus(203);
                $this->RequestHeaterState(false, true);
                $actualAfterCommand = (bool) GetValue($switchID);
                $feedbackFault = $this->UpdateSwitchFeedback(false, $actualAfterCommand, $now);
                $this->SetValue('SwitchFeedbackFault', $feedbackFault);
                $this->UpdateHeatingState($actualAfterCommand, $powerIsUsable ? $power : null, $feedbackFault);
                $this->SendVisualizationPayload();
                return;
            }

            $roomTemperature = $temperature['value'];
            $mode = (int) $this->GetValue('OperatingMode');
            $this->CancelBoostStateIfExpired($now);
            if ($mode === self::MODE_OFF) {
                $this->CancelBoostState();
            }
            $boostActive = $this->ReadAttributeBoolean('BoostActive')
                && $this->ReadAttributeInteger('BoostExpiresAt') > $now
                && $mode !== self::MODE_OFF;
            $this->SetValue('BoostActive', $boostActive);
            $this->SetValue(
                'BoostRemainingSeconds',
                $boostActive ? max(0, $this->ReadAttributeInteger('BoostExpiresAt') - $now) : 0
            );
            $maximumTemperature = $this->ReadPropertyFloat('MaximumRoomTemperature');
            $preheatTarget = $this->UpdateThermalModel(
                $now,
                $roomTemperature,
                $heaterIsOn,
                $powerIsUsable ? $power : null,
                $nextComfort,
                $scheduledTarget,
                $scheduleEnabled,
                $mode
            );
            $automaticTarget = min($preheatTarget ?? $scheduledTarget, $maximumTemperature);
            $manualTarget = min((float) $this->GetValue('ManualTargetTemperature'), $maximumTemperature);
            $regularTarget = $boostActive
                ? min($this->ReadPropertyFloat('BoostTemperature'), $maximumTemperature)
                : match ($mode) {
                    self::MODE_MANUAL => $manualTarget,
                    self::MODE_OFF    => 0.0,
                    default           => $automaticTarget,
                };

            $hysteresis = $this->ReadPropertyFloat('Hysteresis');
            $overTemperature = $roomTemperature >= $maximumTemperature;

            $regularDemand = false;
            if ($mode !== self::MODE_OFF && !$overTemperature) {
                $regularDemand = TemperatureController::hysteresisDemand(
                    $roomTemperature,
                    $regularTarget,
                    $hysteresis,
                    (bool) $this->GetValue('HeatingDemand')
                );
            }
            $thermalModel = ThermalModel::normalize(json_decode($this->ReadAttributeString('ThermalModel'), true));
            $pvResult = PVOptimizer::evaluate([
                'now'                      => $now,
                'mode'                     => $mode,
                'temperature'              => $roomTemperature,
                'regularTarget'            => $regularTarget,
                'pvTarget'                 => (float) $this->GetValue('PVTargetTemperature'),
                'maximumRoomTemperature'   => $maximumTemperature,
                'maximumTemperatureOffset' => $this->ReadPropertyFloat('MaximumPVTemperatureOffset'),
                'hysteresis'               => $hysteresis,
                'scheduleEnabled'          => $scheduleEnabled && $mode === self::MODE_AUTOMATIC,
                'planningHorizonHours'     => $this->ReadPropertyInteger('PVPlanningHorizonHours'),
                'nextComfortTimestamp'     => (int) ($nextComfort['timestamp'] ?? 0),
                'nextComfortTarget'        => (float) ($nextComfort['target'] ?? $scheduledTarget),
                'coolingRateKPerHour'      => (float) $thermalModel['coolingRateKPerHour'],
                'residualHeatK'            => (float) $thermalModel['residualHeatK'],
                'permission'               => (bool) $this->GetValue('PVHeatingEnable'),
                'permissionUpdatedAt'      => $this->ReadAttributeInteger('PVEnableUpdatedAt'),
                'permissionTimeoutSeconds' => $this->ReadPropertyInteger('PVPermissionTimeoutSeconds'),
                'previousActive'           => $this->ReadAttributeBoolean('LastPVHeatingActive'),
            ]);
            $pvPossible = !$overTemperature && $pvResult['possible'];
            $pvDemand = !$regularDemand && $pvPossible && $pvResult['active'];
            $effectiveTarget = $pvDemand
                ? max($regularTarget, (float) $pvResult['targetCeiling'])
                : $regularTarget;
            $residualCutoff = false;
            if (
                ($regularDemand || $pvDemand)
                && $heaterIsOn
                && $powerIsUsable
                && $power > $this->ReadPropertyFloat('PowerThreshold')
            ) {
                $residualCutoff = ThermalModel::shouldStopForResidualHeat(
                    $thermalModel,
                    $roomTemperature,
                    $effectiveTarget,
                    $this->ReadPropertyInteger('MinimumHeatingSamples')
                );
                if ($residualCutoff) {
                    $regularDemand = false;
                    $pvDemand = false;
                    $effectiveTarget = $regularTarget;
                }
            }

            $this->SetValue('HeatingDemand', $regularDemand);
            $this->WriteAttributeBoolean('LastPVHeatingActive', $pvDemand);
            $this->SetValue('PVHeatingPossible', $pvPossible);
            $this->SetValue('PVHeatingActive', $pvDemand);
            $this->SetValue('PVPermissionFresh', (bool) $pvResult['permissionFresh']);
            $this->SetValue('PVTargetCeiling', (float) $pvResult['targetCeiling']);
            $this->SetValue('ExpectedComfortTemperature', (float) $pvResult['expectedTemperatureAtComfort']);
            $this->SetValue('EffectiveTargetTemperature', max(0.0, $effectiveTarget));

            $runtimeExceeded = $this->UpdateSocketRuntime($heaterIsOn, $switchID, $now);
            $runtimeLockout = $this->ReadAttributeBoolean('RuntimeLockout')
                && $this->ReadPropertyBoolean('StopAfterMaximumContinuousOnTime');
            if ($runtimeExceeded && $this->ReadPropertyBoolean('StopAfterMaximumContinuousOnTime')) {
                $runtimeLockout = true;
                $this->WriteAttributeBoolean('RuntimeLockout', true);
            }
            $this->SetValue('MaximumRuntimeExceeded', $runtimeExceeded || $runtimeLockout);
            $this->SetValue('RuntimeLockoutActive', $runtimeLockout);

            $desiredState = TemperatureController::shouldSwitchOn(
                $temperatureIsUsable,
                $mode,
                $roomTemperature,
                $maximumTemperature,
                $runtimeLockout,
                $regularDemand,
                $pvDemand
            );
            $this->SetValue('LastError', '');

            $switchResult = $this->RequestHeaterState(
                $desiredState,
                $overTemperature || $mode === self::MODE_OFF || $runtimeLockout
            );
            $actualAfterCommand = (bool) GetValue($switchID);
            if ($switchResult['waiting']) {
                $this->WriteAttributeInteger('SwitchMismatchSince', 0);
                $feedbackFault = false;
            } else {
                $feedbackFault = $this->UpdateSwitchFeedback($desiredState, $actualAfterCommand, $now);
            }
            $this->SetValue('SwitchFeedbackFault', $feedbackFault);
            $this->UpdateHeatingState($actualAfterCommand, $powerIsUsable ? $power : null, $feedbackFault);
            $faultActive = $sensorDiscrepancy
                || $overTemperature
                || $runtimeExceeded
                || $runtimeLockout
                || $feedbackFault
                || !$switchResult['success'];
            $this->SetValue('FaultActive', $faultActive);
            if (!$switchResult['success']) {
                $this->SetValue('LastError', $this->Translate($switchResult['error']));
                $this->SetValue('ControllerStatus', $this->Translate('The requested switch state has not been confirmed.'));
                $this->SetStatus(201);
            } elseif ($overTemperature) {
                $this->SetValue('LastError', $this->Translate('Maximum room temperature reached.'));
                $this->SetValue('ControllerStatus', $this->Translate('Maximum room temperature reached; heater switched off.'));
                $this->SetStatus(102);
            } elseif ($runtimeLockout) {
                $this->SetValue('LastError', $this->Translate('Maximum continuous ON time reached; heater is locked out.'));
                $this->SetValue('ControllerStatus', $this->Translate('Maximum continuous ON time reached; heater is locked out.'));
                $this->SetStatus(203);
            } elseif ($runtimeExceeded) {
                $this->SetValue('LastError', $this->Translate('Maximum continuous ON time exceeded.'));
                $this->SetValue('ControllerStatus', $this->Translate('Maximum continuous ON time exceeded.'));
                $this->SetStatus(203);
            } elseif ($feedbackFault) {
                $this->SetValue('LastError', $this->Translate('The socket did not confirm the requested state.'));
                $this->SetValue('ControllerStatus', $this->Translate('The socket did not confirm the requested state.'));
                $this->SetStatus(201);
            } elseif ($mode === self::MODE_OFF) {
                $this->SetValue('ControllerStatus', $this->Translate('Heating is disabled.'));
                $this->SetStatus(102);
            } elseif ($switchResult['waiting']) {
                $this->SetValue('ControllerStatus', $this->Translate('Waiting for the minimum switching time.'));
                $this->SetStatus(102);
            } elseif ($switchResult['pending']) {
                $this->SetValue('ControllerStatus', $this->Translate('Waiting for the socket to confirm the requested state.'));
                $this->SetStatus(102);
            } elseif ($regularDemand && $boostActive) {
                $this->SetValue('ControllerStatus', $this->Translate('Heating is requested by the temporary boost.'));
                $this->SetStatus(102);
            } elseif ($residualCutoff) {
                $this->SetValue('ControllerStatus', $this->Translate('Heating stopped early because learned residual heat is sufficient.'));
                $this->SetStatus(102);
            } elseif ($pvDemand) {
                $this->SetValue('ControllerStatus', $this->Translate('Additional PV heating is active.'));
                $this->SetStatus(102);
            } elseif ($pvPossible && !$pvResult['effectivePermission']) {
                $this->SetValue('ControllerStatus', $this->Translate(
                    $pvResult['reason'] === 'permission_stale'
                        ? 'PV permission expired; waiting for a fresh permission.'
                        : 'Thermal PV potential is available; waiting for permission.'
                ));
                $this->SetStatus(102);
            } elseif ($regularDemand && $preheatTarget !== null) {
                $this->SetValue('ControllerStatus', $this->Translate('Adaptive preheating is active.'));
                $this->SetStatus(102);
            } elseif ($regularDemand) {
                $this->SetValue('ControllerStatus', $this->Translate('Heating is requested by the temperature control.'));
                $this->SetStatus(102);
            } elseif ($heaterIsOn && $powerIsUsable && $power <= $this->ReadPropertyFloat('PowerThreshold')) {
                $this->SetValue('ControllerStatus', $this->Translate('Socket is on; the heater is currently drawing little power.'));
                $this->SetStatus(102);
            } elseif (!$powerIsUsable) {
                $this->SetValue('ControllerStatus', $this->Translate('Temperature control is active; power reading is unavailable.'));
                $this->SetStatus(102);
            } elseif (!$energyIsUsable && $energyID > 0) {
                $this->SetValue('ControllerStatus', $this->Translate('Temperature control is active; energy reading is unavailable.'));
                $this->SetStatus(102);
            } else {
                $this->SetValue('ControllerStatus', $this->Translate('Temperature is within the control range.'));
                $this->SetStatus(102);
            }

            if (
                $sensorDiscrepancy
                && !$feedbackFault
                && $switchResult['success']
                && !$runtimeLockout
                && !$overTemperature
            ) {
                $this->SetValue('LastError', $this->Translate('The temperature sensors differ beyond the configured limit.'));
                if (!$regularDemand && !$pvDemand) {
                    $this->SetValue('ControllerStatus', $this->Translate('Temperature sensors disagree; the primary sensor still controls heating.'));
                }
            }

            $this->SendVisualizationPayload();
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $this->SetValue('LastError', $message);
            $this->SetValue('ControllerStatus', $this->Translate('Control error.'));
            $this->SetStatus(201);
            $this->SendDebug('Control cycle failed', $message, 0);
        } finally {
            $this->SetBuffer('ControlCycleRunning', '0');
        }
    }

    private function UpdateThermalModel(
        int $now,
        float $roomTemperature,
        bool $heaterIsOn,
        ?float $power,
        ?array $nextComfort,
        float $scheduledTarget,
        bool $scheduleEnabled,
        int $mode
    ): ?float {
        $storedModel = json_decode($this->ReadAttributeString('ThermalModel'), true);
        $model = ThermalModel::observe(
            ThermalModel::normalize($storedModel),
            [
                'time'        => $now,
                'temperature' => $roomTemperature,
                'socketOn'    => $heaterIsOn,
                'powerW'      => $power,
            ],
            (float) $this->ReadPropertyInteger('NominalHeatingPower'),
            $this->ReadPropertyFloat('PowerThreshold')
        );
        $this->WriteAttributeString('ThermalModel', json_encode($model, JSON_THROW_ON_ERROR));

        $minimumSamples = $this->ReadPropertyInteger('MinimumHeatingSamples');
        $forecastTarget = min(
            (float) ($nextComfort['target'] ?? $scheduledTarget),
            $this->ReadPropertyFloat('MaximumRoomTemperature')
        );
        $forecastModel = $model;
        if (!$heaterIsOn) {
            $residualWindow = $model['residualWindow'];
            if ($residualWindow === null) {
                $forecastModel['residualHeatK'] = 0.0;
            } else {
                $observedResidual = max(0.0, $roomTemperature - $residualWindow['baseTemperature']);
                $forecastModel['residualHeatK'] = max(0.0, $model['residualHeatK'] - $observedResidual);
            }
        }
        $initialHeatingRate = $this->ReadPropertyFloat('InitialHeatingRateKPerHour');
        if ($scheduleEnabled && $nextComfort !== null) {
            $estimatedMinutes = ThermalModel::estimatePreheatMinutes(
                $forecastModel,
                $roomTemperature,
                $forecastTarget,
                $minimumSamples,
                $initialHeatingRate,
                max(0.0, ((int) $nextComfort['timestamp'] - $now) / 60.0),
                $heaterIsOn
            );
        } else {
            $estimatedMinutes = ThermalModel::estimateHeatingMinutes(
                $forecastModel,
                $roomTemperature,
                $forecastTarget,
                $minimumSamples,
                $initialHeatingRate
            );
        }
        $this->SetValue('EstimatedHeatingDuration', $estimatedMinutes ?? 0.0);
        $this->SetValue('EstimatedHeatingRate', $model['heatingRateKPerHour']);
        $this->SetValue('EstimatedCoolingRate', $model['coolingRateKPerHour']);
        $this->SetValue('EstimatedResidualHeating', $model['residualHeatK']);
        $this->SetValue('PredictionConfidence', $model['confidence'] * 100.0);

        if ($model['heatingSamples'] < $minimumSamples) {
            $learningStatus = $this->Translate('Collecting heating data')
                . ': ' . $model['heatingSamples'] . '/' . $minimumSamples . ' '
                . $this->Translate('samples') . ' - '
                . $this->Translate('using the configured initial heating estimate');
        } else {
            $learningStatus = $this->Translate('Thermal model ready.');
        }

        $preheatTarget = null;
        $predictedStart = 0;
        $maximumPreheatMinutes = $this->ReadPropertyInteger('MaximumPreheatMinutes');
        if (
            $this->ReadPropertyBoolean('AdaptivePreheatingEnabled')
            && !$this->ReadAttributeBoolean('BoostActive')
            && $scheduleEnabled
            && $mode === self::MODE_AUTOMATIC
            && $nextComfort !== null
            && $estimatedMinutes !== null
            && (int) $nextComfort['timestamp'] > $now
        ) {
            $remainingMinutes = ((int) $nextComfort['timestamp'] - $now) / 60.0;
            if ($estimatedMinutes > min((float) $maximumPreheatMinutes, $remainingMinutes)) {
                $learningStatus = $this->Translate('The target may not be reached by the comfort time.');
            }
            $leadMinutes = min(
                (float) $maximumPreheatMinutes,
                max(0.0, $estimatedMinutes + $this->ReadPropertyInteger('PreheatSafetyBufferMinutes'))
            );
            $predictedStart = (int) $nextComfort['timestamp'] - (int) round($leadMinutes * 60.0);
            if ($now >= $predictedStart && $now < (int) $nextComfort['timestamp']) {
                $preheatTarget = (float) $nextComfort['target'];
                if ($estimatedMinutes <= min((float) $maximumPreheatMinutes, $remainingMinutes)) {
                    $learningStatus = $this->Translate('Adaptive preheating window is open.');
                }
            } elseif ($predictedStart > $now && $estimatedMinutes <= min((float) $maximumPreheatMinutes, $remainingMinutes)) {
                $learningStatus = $this->Translate('Adaptive preheating is scheduled.');
            }
        }

        $this->SetValue('PredictedHeatingStart', $predictedStart);
        $this->SetValue('LearningStatus', $learningStatus);

        return $preheatTarget;
    }

    private function BeginBoost(): void
    {
        if ((int) $this->GetValue('OperatingMode') === self::MODE_OFF) {
            throw new InvalidArgumentException('Boost cannot be started while the operating mode is Off.');
        }

        $durationSeconds = $this->ReadPropertyInteger('BoostDurationMinutes') * 60;
        $now = $this->getTime();
        $this->WriteAttributeBoolean('BoostActive', true);
        $this->WriteAttributeInteger('BoostStartedAt', $now);
        $this->WriteAttributeInteger('BoostExpiresAt', $now + $durationSeconds);
        $this->SetValue('BoostCommand', 1);
        $this->SetTimerInterval('BoostTimer', $durationSeconds * 1000);
    }

    private function CancelBoostState(): void
    {
        $this->WriteAttributeBoolean('BoostActive', false);
        $this->WriteAttributeInteger('BoostStartedAt', 0);
        $this->WriteAttributeInteger('BoostExpiresAt', 0);
        $this->SetValue('BoostCommand', 0);
        $this->SetTimerInterval('BoostTimer', 0);
    }

    private function CancelBoostStateIfExpired(int $now): bool
    {
        if (!$this->ReadAttributeBoolean('BoostActive')) {
            return false;
        }

        $expiresAt = $this->ReadAttributeInteger('BoostExpiresAt');
        if ($expiresAt > $now) {
            return false;
        }

        $this->CancelBoostState();
        return true;
    }

    private function RestoreBoostTimer(): void
    {
        if (!$this->ReadAttributeBoolean('BoostActive')) {
            $this->SetTimerInterval('BoostTimer', 0);
            return;
        }

        $now = $this->getTime();
        if ($this->CancelBoostStateIfExpired($now)) {
            return;
        }

        $remaining = $this->ReadAttributeInteger('BoostExpiresAt') - $now;
        $this->SetTimerInterval('BoostTimer', max(1000, $remaining * 1000));
    }

    private function ApplyScheduleCommand(string $commandJSON): void
    {
        $command = json_decode($commandJSON, true);
        if (!is_array($command) || !isset($command['op'])) {
            throw new InvalidArgumentException('The weekly schedule command is invalid.');
        }

        if ((string) $command['op'] === 'select') {
            $day = filter_var($command['day'] ?? null, FILTER_VALIDATE_INT);
            if ($day === false || $day < 1 || $day > 7) {
                throw new InvalidArgumentException('The selected schedule day is invalid.');
            }
            $this->WriteAttributeInteger('ScheduleEditorDay', $day);
            return;
        }

        $schedule = WeeklySchedule::applyCommand($this->GetWeeklySchedule(), $command);
        if ($schedule === null) {
            throw new InvalidArgumentException('The weekly schedule change is invalid or conflicts with another time.');
        }

        $this->WriteAttributeString('WeeklySchedule', json_encode($schedule, JSON_THROW_ON_ERROR));
        if ((string) $command['op'] === 'copy') {
            $targetDay = filter_var($command['target'] ?? null, FILTER_VALIDATE_INT);
            if ($targetDay !== false && $targetDay >= 1 && $targetDay <= 7) {
                $this->WriteAttributeInteger('ScheduleEditorDay', $targetDay);
            }
        }
    }

    private function GetWeeklySchedule(): array
    {
        $schedule = json_decode($this->ReadAttributeString('WeeklySchedule'), true);
        $normalized = WeeklySchedule::normalize($schedule);
        if ($normalized !== null) {
            return $normalized;
        }

        $normalized = WeeklySchedule::default();
        $this->WriteAttributeString('WeeklySchedule', json_encode($normalized, JSON_THROW_ON_ERROR));
        return $normalized;
    }

    private function ScheduleCommandString(array $command): string
    {
        return json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function GetScheduleEditorDay(): int
    {
        $day = $this->ReadAttributeInteger('ScheduleEditorDay');
        return $day >= 1 && $day <= 7 ? $day : 1;
    }

    private function RenderScheduleEditor(): string
    {
        $schedule = $this->GetWeeklySchedule();
        $selectedDay = $this->GetScheduleEditorDay();
        $daySelection = '<div class="mvs-actions">';
        foreach (WeeklySchedule::DAY_NAMES as $day => $dayName) {
            $daySelection .= Style::button(
                $this->Translate($dayName),
                'ScheduleCommand',
                $this->ScheduleCommandString(['op' => 'select', 'day' => $day]),
                $day !== $selectedDay
            );
        }
        $daySelection .= '</div>';

        $events = $schedule[$selectedDay - 1];
        $eventRows = '';
        foreach ($events as $index => $event) {
            $label = htmlspecialchars($event['time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' · ' . number_format((float) $event['temperature'], 1, ',', '') . ' °C';
            $eventRows .= '<div class="mvs-schedule-row" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:5px 0;border-bottom:1px solid var(--mvs-border)">'
                . '<strong style="min-width:90px">' . $label . '</strong>';

            foreach ([
                ['label' => '−15 min', 'field' => 'time', 'delta' => -15],
                ['label' => '+15 min', 'field' => 'time', 'delta' => 15],
                ['label' => '−0.5 °C', 'field' => 'temperature', 'delta' => -0.5],
                ['label' => '+0.5 °C', 'field' => 'temperature', 'delta' => 0.5],
            ] as $adjustment) {
                $command = [
                    'op'    => 'adjust',
                    'day'   => $selectedDay,
                    'index' => $index,
                    'field' => $adjustment['field'],
                    'delta' => $adjustment['delta'],
                ];
                if (WeeklySchedule::applyCommand($schedule, $command) !== null) {
                    $eventRows .= Style::button(
                        $adjustment['label'],
                        'ScheduleCommand',
                        $this->ScheduleCommandString($command),
                        true
                    );
                }
            }

            if ($index > 0) {
                $eventRows .= Style::button('Remove', 'ScheduleCommand', $this->ScheduleCommandString([
                    'op'    => 'remove',
                    'day'   => $selectedDay,
                    'index' => $index,
                ]), true);
            }

            $eventRows .= '</div>';
        }

        $copyButtons = '<details style="margin-top:8px"><summary>'
            . htmlspecialchars($this->Translate('Copy this day to…'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</summary><div class="mvs-actions" style="margin-top:8px">';
        foreach (WeeklySchedule::DAY_NAMES as $day => $dayName) {
            if ($day === $selectedDay) {
                continue;
            }
            $copyButtons .= Style::button(
                $this->Translate($dayName),
                'ScheduleCommand',
                $this->ScheduleCommandString(['op' => 'copy', 'source' => $selectedDay, 'target' => $day]),
                true
            );
        }
        $copyButtons .= '</div></details>';

        $content = '<div class="mvs-control-intro">'
            . $this->Translate('Schedule help')
            . '</div>'
            . $daySelection
            . $this->RenderScheduleCharts($schedule, $selectedDay)
            . '<div class="mvs-content">' . $eventRows . '</div>'
            . '<div class="mvs-actions" style="margin-top:8px">'
            . (count($events) < 48 ? Style::button(
                $this->Translate('Add 15-minute point'),
                'ScheduleCommand',
                $this->ScheduleCommandString(['op' => 'add', 'day' => $selectedDay])
            ) : '')
            . Style::button(
                $this->Translate('Reset weekly schedule'),
                'ScheduleCommand',
                $this->ScheduleCommandString(['op' => 'reset']),
                true
            )
            . '</div>'
            . $copyButtons;

        return Style::disclosure(
            $this->Translate('Edit weekly plan'),
            Style::section($this->Translate('Weekly schedule'), $content)
        );
    }

    private function RenderScheduleCharts(array $schedule, int $selectedDay): string
    {
        $width = 720;
        $left = 62;
        $plotWidth = 642;
        $rowHeight = 22;
        $top = 18;
        $height = $top + (7 * $rowHeight) + 26;
        $axis = '';
        foreach ([0, 6, 12, 18, 24] as $hour) {
            $x = $left + ($hour / 24.0 * $plotWidth);
            $axis .= '<line x1="' . number_format($x, 1, '.', '') . '" y1="8" x2="'
                . number_format($x, 1, '.', '') . '" y2="' . ($top + (7 * $rowHeight))
                . '" stroke="currentColor" opacity="0.16"/>';
            $axis .= '<text x="' . number_format($x, 1, '.', '') . '" y="' . ($height - 4)
                . '" text-anchor="middle" fill="currentColor" font-size="10">'
                . sprintf('%02d:00', $hour) . '</text>';
        }

        $weekRows = '';
        foreach ($schedule as $dayIndex => $dayEvents) {
            $row = $top + ($dayIndex * $rowHeight);
            $dayNumber = $dayIndex + 1;
            $dayName = htmlspecialchars($this->Translate(WeeklySchedule::DAY_NAMES[$dayNumber]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $weekRows .= '<text x="0" y="' . ($row + 14) . '" fill="currentColor" font-size="11">' . $dayName . '</text>';
            foreach ($dayEvents as $index => $event) {
                $startMinute = $this->ScheduleTimeToMinutes($event['time']);
                $endMinute = isset($dayEvents[$index + 1])
                    ? $this->ScheduleTimeToMinutes($dayEvents[$index + 1]['time'])
                    : 1440;
                $x = $left + ($startMinute / 1440.0 * $plotWidth);
                $rectWidth = max(0.5, ($endMinute - $startMinute) / 1440.0 * $plotWidth);
                $temperature = (float) $event['temperature'];
                $comfort = $temperature >= $this->ReadPropertyFloat('TargetTemperature');
                $fill = $comfort ? '#d98643' : '#5c92c9';
                $tooltip = htmlspecialchars(
                    sprintf('%s · %s · %.1f °C', $dayName, $event['time'], $temperature),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
                $weekRows .= '<rect x="' . number_format($x, 2, '.', '') . '" y="' . $row . '" width="'
                    . number_format($rectWidth, 2, '.', '') . '" height="16" rx="2" fill="' . $fill
                    . '" opacity="' . ($dayNumber === $selectedDay ? '1' : '0.65') . '"><title>' . $tooltip . '</title></rect>';
            }
        }

        $weekChart = '<svg role="img" aria-label="' . htmlspecialchars($this->Translate('Weekly temperature overview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" viewBox="0 0 ' . $width . ' ' . $height . '" style="width:100%;height:auto;color:var(--content-color);margin:8px 0">'
            . $axis . $weekRows . '</svg>';

        $plotLeft = 42.0;
        $plotTop = 12.0;
        $detailWidth = 650.0;
        $plotHeight = 156.0;
        $detailHeight = 195.0;
        $minimumTemperature = 15.0;
        $maximumTemperature = 30.0;
        $mapY = static function (float $temperature) use ($plotTop, $plotHeight, $minimumTemperature, $maximumTemperature): float
        {
            $clamped = min($maximumTemperature, max($minimumTemperature, $temperature));
            return $plotTop + (($maximumTemperature - $clamped) / ($maximumTemperature - $minimumTemperature) * $plotHeight);
        };

        $selectedEvents = $schedule[$selectedDay - 1];
        $firstTemperature = (float) $selectedEvents[0]['temperature'];
        $path = 'M ' . number_format($plotLeft, 2, '.', '') . ' ' . number_format($mapY($firstTemperature), 2, '.', '');
        foreach ($selectedEvents as $index => $event) {
            $x = $plotLeft + ($this->ScheduleTimeToMinutes($event['time']) / 1440.0 * $detailWidth);
            $y = $mapY((float) $event['temperature']);
            $path .= ' H ' . number_format($x, 2, '.', '') . ' V ' . number_format($y, 2, '.', '');
        }
        $path .= ' H ' . number_format($plotLeft + $detailWidth, 2, '.', '');

        $detailGrid = '';
        foreach ([15, 20, 25, 30] as $temperature) {
            $y = $mapY((float) $temperature);
            $detailGrid .= '<line x1="' . $plotLeft . '" y1="' . number_format($y, 2, '.', '')
                . '" x2="' . ($plotLeft + $detailWidth) . '" y2="' . number_format($y, 2, '.', '')
                . '" stroke="currentColor" opacity="0.16"/><text x="2" y="' . number_format($y + 3, 2, '.', '')
                . '" fill="currentColor" font-size="10">' . $temperature . '°</text>';
        }
        foreach ([0, 6, 12, 18, 24] as $hour) {
            $x = $plotLeft + ($hour / 24.0 * $detailWidth);
            $detailGrid .= '<text x="' . number_format($x, 2, '.', '') . '" y="' . ($detailHeight - 5)
                . '" text-anchor="middle" fill="currentColor" font-size="10">' . sprintf('%02d:00', $hour) . '</text>';
        }

        $dayTitle = htmlspecialchars($this->Translate(WeeklySchedule::DAY_NAMES[$selectedDay]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dayChart = '<div style="font-size:.75rem;color:var(--mvs-muted);margin-top:6px">'
            . htmlspecialchars($this->Translate('Selected day profile'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . $dayTitle . '</div>'
            . '<svg role="img" aria-label="' . htmlspecialchars($this->Translate('Temperature schedule for the selected day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" viewBox="0 0 704 ' . $detailHeight . '" style="width:100%;height:auto;color:var(--content-color)">'
            . $detailGrid . '<path d="' . $path . '" fill="none" stroke="#dd8c50" stroke-width="3" stroke-linejoin="round"/>'
            . '</svg>';

        return $weekChart . $dayChart;
    }

    private function ScheduleTimeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }

    private function RequestHeaterState(bool $desiredState, bool $safetyOverride): array
    {
        $switchID = $this->ReadPropertyInteger('HeaterSwitchID');
        if ($switchID <= 0 || !IPS_VariableExists($switchID)) {
            return ['success' => false, 'waiting' => false, 'error' => 'The heater switch variable is unavailable.'];
        }

        $actualState = (bool) GetValue($switchID);
        $pending = $this->ReadAttributeBoolean('CommandPending');
        $lastCommandedState = $this->ReadAttributeBoolean('LastCommandedSwitchState');
        $lastCommandTime = $this->ReadAttributeInteger('LastSwitchCommandTime');

        if ($actualState === $desiredState) {
            if ($pending) {
                $this->WriteAttributeBoolean('CommandPending', false);
            }
            return ['success' => true, 'waiting' => false, 'pending' => false, 'error' => ''];
        }

        if ($pending && $lastCommandedState === $desiredState && ($this->getTime() - $lastCommandTime) < self::SWITCH_CONFIRMATION_TIMEOUT_SECONDS) {
            return ['success' => true, 'waiting' => false, 'pending' => true, 'error' => ''];
        }

        if (!$safetyOverride) {
            $switchInfo = IPS_GetVariable($switchID);
            $lastChange = (int) ($switchInfo['VariableChanged'] ?? 0);
            $minimumSeconds = $actualState
                ? $this->ReadPropertyInteger('MinimumOnSeconds')
                : $this->ReadPropertyInteger('MinimumOffSeconds');
            if ($lastChange > 0 && ($this->getTime() - $lastChange) < $minimumSeconds) {
                return ['success' => true, 'waiting' => true, 'pending' => false, 'error' => ''];
            }
        }

        try {
            if (!RequestAction($switchID, $desiredState)) {
                return ['success' => false, 'waiting' => false, 'pending' => false, 'error' => 'RequestAction did not confirm the switch command.'];
            }
        } catch (Throwable $exception) {
            return ['success' => false, 'waiting' => false, 'pending' => false, 'error' => 'Switch command failed: ' . $exception->getMessage()];
        }

        $this->WriteAttributeBoolean('CommandPending', true);
        $this->WriteAttributeBoolean('LastCommandedSwitchState', $desiredState);
        $this->WriteAttributeInteger('LastSwitchCommandTime', $this->getTime());

        return ['success' => true, 'waiting' => false, 'pending' => true, 'error' => ''];
    }

    private function SwitchHeaterOffIfPossible(): void
    {
        $switchID = $this->ReadPropertyInteger('HeaterSwitchID');
        if ($switchID <= 0 || !IPS_VariableExists($switchID)) {
            return;
        }

        $variable = IPS_GetVariable($switchID);
        if ((int) $variable['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            return;
        }

        $hasAction = (int) ($variable['VariableAction'] ?? 0) !== 0
            || (int) ($variable['VariableCustomAction'] ?? 0) !== 0;
        if (!$hasAction || !(bool) GetValue($switchID)) {
            return;
        }

        $this->RequestHeaterState(false, true);
    }

    private function ReadTemperature(int $variableID, float $offset): ?array
    {
        $reading = $this->ReadNumericReading($variableID);
        if ($reading === null) {
            return null;
        }

        $reading['value'] += $offset;
        return $reading;
    }

    private function ReadOptionalTemperature(
        int $variableID,
        float $offset,
        int $now,
        int $timeout
    ): ?float {
        if ($variableID <= 0) {
            return null;
        }

        $reading = $this->ReadTemperature($variableID, $offset);
        if (
            $reading === null
            || $reading['updated'] <= 0
            || $reading['updated'] > $now
            || ($now - $reading['updated']) > $timeout
            || $reading['value'] < self::MINIMUM_PLAUSIBLE_TEMPERATURE
            || $reading['value'] > self::MAXIMUM_PLAUSIBLE_TEMPERATURE
        ) {
            return null;
        }

        return $reading['value'];
    }

    private function ReadNumericReading(int $variableID): ?array
    {
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }

        $variable = IPS_GetVariable($variableID);
        if (!in_array((int) $variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
            return null;
        }

        $value = GetValue($variableID);
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return null;
        }

        return [
            'value'   => (float) $value,
            'updated' => (int) ($variable['VariableUpdated'] ?? 0),
        ];
    }

    private function ReadOptionalNumericVariable(int $variableID): ?float
    {
        $reading = $this->ReadNumericReading($variableID);
        if ($reading === null || !is_finite($reading['value'])) {
            return null;
        }

        return $reading['value'];
    }

    private function WriteEnergyTrackerValues(array $model, int $timestamp): void
    {
        $values = EnergyTracker::values($model, $timestamp, date_default_timezone_get());
        foreach ([
            'EnergyTotal',
            'EnergyToday',
            'EnergyYesterday',
            'EnergyWeek',
            'EnergyMonth',
            'RegularHeatingEnergy',
            'EstimatedPVHeatingEnergy',
            'ElectricalHeatingDurationHours',
            'SocketOnDurationHours',
        ] as $ident) {
            $this->SetValue($ident, (float) $values[$ident]);
        }

        $this->SetValue('EnergyReadingEstimated', (bool) $values['EnergyReadingEstimated']);
        $this->SetValue('EnergyMeterResetAt', (int) $values['EnergyMeterResetAt']);
        $this->SetValue('EnergyLastGapSeconds', (int) $values['EnergyLastGapSeconds']);
        $this->SetValue('EnergySource', $this->Translate(match ($values['EnergySource']) {
            'meter'     => 'Cumulative meter',
            'estimated' => 'Estimated from power',
            default     => 'Unavailable',
        }));
    }

    private function UpdateSocketRuntime(bool $socketOn, int $switchID, int $now): bool
    {
        if (!$socketOn) {
            $this->WriteAttributeInteger('SocketOnSince', 0);
            return false;
        }

        $socketOnSince = $this->ReadAttributeInteger('SocketOnSince');
        if ($socketOnSince <= 0) {
            $switchInfo = IPS_GetVariable($switchID);
            $socketOnSince = (int) ($switchInfo['VariableChanged'] ?? 0);
            if ($socketOnSince <= 0 || $socketOnSince > $now) {
                $socketOnSince = $now;
            }
            $this->WriteAttributeInteger('SocketOnSince', $socketOnSince);
        }

        $maximumOnSeconds = $this->ReadPropertyInteger('MaximumContinuousOnMinutes') * 60;
        return SafetyController::hasExceededRuntime($socketOn, $socketOnSince, $now, $maximumOnSeconds);
    }

    private function UpdateSwitchFeedback(bool $desiredState, bool $actualState, int $now): bool
    {
        if ($desiredState === $actualState) {
            $this->WriteAttributeInteger('SwitchMismatchSince', 0);
            return false;
        }

        $mismatchSince = $this->ReadAttributeInteger('SwitchMismatchSince');
        if ($mismatchSince <= 0 || $mismatchSince > $now) {
            $mismatchSince = $now;
            $this->WriteAttributeInteger('SwitchMismatchSince', $mismatchSince);
        }

        return SafetyController::hasSwitchFeedbackTimeout(
            $desiredState,
            $actualState,
            $mismatchSince,
            $now,
            self::SWITCH_CONFIRMATION_TIMEOUT_SECONDS
        );
    }

    private function UpdateHeatingState(bool $socketOn, ?float $power, bool $feedbackFault): void
    {
        $state = match (true) {
            $feedbackFault                                                         => self::HEATER_STATE_FEEDBACK_FAULT,
            !$socketOn                                                             => self::HEATER_STATE_OFF,
            $power !== null && $power > $this->ReadPropertyFloat('PowerThreshold') => self::HEATER_STATE_ELECTRICALLY_ACTIVE,
            default                                                                => self::HEATER_STATE_SOCKET_ON,
        };
        $this->SetValue('HeatingState', $state);
    }

    private function ConfigureArchiveLogging(): void
    {
        if (!$this->ReadPropertyBoolean('ArchiveLoggingEnabled')) {
            $this->SetValue('ArchiveStatus', $this->Translate('Module archive logging is disabled.'));
            return;
        }

        if (!function_exists('IPS_GetInstanceListByModuleID') || !function_exists('AC_GetLoggingStatus')) {
            $this->SetValue('ArchiveStatus', $this->Translate('Archive Control functions are unavailable in this environment.'));
            return;
        }

        $archiveInstances = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if ($archiveInstances === []) {
            $this->SetValue('ArchiveStatus', $this->Translate('No Archive Control instance is available.'));
            return;
        }

        $archiveID = (int) $archiveInstances[0];
        $loggedIdents = [
            'RoomTemperature'            => 0,
            'SecondaryTemperature'       => 0,
            'TemperatureDifference'      => 0,
            'ScheduledTargetTemperature' => 0,
            'EffectiveTargetTemperature' => 0,
            'HeatingDemand'              => 0,
            'HeatingActive'              => 0,
            'CurrentHeatingPower'        => 0,
            'EnergyTotal'                => 1,
            'PVHeatingActive'            => 0,
            'PredictedHeatingStart'      => 0,
            'EstimatedHeatingDuration'   => 0,
            'PredictionConfidence'       => 0,
            'FaultActive'                => 0,
        ];
        $previousIDs = json_decode($this->ReadAttributeString('ArchivedVariableIDs'), true);
        $previousIDs = is_array($previousIDs) ? array_map('intval', $previousIDs) : [];
        $configuredIDs = [];

        try {
            foreach ($loggedIdents as $ident => $aggregationType) {
                $variableID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($variableID === false || $variableID <= 0) {
                    continue;
                }
                $configuredIDs[] = (int) $variableID;
                if (in_array((int) $variableID, $previousIDs, true)) {
                    continue;
                }

                if (!AC_GetLoggingStatus($archiveID, $variableID)) {
                    AC_SetLoggingStatus($archiveID, $variableID, true);
                }
                $currentAggregation = AC_GetAggregationType($archiveID, $variableID);
                if (is_array($currentAggregation)) {
                    $currentAggregation = $currentAggregation['AggregationType'] ?? $currentAggregation['Aggregation'] ?? 0;
                }
                if ((int) $currentAggregation !== $aggregationType) {
                    AC_SetAggregationType($archiveID, $variableID, $aggregationType);
                    AC_ReAggregateVariable($archiveID, $variableID);
                }
            }
            $this->WriteAttributeString('ArchivedVariableIDs', json_encode($configuredIDs, JSON_THROW_ON_ERROR));
            $this->SetValue('ArchiveStatus', $this->Translate('Module variables are logged in Archive Control.'));
        } catch (Throwable $exception) {
            $this->SetValue('ArchiveStatus', $this->Translate('Archive configuration failed:') . ' ' . $exception->getMessage());
            $this->SendDebug('Archive logging setup failed', $exception->getMessage(), 0);
        }
    }

    private function ValidateTemperatureActionValue(mixed $value, string $ident): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($ident . ' expects a numeric temperature.');
        }

        $temperature = (float) $value;
        if (!is_finite($temperature) || $temperature < 5.0 || $temperature > 35.0) {
            throw new InvalidArgumentException($ident . ' must be between 5 and 35 °C.');
        }
        if ($temperature > $this->ReadPropertyFloat('MaximumRoomTemperature')) {
            throw new InvalidArgumentException($ident . ' must not exceed the configured maximum room temperature.');
        }

        return $temperature;
    }

    private function GetConfigurationError(): string
    {
        $roomTemperatureID = $this->ReadPropertyInteger('RoomTemperatureID');
        $switchID = $this->ReadPropertyInteger('HeaterSwitchID');
        $powerID = $this->ReadPropertyInteger('PowerVariableID');
        $secondaryID = $this->ReadPropertyInteger('SecondaryTemperatureID');
        $energyID = $this->ReadPropertyInteger('EnergyVariableID');

        if ($roomTemperatureID <= 0) {
            return 'Select a primary room temperature variable.';
        }
        if ($switchID <= 0) {
            return 'Select a heater switch variable.';
        }
        if ($powerID <= 0) {
            return 'Select a power variable in watts.';
        }

        $ids = [$roomTemperatureID, $switchID, $powerID];
        if ($secondaryID > 0) {
            $ids[] = $secondaryID;
        }
        if ($energyID > 0) {
            $ids[] = $energyID;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            return 'Each hardware input must use a different variable.';
        }

        foreach ($ids as $id) {
            if (!IPS_VariableExists($id)) {
                return 'A selected hardware variable no longer exists.';
            }
        }

        if (!$this->IsVariableType($roomTemperatureID, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT])) {
            return 'The primary room temperature variable must be numeric.';
        }
        if ($secondaryID > 0 && !$this->IsVariableType($secondaryID, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT])) {
            return 'The secondary temperature variable must be numeric.';
        }
        if (!$this->IsVariableType($switchID, [VARIABLETYPE_BOOLEAN])) {
            return 'The heater switch variable must be boolean.';
        }
        if (!$this->IsVariableType($powerID, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT])) {
            return 'The power variable must be numeric and measured in watts.';
        }
        if ($energyID > 0 && !$this->IsVariableType($energyID, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT])) {
            return 'The energy variable must be numeric and measured in kilowatt-hours.';
        }

        $switchInfo = IPS_GetVariable($switchID);
        $hasAction = (int) ($switchInfo['VariableAction'] ?? 0) !== 0
            || (int) ($switchInfo['VariableCustomAction'] ?? 0) !== 0;
        if (!$hasAction) {
            return 'The heater switch variable must provide an action.';
        }

        if ($this->ReadPropertyInteger('NominalHeatingPower') <= 0) {
            return 'Nominal heating power must be greater than zero.';
        }
        if ($this->ReadPropertyInteger('SensorTimeoutSeconds') < 30) {
            return 'Sensor timeout must be at least 30 seconds.';
        }
        if (
            $this->ReadPropertyInteger('PowerTimeoutSeconds') < 30
            || $this->ReadPropertyInteger('PowerTimeoutSeconds') > 86400
        ) {
            return 'Power reading timeout must be between 30 and 86400 seconds.';
        }
        if ($this->ReadPropertyInteger('ControlIntervalSeconds') < 5) {
            return 'Control interval must be at least 5 seconds.';
        }
        if (
            $this->ReadPropertyInteger('MaximumPreheatMinutes') < 0
            || $this->ReadPropertyInteger('MaximumPreheatMinutes') > 360
        ) {
            return 'Maximum preheating time must be between 0 and 360 minutes.';
        }
        if (
            $this->ReadPropertyInteger('PreheatSafetyBufferMinutes') < 0
            || $this->ReadPropertyInteger('PreheatSafetyBufferMinutes') > 120
        ) {
            return 'Preheating safety buffer must be between 0 and 120 minutes.';
        }
        if (
            $this->ReadPropertyInteger('MinimumHeatingSamples') < 1
            || $this->ReadPropertyInteger('MinimumHeatingSamples') > 100
        ) {
            return 'Minimum heating samples must be between 1 and 100.';
        }
        if (
            $this->ReadPropertyFloat('InitialHeatingRateKPerHour') < 0.05
            || $this->ReadPropertyFloat('InitialHeatingRateKPerHour') > 3.0
        ) {
            return 'Initial heating rate must be between 0.05 and 3.0 K/h.';
        }
        if (
            $this->ReadPropertyFloat('BoostTemperature') < 5.0
            || $this->ReadPropertyFloat('BoostTemperature') > 35.0
            || $this->ReadPropertyFloat('BoostTemperature') > $this->ReadPropertyFloat('MaximumRoomTemperature')
        ) {
            return 'Boost temperature must be between 5 and 35 °C and no higher than the maximum room temperature.';
        }
        if (
            $this->ReadPropertyInteger('BoostDurationMinutes') < 1
            || $this->ReadPropertyInteger('BoostDurationMinutes') > 240
        ) {
            return 'Boost duration must be between 1 and 240 minutes.';
        }
        if (
            abs($this->ReadPropertyFloat('TemperatureOffset')) > 10.0
            || abs($this->ReadPropertyFloat('SecondaryTemperatureOffset')) > 10.0
        ) {
            return 'Sensor offsets must be between -10 and 10 °C.';
        }
        if ($this->ReadPropertyFloat('PowerThreshold') < 0.0 || $this->ReadPropertyFloat('PowerThreshold') > 1000.0) {
            return 'Power threshold must be between 0 and 1000 W.';
        }
        if (
            $this->ReadPropertyFloat('MaximumPVTemperatureOffset') < 0.0
            || $this->ReadPropertyFloat('MaximumPVTemperatureOffset') > 5.0
        ) {
            return 'Maximum PV temperature offset must be between 0 and 5 K.';
        }
        if (
            $this->ReadPropertyInteger('PVPlanningHorizonHours') < 1
            || $this->ReadPropertyInteger('PVPlanningHorizonHours') > 168
        ) {
            return 'PV planning horizon must be between 1 and 168 hours.';
        }
        if (
            $this->ReadPropertyInteger('PVPermissionTimeoutSeconds') < 0
            || $this->ReadPropertyInteger('PVPermissionTimeoutSeconds') > 86400
        ) {
            return 'PV permission timeout must be between 0 and 86400 seconds.';
        }
        if (
            $this->ReadPropertyFloat('MaximumSensorDifference') < 0.5
            || $this->ReadPropertyFloat('MaximumSensorDifference') > 20.0
        ) {
            return 'Maximum sensor difference must be between 0.5 and 20 K.';
        }
        if (
            $this->ReadPropertyInteger('MaximumContinuousOnMinutes') < 0
            || $this->ReadPropertyInteger('MaximumContinuousOnMinutes') > 1440
        ) {
            return 'Maximum continuous ON time must be between 0 and 1440 minutes.';
        }
        if (
            $this->ReadPropertyInteger('MinimumOnSeconds') < 0
            || $this->ReadPropertyInteger('MinimumOnSeconds') > 3600
            || $this->ReadPropertyInteger('MinimumOffSeconds') < 0
            || $this->ReadPropertyInteger('MinimumOffSeconds') > 3600
        ) {
            return 'Minimum switching times must be between 0 and 3600 seconds.';
        }

        $targetTemperature = $this->ReadPropertyFloat('TargetTemperature');
        $hysteresis = $this->ReadPropertyFloat('Hysteresis');
        $maximumTemperature = $this->ReadPropertyFloat('MaximumRoomTemperature');
        if ($targetTemperature < 5.0 || $targetTemperature > 35.0) {
            return 'Target temperature must be between 5 and 35 °C.';
        }
        if ($hysteresis < 0.1 || $hysteresis > 3.0) {
            return 'Hysteresis must be between 0.1 and 3.0 K.';
        }
        if ($maximumTemperature <= $targetTemperature || $maximumTemperature > 40.0) {
            return 'Maximum room temperature must be above the target temperature and no more than 40 °C.';
        }

        return '';
    }

    private function IsVariableType(int $variableID, array $allowedTypes): bool
    {
        if (!IPS_VariableExists($variableID)) {
            return false;
        }

        return in_array((int) IPS_GetVariable($variableID)['VariableType'], $allowedTypes, true);
    }

    private function SyncVariableReferences(): void
    {
        $selectedIDs = array_values(array_unique(array_filter([
            $this->ReadPropertyInteger('RoomTemperatureID'),
            $this->ReadPropertyInteger('SecondaryTemperatureID'),
            $this->ReadPropertyInteger('HeaterSwitchID'),
            $this->ReadPropertyInteger('PowerVariableID'),
            $this->ReadPropertyInteger('EnergyVariableID'),
        ], static fn (int $id): bool => $id > 0)));

        $oldIDs = json_decode($this->ReadAttributeString('ObservedVariableIDs'), true);
        if (!is_array($oldIDs)) {
            $oldIDs = [];
        }

        foreach ($oldIDs as $oldID) {
            $oldID = (int) $oldID;
            if (!in_array($oldID, $selectedIDs, true)) {
                $this->UnregisterMessage($oldID, VM_UPDATE);
                $this->UnregisterReference($oldID);
            }
        }

        foreach ($selectedIDs as $id) {
            if (!in_array($id, $oldIDs, true)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $this->WriteAttributeString('ObservedVariableIDs', json_encode($selectedIDs, JSON_THROW_ON_ERROR));
    }

    private function GetObservedVariableIDs(): array
    {
        $ids = json_decode($this->ReadAttributeString('ObservedVariableIDs'), true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    private function MaintainFloatVariable(
        string $ident,
        string $name,
        string $suffix,
        int $digits,
        float $minimum,
        float $maximum,
        string $icon
    ): void {
        if ($ident === 'ManualTargetTemperature' || $ident === 'PVTargetTemperature') {
            $presentation = [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT,
                'SUFFIX'       => $suffix,
            ];
        } else {
            $presentation = [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => $icon,
                'SUFFIX'       => $suffix,
                'DIGITS'       => $digits,
                'MIN'          => $minimum,
                'MAX'          => $maximum,
            ];
        }

        if ($this->RegisterVariableFloat($ident, $name, $presentation, $this->GetVariablePosition($ident))) {
            $initialValue = match ($ident) {
                'ManualTargetTemperature' => $this->ReadPropertyFloat('TargetTemperature'),
                'PVTargetTemperature'     => min(
                    $this->ReadPropertyFloat('MaximumRoomTemperature'),
                    max($this->ReadPropertyFloat('TargetTemperature'), 25.0)
                ),
                default => 0.0,
            };
            $this->SetValue($ident, $initialValue);
        }

        if ($ident === 'ManualTargetTemperature') {
            IPS_SetName($this->GetIDForIdent($ident), $name);
        }
    }

    private function MaintainBooleanVariable(string $ident, string $name, bool $action): void
    {
        $presentation = $ident === 'PVHeatingEnable'
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH]
            : $this->GetBooleanValuePresentation();

        if ($this->RegisterVariableBoolean($ident, $name, $presentation, $this->GetVariablePosition($ident))) {
            $this->SetValue($ident, false);
        }

        $this->MaintainAction($ident, $action);
    }

    private function GetVariablePosition(string $ident): int
    {
        return match ($ident) {
            'RoomTemperature'                => 10,
            'SecondaryTemperature'           => 11,
            'TemperatureDifference'          => 12,
            'ScheduledTargetTemperature'     => 18,
            'EffectiveTargetTemperature'     => 20,
            'ManualTargetTemperature'        => 21,
            'PVTargetTemperature'            => 22,
            'PVTargetCeiling'                => 23,
            'ExpectedComfortTemperature'     => 24,
            'HeatingDemand'                  => 30,
            'HeatingActive'                  => 31,
            'HeatingState'                   => 32,
            'SecondarySensorAvailable'       => 33,
            'SensorDiscrepancy'              => 34,
            'PVHeatingPossible'              => 35,
            'PVHeatingEnable'                => 36,
            'PVHeatingActive'                => 37,
            'PVPermissionFresh'              => 38,
            'BoostActive'                    => 39,
            'BoostRemainingSeconds'          => 40,
            'BoostCommand'                   => 41,
            'CurrentHeatingPower'            => 42,
            'PowerAvailable'                 => 43,
            'EnergyTotal'                    => 44,
            'EnergyToday'                    => 45,
            'EnergyYesterday'                => 46,
            'EnergyWeek'                     => 47,
            'EnergyMonth'                    => 48,
            'RegularHeatingEnergy'           => 49,
            'EstimatedPVHeatingEnergy'       => 50,
            'ElectricalHeatingDurationHours' => 51,
            'SocketOnDurationHours'          => 52,
            'EnergyReadingEstimated'         => 53,
            'NextComfortTime'                => 54,
            'PredictedHeatingStart'          => 55,
            'EstimatedHeatingDuration'       => 56,
            'EstimatedHeatingRate'           => 57,
            'EstimatedCoolingRate'           => 58,
            'EstimatedResidualHeating'       => 59,
            'PredictionConfidence'           => 60,
            'LearningStatus'                 => 61,
            'FaultActive'                    => 62,
            'RuntimeLockoutActive'           => 63,
            'MaximumRuntimeExceeded'         => 64,
            'SwitchFeedbackFault'            => 65,
            default                          => 100,
        };
    }

    private function GetBooleanValuePresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                ['Value' => true, 'Caption' => $this->Translate('Yes'), 'IconActive' => true, 'IconValue' => 'check'],
                ['Value' => false, 'Caption' => $this->Translate('No'), 'IconActive' => true, 'IconValue' => 'xmark'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function GetOperatingModePresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'DISPLAY'      => 2,
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode([
                ['Value' => self::MODE_AUTOMATIC, 'Caption' => $this->Translate('Automatic'), 'IconActive' => true, 'IconValue' => 'sun'],
                ['Value' => self::MODE_MANUAL, 'Caption' => $this->Translate('Manual'), 'IconActive' => true, 'IconValue' => 'hand'],
                ['Value' => self::MODE_OFF, 'Caption' => $this->Translate('Off'), 'IconActive' => true, 'IconValue' => 'power-off'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function GetBoostCommandPresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'DISPLAY'      => 2,
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode([
                ['Value' => 1, 'Caption' => $this->Translate('Start boost'), 'IconActive' => true, 'IconValue' => 'bolt'],
                ['Value' => 0, 'Caption' => $this->Translate('Stop boost'), 'IconActive' => true, 'IconValue' => 'stop'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function GetHeatingStatePresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'DISPLAY'      => 2,
            'LAYOUT'       => 1,
            'OPTIONS'      => json_encode([
                ['Value' => self::HEATER_STATE_OFF, 'Caption' => $this->Translate('Off'), 'IconActive' => true, 'IconValue' => 'power-off'],
                ['Value' => self::HEATER_STATE_SOCKET_ON, 'Caption' => $this->Translate('Socket on without load'), 'IconActive' => true, 'IconValue' => 'plug'],
                ['Value' => self::HEATER_STATE_ELECTRICALLY_ACTIVE, 'Caption' => $this->Translate('Heating electrically'), 'IconActive' => true, 'IconValue' => 'bolt'],
                ['Value' => self::HEATER_STATE_FEEDBACK_FAULT, 'Caption' => $this->Translate('Switch feedback fault'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function GetVisualizationPayload(): array
    {
        $isOn = (bool) $this->GetValue('HeatingActive');
        $lastError = trim((string) $this->GetValue('LastError'));
        $sensorDiscrepancyOnly = $lastError === $this->Translate('The temperature sensors differ beyond the configured limit.');
        $state = $lastError !== '' && !$sensorDiscrepancyOnly
            ? 'error'
            : ((bool) $this->GetValue('FaultActive') ? 'warning' : ($isOn ? 'active' : 'inactive'));
        $stateLabel = $this->Translate(match ($state) {
            'active'  => 'Heating',
            'error'   => 'Needs attention',
            'warning' => 'Check status',
            default   => 'Idle',
        });

        return [
            'state'      => $state,
            'stateLabel' => $stateLabel,
            'content'    => $this->RenderVisualizationContent($stateLabel),
            'footer'     => (string) $this->GetValue('ControllerStatus'),
        ];
    }

    private function SendVisualizationPayload(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->GetVisualizationPayload(), JSON_THROW_ON_ERROR));
    }

    private function RenderVisualization(): string
    {
        $payload = $this->GetVisualizationPayload();

        return Style::renderTile([
            'title'      => $this->Translate('Smart Heating Control'),
            'icon'       => 'fa-light fa-temperature-half',
            'state'      => $payload['state'],
            'stateLabel' => $payload['stateLabel'],
            'showHeader' => false,
            'content'    => $payload['content'],
            'footer'     => $payload['footer'],
        ]);
    }

    private function RenderVisualizationContent(string $stateLabel): string
    {
        $mode = (int) $this->GetValue('OperatingMode');
        $isOn = (bool) $this->GetValue('HeatingActive');
        $lastError = trim((string) $this->GetValue('LastError'));
        $sensorDiscrepancyOnly = $lastError === $this->Translate('The temperature sensors differ beyond the configured limit.');
        $displayError = $sensorDiscrepancyOnly ? '' : $lastError;
        $statusMessage = $displayError !== ''
            ? $displayError
            : trim((string) $this->GetValue('ControllerStatus'));
        $notice = ((bool) $this->GetValue('FaultActive') || $displayError !== '') && $statusMessage !== ''
            ? Style::alert($statusMessage, $displayError !== '' ? 'error' : 'warning')
            : '';

        return $notice
            . $this->RenderOverview($mode, $isOn, $stateLabel)
            . $this->RenderControls($mode)
            . $this->RenderScheduleEditor()
            . Style::disclosure(
                $this->Translate('History and energy'),
                Style::section($this->Translate('Historical trends'), $this->RenderHistoricalChart())
                    . $this->RenderEnergyDetails()
            )
            . Style::disclosure(
                $this->Translate('More status and diagnostics'),
                $this->RenderTechnicalDetails()
            );
    }

    private function RenderOverview(int $mode, bool $isOn, string $stateLabel): string
    {
        $roomTemperature = (float) $this->GetValue('RoomTemperature');
        $scheduledTargetTemperature = (float) $this->GetValue('ScheduledTargetTemperature');
        $targetTemperature = (float) $this->GetValue('EffectiveTargetTemperature');
        $manualTarget = (float) $this->GetValue('ManualTargetTemperature');
        $degree = chr(194) . chr(176) . 'C';
        $heatingState = match ((int) $this->GetValue('HeatingState')) {
            self::HEATER_STATE_SOCKET_ON => $this->Translate('Socket on without load'),
            self::HEATER_STATE_ELECTRICALLY_ACTIVE => $this->Translate('Heating electrically'),
            self::HEATER_STATE_FEEDBACK_FAULT => $this->Translate('Switch feedback fault'),
            default => $this->Translate('Off'),
        };
        $targetValue = $mode === self::MODE_OFF
            ? $this->Translate('Off')
            : number_format($targetTemperature, 1, ',', '') . ' ' . $degree;
        $targetDetail = match ($mode) {
            self::MODE_MANUAL => $this->Translate('Manual desired temperature') . ': '
                . number_format($manualTarget, 1, ',', '') . ' ' . $degree,
            self::MODE_OFF => $this->Translate('Heating is disabled by operating mode.'),
            default => $this->Translate('Scheduled target') . ': '
                . number_format($scheduledTargetTemperature, 1, ',', '') . ' ' . $degree,
        };
        $modeLabel = $this->Translate(match ($mode) {
            self::MODE_MANUAL => 'Manual',
            self::MODE_OFF => 'Off',
            default => 'Automatic',
        });

        $content = '<div class="mvs-dashboard-grid">'
            . Style::stat(
                $this->Translate('Room temperature'),
                number_format($roomTemperature, 1, ',', '') . ' ' . $degree,
                $this->Translate('Primary sensor')
            )
            . Style::stat($this->Translate('Current target'), $targetValue, $targetDetail)
            . Style::stat($this->Translate('Operating mode'), $modeLabel)
            . Style::stat(
                $this->Translate('Heating state'),
                $heatingState,
                $this->Translate($isOn ? 'On' : 'Off')
            )
            . '</div>';

        return Style::sectionWithStatus($this->Translate('Heating overview'), $content, $stateLabel);
    }

    private function RenderControls(int $mode): string
    {
        $manualTarget = (float) $this->GetValue('ManualTargetTemperature');
        $pvTarget = (float) $this->GetValue('PVTargetTemperature');
        $pvEnable = (bool) $this->GetValue('PVHeatingEnable');
        $boostActive = (bool) $this->GetValue('BoostActive');
        $boostRemainingSeconds = (int) $this->GetValue('BoostRemainingSeconds');
        $maximumTarget = min(35.0, $this->ReadPropertyFloat('MaximumRoomTemperature'));

        $modeButtons = '<div class="mvs-actions">'
            . Style::button($this->Translate('Automatic'), 'OperatingMode', self::MODE_AUTOMATIC, $mode !== self::MODE_AUTOMATIC)
            . Style::button($this->Translate('Manual'), 'OperatingMode', self::MODE_MANUAL, $mode !== self::MODE_MANUAL)
            . Style::button($this->Translate('Off'), 'OperatingMode', self::MODE_OFF, $mode !== self::MODE_OFF)
            . '</div>';

        $manualInput = Style::numberInputForm(
            $this->Translate('Manual desired temperature'),
            'ManualTargetTemperature',
            $manualTarget,
            5.0,
            $maximumTarget,
            0.1,
            $this->Translate('Manual target help'),
            'shc-manual-target-' . $this->InstanceID,
            $this->Translate('Apply')
        );

        $boostControls = '<div class="mvs-dashboard-grid">'
            . Style::stat(
                $this->Translate('Boost active'),
                $this->Translate($boostActive ? 'Yes' : 'No'),
                $boostActive
                    ? $this->Translate('Boost remaining time') . ': '
                        . number_format((int) ceil($boostRemainingSeconds / 60)) . ' min'
                    : ''
            )
            . Style::stat(
                $this->Translate('PV heating possible'),
                $this->Translate((bool) $this->GetValue('PVHeatingPossible') ? 'Yes' : 'No'),
                $this->Translate('PV permission fresh') . ': '
                    . $this->Translate((bool) $this->GetValue('PVPermissionFresh') ? 'Yes' : 'No')
            )
            . '</div><div class="mvs-actions">'
            . Style::button(
                $this->Translate($boostActive ? 'Stop boost' : 'Start boost'),
                'BoostCommand',
                $boostActive ? 0 : 1,
                true
            )
            . Style::button(
                $this->Translate($pvEnable ? 'Disable PV permission' : 'Enable PV permission'),
                'PVHeatingEnable',
                !$pvEnable,
                true
            )
            . '</div>';

        $pvInput = Style::numberInputForm(
            $this->Translate('PV target temperature'),
            'PVTargetTemperature',
            $pvTarget,
            5.0,
            $maximumTarget,
            0.1,
            $this->Translate('PV target help'),
            'shc-pv-target-' . $this->InstanceID,
            $this->Translate('Apply')
        );

        $additionalControls = Style::disclosure(
            $this->Translate('Boost and PV controls'),
            $boostControls . $pvInput
        );

        $content = '<div class="mvs-control-intro">' . $this->Translate('Mode help') . '</div>'
            . $modeButtons
            . $manualInput
            . $additionalControls;

        return Style::section($this->Translate('Controls'), $content);
    }

    private function RenderEnergyDetails(): string
    {
        $powerLabel = (bool) $this->GetValue('PowerAvailable')
            ? number_format((float) $this->GetValue('CurrentHeatingPower'), 0, ',', '') . ' W'
            : $this->Translate('No current power reading');

        $content = '<div class="mvs-dashboard-grid">'
            . Style::stat($this->Translate('Power'), $powerLabel)
            . Style::stat(
                $this->Translate('Energy today'),
                number_format((float) $this->GetValue('EnergyToday'), 2, ',', '') . ' kWh',
                $this->Translate('Energy yesterday') . ': '
                    . number_format((float) $this->GetValue('EnergyYesterday'), 2, ',', '') . ' kWh'
            )
            . Style::stat(
                $this->Translate('Energy this week'),
                number_format((float) $this->GetValue('EnergyWeek'), 2, ',', '') . ' kWh',
                $this->Translate('Energy this month') . ': '
                    . number_format((float) $this->GetValue('EnergyMonth'), 2, ',', '') . ' kWh'
            )
            . Style::stat(
                $this->Translate('Energy') . ' ' . $this->Translate('total'),
                number_format((float) $this->GetValue('EnergyTotal'), 2, ',', '') . ' kWh',
                $this->Translate('Energy source') . ': ' . (string) $this->GetValue('EnergySource')
            )
            . Style::stat(
                $this->Translate('Electrical heating duration'),
                number_format((float) $this->GetValue('ElectricalHeatingDurationHours'), 1, ',', '') . ' h',
                $this->Translate('Socket on duration') . ': '
                    . number_format((float) $this->GetValue('SocketOnDurationHours'), 1, ',', '') . ' h'
            )
            . Style::stat(
                $this->Translate('PV heating energy'),
                number_format((float) $this->GetValue('EstimatedPVHeatingEnergy'), 2, ',', '') . ' kWh'
            )
            . '</div>';

        return Style::section($this->Translate('Energy and power'), $content);
    }

    private function RenderTechnicalDetails(): string
    {
        $degree = chr(194) . chr(176) . 'C';
        $secondary = (bool) $this->GetValue('SecondarySensorAvailable')
            ? number_format((float) $this->GetValue('SecondaryTemperature'), 1, ',', '') . ' ' . $degree
            : $this->Translate('Unavailable');
        $difference = number_format((float) $this->GetValue('TemperatureDifference'), 1, ',', '') . ' K';

        $nextComfortTimestamp = (int) $this->GetValue('NextComfortTime');
        if ($nextComfortTimestamp > 0) {
            $nextComfortDate = (new DateTimeImmutable('@' . $nextComfortTimestamp))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $nextComfort = $this->Translate(WeeklySchedule::DAY_NAMES[(int) $nextComfortDate->format('N')])
                . ' ' . $nextComfortDate->format('H:i');
        } else {
            $nextComfort = $this->Translate('No upcoming comfort phase');
        }

        $predictedStartTimestamp = (int) $this->GetValue('PredictedHeatingStart');
        if ($predictedStartTimestamp > 0) {
            $predictedStartDate = (new DateTimeImmutable('@' . $predictedStartTimestamp))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $predictedStart = $this->Translate(WeeklySchedule::DAY_NAMES[(int) $predictedStartDate->format('N')])
                . ' ' . $predictedStartDate->format('H:i');
        } else {
            $predictedStart = $this->Translate('No predicted heating start');
        }

        $content = '<div class="mvs-dashboard-grid">'
            . Style::stat($this->Translate('Secondary temperature'), $secondary)
            . Style::stat($this->Translate('Temperature difference'), $difference)
            . Style::stat($this->Translate('Next comfort phase'), $nextComfort)
            . Style::stat($this->Translate('Predicted heating start'), $predictedStart)
            . Style::stat(
                $this->Translate('Estimated heating duration'),
                number_format((float) $this->GetValue('EstimatedHeatingDuration'), 0, ',', '') . ' min',
                $this->Translate('Estimated heating rate') . ': '
                    . number_format((float) $this->GetValue('EstimatedHeatingRate'), 2, ',', '') . ' K/h'
            )
            . Style::stat(
                $this->Translate('Estimated cooling rate'),
                number_format((float) $this->GetValue('EstimatedCoolingRate'), 2, ',', '') . ' K/h',
                $this->Translate('Estimated residual heating') . ': '
                    . number_format((float) $this->GetValue('EstimatedResidualHeating'), 2, ',', '') . ' K'
            )
            . Style::stat(
                $this->Translate('Prediction confidence'),
                number_format((float) $this->GetValue('PredictionConfidence'), 0, ',', '') . ' %'
            )
            . Style::stat(
                $this->Translate('Learning status'),
                (string) $this->GetValue('LearningStatus')
            )
            . Style::stat(
                $this->Translate('PV heating possible'),
                $this->Translate((bool) $this->GetValue('PVHeatingPossible') ? 'Yes' : 'No'),
                $this->Translate('PV permission fresh') . ': '
                    . $this->Translate((bool) $this->GetValue('PVPermissionFresh') ? 'Yes' : 'No')
            )
            . Style::stat(
                $this->Translate('Allowed PV target'),
                number_format((float) $this->GetValue('PVTargetCeiling'), 1, ',', '') . ' ' . $degree,
                $this->Translate('Expected temperature at comfort time') . ': '
                    . number_format((float) $this->GetValue('ExpectedComfortTemperature'), 1, ',', '') . ' ' . $degree
            )
            . '</div>';

        $lastError = trim((string) $this->GetValue('LastError'));
        $diagnostics = '<div class="mvs-control-intro">'
            . htmlspecialchars((string) $this->GetValue('ControllerStatus'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>'
            . ($lastError === '' ? '' : Style::alert($lastError, 'error'))
            . '<div class="mvs-help">' . $this->Translate('Archive status') . ': '
            . htmlspecialchars((string) $this->GetValue('ArchiveStatus'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';

        if ((bool) $this->GetValue('RuntimeLockoutActive')) {
            $diagnostics .= Style::button($this->Translate('Reset safety lockout'), 'SafetyCommand', 1, true);
        }

        return '<div class="mvs-content">'
            . Style::section($this->Translate('More status'), $content)
            . Style::section($this->Translate('Diagnostics'), $diagnostics)
            . '</div>';
    }


    private function RenderHistoricalChart(): string
    {
        $series = $this->GetHistoricalChartSeries()['series'] ?? [];
        if (array_filter($series, static fn (array $points): bool => $points !== []) === []) {
            return '<div>' . htmlspecialchars($this->Translate('No archived trend data is available yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }

        $now = $this->getTime();
        $start = $now - 86400;
        $left = 42.0;
        $width = 650.0;
        $tempTop = 15.0;
        $tempHeight = 112.0;
        $powerTop = 146.0;
        $powerHeight = 46.0;
        $mapX = static fn (int $timestamp): float => $left
            + (min(1.0, max(0.0, ($timestamp - $start) / max(1, $now - $start))) * $width);
        $mapTempY = static fn (float $value): float => $tempTop
            + ((35.0 - min(35.0, max(15.0, $value))) / 20.0 * $tempHeight);
        $maximumPower = max(100.0, (float) $this->ReadPropertyInteger('NominalHeatingPower') * 1.2);
        $mapPowerY = static fn (float $value): float => $powerTop
            + (($maximumPower - min($maximumPower, max(0.0, $value))) / $maximumPower * $powerHeight);

        $grid = '';
        foreach ([15, 20, 25, 30, 35] as $temperature) {
            $y = $mapTempY((float) $temperature);
            $grid .= '<line x1="' . $left . '" y1="' . number_format($y, 2, '.', '')
                . '" x2="' . ($left + $width) . '" y2="' . number_format($y, 2, '.', '')
                . '" stroke="currentColor" opacity="0.12"/><text x="2" y="' . number_format($y + 3, 2, '.', '')
                . '" fill="currentColor" font-size="9">' . $temperature . '°</text>';
        }

        $localStart = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $timeLines = '';
        foreach ([0, 6, 12, 18, 24] as $hour) {
            $x = $mapX($start + ($hour * 3600));
            $timeLines .= '<line x1="' . number_format($x, 2, '.', '') . '" y1="8" x2="'
                . number_format($x, 2, '.', '') . '" y2="198" stroke="currentColor" opacity="0.1"/>'
                . '<text x="' . number_format($x, 2, '.', '') . '" y="216" text-anchor="middle" fill="currentColor" font-size="9">'
                . $localStart->modify('+' . ($hour * 60) . ' minutes')->format('H:i') . '</text>';
        }

        $colors = [
            'RoomTemperature'            => '#d97843',
            'SecondaryTemperature'       => '#8a63bf',
            'EffectiveTargetTemperature' => '#4a9a63',
        ];
        $seriesLabels = [
            'RoomTemperature'            => 'Room temperature',
            'SecondaryTemperature'       => 'Secondary temperature',
            'EffectiveTargetTemperature' => 'Effective target temperature',
        ];
        $temperaturePaths = '';
        $legend = '';
        foreach ($colors as $ident => $color) {
            $points = $series[$ident] ?? [];
            if ($points === []) {
                continue;
            }
            $coordinates = [];
            foreach ($points as [$timestamp, $value]) {
                $coordinates[] = number_format($mapX((int) $timestamp), 2, '.', '') . ','
                    . number_format($mapTempY((float) $value), 2, '.', '');
            }
            $temperaturePaths .= '<polyline points="' . implode(' ', $coordinates)
                . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            $legend .= '<span style="color:' . $color . ';margin-right:10px">● '
                . htmlspecialchars($this->Translate($seriesLabels[$ident] ?? $ident), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        $pvBands = '';
        $pvPoints = $series['PVHeatingActive'] ?? [];
        foreach ($pvPoints as $index => $point) {
            if ((float) $point[1] < 0.5) {
                continue;
            }
            $end = isset($pvPoints[$index + 1][0]) ? (int) $pvPoints[$index + 1][0] : $now;
            $x1 = $mapX((int) $point[0]);
            $x2 = $mapX($end);
            $pvBands .= '<rect x="' . number_format($x1, 2, '.', '') . '" y="' . $tempTop . '" width="'
                . number_format(max(1.0, $x2 - $x1), 2, '.', '') . '" height="' . $tempHeight
                . '" fill="#f0a832" opacity="0.11"><title>'
                . htmlspecialchars($this->Translate('PV heating phase'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</title></rect>';
        }

        $powerCoordinates = [];
        foreach ($series['CurrentHeatingPower'] ?? [] as [$timestamp, $value]) {
            $powerCoordinates[] = number_format($mapX((int) $timestamp), 2, '.', '') . ','
                . number_format($mapPowerY((float) $value), 2, '.', '');
        }
        $powerPath = $powerCoordinates === [] ? ''
            : '<polyline points="' . implode(' ', $powerCoordinates)
                . '" fill="none" stroke="#e0b33f" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        $svg = '<svg role="img" aria-label="' . htmlspecialchars($this->Translate('Temperature and power history for the last 24 hours'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" viewBox="0 0 704 226" style="width:100%;height:auto;color:var(--content-color)">'
            . $grid . $timeLines . $pvBands . $temperaturePaths
            . '<line x1="' . $left . '" y1="' . $powerTop . '" x2="' . ($left + $width)
            . '" y2="' . $powerTop . '" stroke="currentColor" opacity="0.25"/>'
            . '<text x="2" y="' . ($powerTop + 4) . '" fill="currentColor" font-size="9">W</text>'
            . '<text x="2" y="' . ($powerTop + $powerHeight) . '" fill="currentColor" font-size="9">0</text>'
            . $powerPath . '</svg>';

        return $svg . '<div style="font-size:.72rem">' . $legend . '<span style="color:#e0b33f">● '
            . htmlspecialchars($this->Translate('Power'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></div>';
    }

    private function GetHistoricalChartSeries(): array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID') || !function_exists('AC_GetAggregatedValues')) {
            return ['series' => []];
        }

        $now = $this->getTime();
        $cacheAt = $this->ReadAttributeInteger('HistoryChartCacheAt');
        $cache = json_decode($this->ReadAttributeString('HistoryChartCache'), true);
        if (is_array($cache) && $cacheAt > 0 && ($now - $cacheAt) < 300) {
            return $cache;
        }

        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if ($archives === []) {
            return ['series' => []];
        }

        $series = [];
        $start = $now - 86400;
        foreach ([
            'RoomTemperature',
            'SecondaryTemperature',
            'EffectiveTargetTemperature',
            'CurrentHeatingPower',
            'PVHeatingActive',
        ] as $ident) {
            $variableID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($variableID === false || $variableID <= 0) {
                continue;
            }
            try {
                $rows = AC_GetAggregatedValues((int) $archives[0], (int) $variableID, 5, $start, $now, 288);
                $points = [];
                foreach ($rows as $row) {
                    $timestamp = (int) ($row['TimeStamp'] ?? $row['Timestamp'] ?? 0);
                    $value = $row['Avg'] ?? $row['Value'] ?? null;
                    if ($timestamp > 0 && is_numeric($value) && is_finite((float) $value)) {
                        $points[] = [$timestamp, (float) $value];
                    }
                }
                usort($points, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
                $series[$ident] = $points;
            } catch (Throwable $exception) {
                $this->SendDebug('History query failed for ' . $ident, $exception->getMessage(), 0);
            }
        }

        $result = ['series' => $series];
        $this->WriteAttributeString('HistoryChartCache', json_encode($result, JSON_THROW_ON_ERROR));
        $this->WriteAttributeInteger('HistoryChartCacheAt', $now);
        return $result;
    }
}
