<?php

declare(strict_types=1);

use ModuleSHC\TemperatureController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/TemperatureController.php';

final class TemperatureControllerTest extends TestCase
{
    public function testHysteresisSwitchesAtTheConfiguredBandAndRetainsStateInsideIt(): void
    {
        $this->assertTrue(TemperatureController::hysteresisDemand(19.6, 20.0, 0.3, false));
        $this->assertFalse(TemperatureController::hysteresisDemand(20.4, 20.0, 0.3, true));
        $this->assertTrue(TemperatureController::hysteresisDemand(20.0, 20.0, 0.3, true));
        $this->assertFalse(TemperatureController::hysteresisDemand(20.0, 20.0, 0.3, false));
    }

    public function testSafetyAndOffModeOverrideAllHeatingDemands(): void
    {
        $this->assertFalse(TemperatureController::shouldSwitchOn(false, 0, 18.0, 28.0, false, true, true));
        $this->assertFalse(TemperatureController::shouldSwitchOn(true, 2, 18.0, 28.0, false, true, true));
        $this->assertFalse(TemperatureController::shouldSwitchOn(true, 0, 28.0, 28.0, false, true, true));
        $this->assertFalse(TemperatureController::shouldSwitchOn(true, 0, 18.0, 28.0, true, true, true));
    }

    public function testRegularDemandWorksWithoutPVAndPVCanOnlyAddHeat(): void
    {
        $this->assertTrue(TemperatureController::shouldSwitchOn(true, 0, 18.0, 28.0, false, true, false));
        $this->assertTrue(TemperatureController::shouldSwitchOn(true, 0, 22.0, 28.0, false, false, true));
        $this->assertFalse(TemperatureController::shouldSwitchOn(true, 0, 22.0, 28.0, false, false, false));
    }
}
