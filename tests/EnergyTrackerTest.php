<?php

declare(strict_types=1);

use ModuleSHC\EnergyTracker;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/EnergyTracker.php';

final class EnergyTrackerTest extends TestCase
{
    public function testCumulativeMeterCreatesMonotonicTotalAndPeriodEnergy(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $beforeMidnight = (new DateTimeImmutable('2026-01-01 23:59:30', $zone))->getTimestamp();
        $afterMidnight = (new DateTimeImmutable('2026-01-02 00:00:30', $zone))->getTimestamp();

        $state = EnergyTracker::observe(EnergyTracker::create(), $beforeMidnight, 10.0, true, 100.0, true, false, 10.0, 'Europe/Berlin');
        $state = EnergyTracker::observe($state, $afterMidnight, 10.01, true, 100.0, true, true, 10.0, 'Europe/Berlin');
        $values = EnergyTracker::values($state, $afterMidnight, 'Europe/Berlin');

        $this->assertEqualsWithDelta(10.01, $values['EnergyTotal'], 0.000001);
        $this->assertEqualsWithDelta(0.005, $values['EnergyYesterday'], 0.000001);
        $this->assertEqualsWithDelta(0.005, $values['EnergyToday'], 0.000001);
        $this->assertEqualsWithDelta(0.01, $values['RegularHeatingEnergy'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $values['EstimatedPVHeatingEnergy'], 0.000001);
        $this->assertFalse($values['EnergyReadingEstimated']);
    }

    public function testMeterResetDoesNotReduceLifetimeTotalOrCountResetAsEnergy(): void
    {
        $state = EnergyTracker::observe(EnergyTracker::create(), 1000, 5.0, true, null, false, false, 10.0);
        $state = EnergyTracker::observe($state, 1060, 0.2, true, null, false, false, 10.0);
        $state = EnergyTracker::observe($state, 1120, 0.3, true, null, false, false, 10.0);
        $values = EnergyTracker::values($state, 1120);

        $this->assertEqualsWithDelta(5.1, $values['EnergyTotal'], 0.000001);
        $this->assertSame(1060, $values['EnergyMeterResetAt']);
    }

    public function testPowerIntegrationIsEstimatedAndUsesOnlyShortValidIntervals(): void
    {
        $state = EnergyTracker::observe(EnergyTracker::create(), 1000, null, false, 100.0, true, false, 10.0, 'UTC', 300);
        $state = EnergyTracker::observe($state, 1060, null, false, 100.0, true, true, 10.0, 'UTC', 300);
        $values = EnergyTracker::values($state, 1060);

        $this->assertEqualsWithDelta(100.0 / 60000.0, $values['EnergyTotal'], 0.000001);
        $this->assertTrue($values['EnergyReadingEstimated']);
        $this->assertEqualsWithDelta(1.0 / 60.0, $values['SocketOnDurationHours'], 0.000001);
        $this->assertEqualsWithDelta(1.0 / 60.0, $values['ElectricalHeatingDurationHours'], 0.000001);

        $state = EnergyTracker::observe($state, 2000, null, false, 100.0, true, false, 10.0, 'UTC', 300);
        $values = EnergyTracker::values($state, 2000);
        $this->assertEqualsWithDelta(100.0 / 60000.0, $values['EnergyTotal'], 0.000001);
        $this->assertSame(940, $values['EnergyLastGapSeconds']);
    }

    public function testCumulativeMeterDeltaDuringPVPhaseIsClassifiedSeparately(): void
    {
        $state = EnergyTracker::observe(EnergyTracker::create(), 1000, 2.0, true, 100.0, true, true, 10.0);
        $state = EnergyTracker::observe($state, 1060, 2.02, true, 100.0, true, true, 10.0);
        $values = EnergyTracker::values($state, 1060);

        $this->assertEqualsWithDelta(0.02, $values['EstimatedPVHeatingEnergy'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $values['RegularHeatingEnergy'], 0.000001);
    }
}
