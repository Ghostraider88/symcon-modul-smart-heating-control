<?php

declare(strict_types=1);

use ModuleSHC\SafetyController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/SafetyController.php';

final class SafetyControllerTest extends TestCase
{
    public function testTemperatureMustBePlausibleAndFresh(): void
    {
        $this->assertTrue(SafetyController::isTemperatureUsable(21.5, 950, 1000, 100, -20.0, 60.0));
        $this->assertFalse(SafetyController::isTemperatureUsable(21.5, 800, 1000, 100, -20.0, 60.0));
        $this->assertFalse(SafetyController::isTemperatureUsable(70.0, 950, 1000, 100, -20.0, 60.0));
        $this->assertFalse(SafetyController::isTemperatureUsable(NAN, 950, 1000, 100, -20.0, 60.0));
    }

    public function testSecondarySensorDiscrepancyIsDiagnosticOnly(): void
    {
        $this->assertTrue(SafetyController::hasSensorDiscrepancy(22.0, 28.0, 5.0));
        $this->assertFalse(SafetyController::hasSensorDiscrepancy(22.0, null, 5.0));
        $this->assertFalse(SafetyController::hasSensorDiscrepancy(22.0, 26.0, 5.0));
    }

    public function testMaximumRunAndSwitchFeedbackTimeoutsAreDeterministic(): void
    {
        $this->assertTrue(SafetyController::hasExceededRuntime(true, 1000, 4600, 3600));
        $this->assertFalse(SafetyController::hasExceededRuntime(false, 1000, 4600, 3600));
        $this->assertTrue(SafetyController::hasSwitchFeedbackTimeout(true, false, 1000, 1030, 30));
        $this->assertFalse(SafetyController::hasSwitchFeedbackTimeout(false, false, 1000, 1030, 30));
    }
}
