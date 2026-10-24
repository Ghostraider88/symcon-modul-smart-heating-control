<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

final class MyModuleTest extends TestCaseSymconValidation
{
    protected function setUp(): void
    {
        IPS\Kernel::reset();
        parent::setUp();
    }

    public function testValidateModule(): void
    {
        $this->validateModule(__DIR__ . '/../MyModule');
    }

    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testStrictLifecycleAndAction(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);

        $switchID = IPS_GetObjectIDByIdent('Switch', $instanceID);
        $statusID = IPS_GetObjectIDByIdent('Status', $instanceID);

        $this->assertTrue(IPS_VariableExists($switchID));
        $this->assertTrue(IPS_VariableExists($statusID));
        $this->assertSame(VARIABLETYPE_BOOLEAN, IPS_GetVariable($switchID)['VariableType']);
        $this->assertFalse(GetValueBoolean($switchID));
        $this->assertSame(104, IPS_GetInstance($instanceID)['InstanceStatus']);

        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $this->expectException(LogicException::class);
        $module->RequestAction('Switch', true);
    }

    public function testConfigurationUpdateAndVisualization(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);

        IPS_SetProperty($instanceID, 'Hostname', 'example.test');
        IPS_SetProperty($instanceID, 'Interval', 60);
        IPS_ApplyChanges($instanceID);

        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $module->RequestAction('Switch', true);

        $switchID = IPS_GetObjectIDByIdent('Switch', $instanceID);
        $this->assertTrue(GetValueBoolean($switchID));
        $this->assertSame(102, IPS_GetInstance($instanceID)['InstanceStatus']);

        $tile = $module->GetVisualizationTile();
        $this->assertStringContainsString('mvs-status-active', $tile);
        $this->assertStringContainsString('onclick="requestAction(&quot;Switch&quot;, false)"', $tile);

        $updateFunction = $this->getModuleConfiguration()['prefix'] . '_Update';
        $updateFunction($instanceID);
        $statusID = IPS_GetObjectIDByIdent('Status', $instanceID);
        $this->assertStringStartsWith('Demo update completed at ', GetValueString($statusID));
        $this->assertStringContainsString('Last demo update:', $module->GetVisualizationTile());
    }

    public function testInvalidIntervalIsRejected(): void
    {
        $instanceID = $this->createInstance();
        IPS_SetProperty($instanceID, 'Hostname', 'example.test');
        IPS_SetProperty($instanceID, 'Interval', 86401);
        IPS_ApplyChanges($instanceID);

        $this->assertSame(202, IPS_GetInstance($instanceID)['InstanceStatus']);

        $this->expectException(LogicException::class);
        IPS\InstanceManager::getInstanceInterface($instanceID)->RequestAction('Switch', true);
    }

    public function testMalformedHostnameIsRejected(): void
    {
        $instanceID = $this->createInstance();
        IPS_SetProperty($instanceID, 'Hostname', '<script>hostname-probe</script>');
        IPS_ApplyChanges($instanceID);

        $this->assertSame(202, IPS_GetInstance($instanceID)['InstanceStatus']);
        $tile = IPS\InstanceManager::getInstanceInterface($instanceID)->GetVisualizationTile();
        $this->assertStringContainsString('Hostname is invalid.', $tile);
        $this->assertStringNotContainsString('hostname-probe', $tile);
    }

    public function testRequestActionRejectsWrongValueType(): void
    {
        $instanceID = $this->createInstance();
        IPS_ApplyChanges($instanceID);

        $this->expectException(InvalidArgumentException::class);
        IPS\InstanceManager::getInstanceInterface($instanceID)->RequestAction('Switch', 'true');
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
            file_get_contents(__DIR__ . '/../MyModule/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
