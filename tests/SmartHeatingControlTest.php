<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

final class SmartHeatingControlTest extends TestCaseSymconValidation
{
    protected function setUp(): void
    {
        IPS\Kernel::reset();
        parent::setUp();
    }

    public function testValidateModule(): void
    {
        $this->validateModule(__DIR__ . '/../SmartHeatingControl');
    }

    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testUnconfiguredInstanceCreatesControllerVariables(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);

        $identTypes = [
            'RoomTemperature'       => VARIABLETYPE_FLOAT,
            'SecondaryTemperature'  => VARIABLETYPE_FLOAT,
            'TemperatureDifference' => VARIABLETYPE_FLOAT,
            'HeatingDemand'         => VARIABLETYPE_BOOLEAN,
            'HeatingActive'         => VARIABLETYPE_BOOLEAN,
            'HeatingState'          => VARIABLETYPE_INTEGER,
            'FaultActive'           => VARIABLETYPE_BOOLEAN,
            'CurrentHeatingPower'   => VARIABLETYPE_FLOAT,
            'EnergyTotal'           => VARIABLETYPE_FLOAT,
            'EnergyToday'           => VARIABLETYPE_FLOAT,
            'EnergyYesterday'       => VARIABLETYPE_FLOAT,
            'EnergyWeek'            => VARIABLETYPE_FLOAT,
            'EnergyMonth'           => VARIABLETYPE_FLOAT,
            'PVHeatingEnable'       => VARIABLETYPE_BOOLEAN,
            'PVPermissionFresh'     => VARIABLETYPE_BOOLEAN,
            'PVHeatingPossible'     => VARIABLETYPE_BOOLEAN,
            'PVHeatingActive'       => VARIABLETYPE_BOOLEAN,
            'OperatingMode'         => VARIABLETYPE_INTEGER,
            'SafetyCommand'         => VARIABLETYPE_INTEGER,
            'ScheduleCommand'       => VARIABLETYPE_STRING,
        ];

        foreach ($identTypes as $ident => $type) {
            $variableID = IPS_GetObjectIDByIdent($ident, $instanceID);
            $this->assertTrue(IPS_VariableExists($variableID), $ident . ' should be registered.');
            $this->assertSame($type, IPS_GetVariable($variableID)['VariableType']);
            $this->assertNotEmpty(
                IPS_GetVariable($variableID)['VariablePresentation'],
                $ident . ' should have a current presentation.'
            );
        }

        $this->assertSame(104, IPS_GetInstance($instanceID)['InstanceStatus']);
        $this->assertSame(
            'Select a primary room temperature variable.',
            GetValueString(IPS_GetObjectIDByIdent('LastError', $instanceID))
        );

        $tile = IPS\InstanceManager::getInstanceInterface($instanceID)->GetVisualizationTile();
        $this->assertStringContainsString('requestAction(&quot;OperatingMode&quot;, 0)', $tile);
        $this->assertStringContainsString('requestAction(&quot;PVHeatingEnable&quot;, true)', $tile);
        $this->assertStringContainsString('requestAction(&quot;ScheduleCommand&quot;', $tile);
    }

    public function testOperatingModeActionValidatesValues(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);

        $module->RequestAction('OperatingMode', 1);
        $this->assertSame(1, GetValueInteger(IPS_GetObjectIDByIdent('OperatingMode', $instanceID)));

        $this->expectException(InvalidArgumentException::class);
        $module->RequestAction('OperatingMode', 'off');
    }

    public function testManualAndPVTargetsRejectOutOfRangeValues(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);

        $this->expectException(InvalidArgumentException::class);
        $module->RequestAction('ManualTargetTemperature', 45.0);
    }

    public function testConfigurationSelectsGenericSocketVariables(): void
    {
        $form = json_decode(
            file_get_contents(__DIR__ . '/../SmartHeatingControl/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $elements = [];
        foreach ($form['elements'] as $element) {
            if (isset($element['name'])) {
                $elements[$element['name']] = $element;
            }
        }

        $this->assertSame([0], $elements['HeaterSwitchID']['validVariableTypes']);
        $this->assertSame(1, $elements['HeaterSwitchID']['requiredAction']);
        $this->assertSame([1, 2], $elements['PowerVariableID']['validVariableTypes']);
        $this->assertSame([1, 2], $elements['EnergyVariableID']['validVariableTypes']);
    }

    public function testConfigurationDoesNotDependOnHeaterModelOrVolume(): void
    {
        $form = file_get_contents(__DIR__ . '/../SmartHeatingControl/form.json');
        $module = file_get_contents(__DIR__ . '/../SmartHeatingControl/module.php');

        $this->assertStringNotContainsString('HeaterModel', $form . $module);
        $this->assertStringNotContainsString('FillVolume', $form . $module);
    }

    public function testBoostCannotStartInOffMode(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $module->RequestAction('OperatingMode', 2);

        $this->expectException(InvalidArgumentException::class);
        $module->RequestAction('BoostCommand', 1);
    }

    public function testConfigurationExposesGenericPowerTimeoutArchiveAndPVLimits(): void
    {
        $form = json_decode(
            file_get_contents(__DIR__ . '/../SmartHeatingControl/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $names = array_column($form['elements'], 'name');

        foreach ([
            'PowerTimeoutSeconds',
            'MaximumSensorDifference',
            'MaximumPVTemperatureOffset',
            'PVPlanningHorizonHours',
            'PVPermissionTimeoutSeconds',
            'MaximumContinuousOnMinutes',
            'StopAfterMaximumContinuousOnTime',
            'ArchiveLoggingEnabled',
        ] as $requiredProperty) {
            $this->assertContains($requiredProperty, $names);
        }
    }

    private function createInstance(): int
    {
        $moduleConfiguration = $this->getModuleConfiguration();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        return IPS_CreateInstance($moduleConfiguration['id']);
    }

    private function getModuleConfiguration(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../SmartHeatingControl/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}

\n