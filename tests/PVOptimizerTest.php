<?php

declare(strict_types=1);

use ModuleSHC\PVOptimizer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/PVOptimizer.php';

final class PVOptimizerTest extends TestCase
{
    public function testPotentialRespectsComfortHorizonAndPVTemperatureOffset(): void
    {
        $result = PVOptimizer::evaluate([
            'now'                      => 1000,
            'mode'                     => 0,
            'temperature'              => 22.0,
            'regularTarget'            => 20.0,
            'pvTarget'                 => 27.0,
            'maximumRoomTemperature'   => 28.0,
            'maximumTemperatureOffset' => 1.0,
            'hysteresis'               => 0.3,
            'scheduleEnabled'          => true,
            'planningHorizonHours'     => 12,
            'nextComfortTimestamp'     => 1000 + (5 * 3600),
            'nextComfortTarget'        => 24.0,
            'coolingRateKPerHour'      => 0.2,
            'permission'               => true,
            'permissionUpdatedAt'      => 1000,
            'previousActive'           => false,
        ]);

        $this->assertTrue($result['possible']);
        $this->assertTrue($result['active']);
        $this->assertSame(25.0, $result['targetCeiling']);
        $this->assertSame(21.0, $result['expectedTemperatureAtComfort']);
    }

    public function testNoPVHeatingWhenComfortIsOutsidePlanningHorizonOrModeIsOff(): void
    {
        $base = [
            'now'                      => 1000,
            'mode'                     => 0,
            'temperature'              => 20.0,
            'regularTarget'            => 19.0,
            'pvTarget'                 => 25.0,
            'maximumRoomTemperature'   => 28.0,
            'maximumTemperatureOffset' => 1.0,
            'scheduleEnabled'          => true,
            'planningHorizonHours'     => 2,
            'nextComfortTimestamp'     => 1000 + (5 * 3600),
            'nextComfortTarget'        => 24.0,
        ];

        $outsideHorizon = PVOptimizer::evaluate($base);
        $off = PVOptimizer::evaluate(array_replace($base, ['mode' => 2]));

        $this->assertFalse($outsideHorizon['possible']);
        $this->assertFalse($off['possible']);
        $this->assertFalse($off['active']);
    }

    public function testStalePermissionExpiresWithoutAffectingPotentialOrRegularControl(): void
    {
        $result = PVOptimizer::evaluate([
            'now'                      => 2000,
            'mode'                     => 0,
            'temperature'              => 21.0,
            'regularTarget'            => 20.0,
            'pvTarget'                 => 25.0,
            'maximumRoomTemperature'   => 28.0,
            'maximumTemperatureOffset' => 1.0,
            'scheduleEnabled'          => false,
            'permission'               => true,
            'permissionUpdatedAt'      => 1000,
            'permissionTimeoutSeconds' => 300,
        ]);

        $this->assertTrue($result['possible']);
        $this->assertFalse($result['active']);
        $this->assertFalse($result['effectivePermission']);
        $this->assertSame('permission_stale', $result['reason']);
    }

    public function testReachingThePVTemperatureLimitStopsAdditionalDemand(): void
    {
        $result = PVOptimizer::evaluate([
            'now'                      => 2000,
            'mode'                     => 1,
            'temperature'              => 25.4,
            'regularTarget'            => 22.0,
            'pvTarget'                 => 28.0,
            'maximumRoomTemperature'   => 28.0,
            'maximumTemperatureOffset' => 1.0,
            'hysteresis'               => 0.3,
            'scheduleEnabled'          => false,
            'permission'               => true,
            'permissionUpdatedAt'      => 1900,
        ]);

        $this->assertFalse($result['possible']);
        $this->assertFalse($result['active']);
    }
}
