<?php

declare(strict_types=1);

use ModuleSHC\ThermalModel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/ThermalModel.php';

final class ThermalModelTest extends TestCase
{
    public function testHeatingRateLearnsFromSustainedMeasuredHeating(): void
    {
        $model = ThermalModel::create();
        foreach ([
            ['time' => 1000, 'temperature' => 20.0],
            ['time' => 1600, 'temperature' => 20.1],
            ['time' => 2200, 'temperature' => 20.2],
            ['time' => 2800, 'temperature' => 20.3],
        ] as $sample) {
            $model = ThermalModel::observe($model, $sample + ['socketOn' => true, 'powerW' => 900.0], 900.0, 10.0);
        }

        $this->assertSame(3, $model['heatingSamples']);
        $this->assertEqualsWithDelta(0.6, $model['heatingRateKPerHour'], 0.01);
        $this->assertSame(3, (int) round($model['confidence'] * 8));
    }

    public function testPreheatEstimateWaitsForSufficientHeatingSamples(): void
    {
        $model = ThermalModel::create();
        $this->assertNull(ThermalModel::estimateHeatingMinutes($model, 20.0, 24.0));

        $model['heatingSamples'] = 3;
        $model['heatingRateKPerHour'] = 0.5;
        $this->assertEqualsWithDelta(480.0, ThermalModel::estimateHeatingMinutes($model, 20.0, 24.0), 0.01);
    }

    public function testConfiguredInitialHeatingRateProvidesConservativeColdStartEstimate(): void
    {
        $model = ThermalModel::create();
        $this->assertEqualsWithDelta(
            800.0,
            ThermalModel::estimateHeatingMinutes($model, 20.0, 24.0, 3, 0.3),
            0.01
        );

        $model['residualHeatK'] = 0.5;
        $this->assertEqualsWithDelta(
            700.0,
            ThermalModel::estimateHeatingMinutes($model, 20.0, 24.0, 3, 0.3),
            0.01
        );
    }

    public function testPreheatEstimateAccountsForCoolingWhileWaiting(): void
    {
        $model = ThermalModel::create();
        $model['heatingSamples'] = 3;
        $model['heatingRateKPerHour'] = 0.5;
        $model['coolingRateKPerHour'] = 0.2;

        $this->assertEqualsWithDelta(
            360.0,
            ThermalModel::estimatePreheatMinutes($model, 20.0, 24.0, 3, 0.3, 60.0, false),
            0.01
        );
        $this->assertEqualsWithDelta(
            480.0,
            ThermalModel::estimatePreheatMinutes($model, 20.0, 24.0, 3, 0.3, 60.0, true),
            0.01
        );
    }

    public function testResidualHeatIsLearnedAfterSwitchOff(): void
    {
        $model = ThermalModel::create();
        $observations = [
            ['time' => 1000, 'temperature' => 21.0, 'socketOn' => true, 'powerW' => 900.0],
            ['time' => 1600, 'temperature' => 21.1, 'socketOn' => false, 'powerW' => 0.0],
            ['time' => 2200, 'temperature' => 21.2, 'socketOn' => false, 'powerW' => 0.0],
            ['time' => 2800, 'temperature' => 21.3, 'socketOn' => false, 'powerW' => 0.0],
            ['time' => 7201, 'temperature' => 21.2, 'socketOn' => false, 'powerW' => 0.0],
        ];
        foreach ($observations as $sample) {
            $model = ThermalModel::observe($model, $sample, 900.0, 10.0);
        }

        $this->assertSame(1, $model['residualSamples']);
        $this->assertEqualsWithDelta(0.2, $model['residualHeatK'], 0.01);
    }

    public function testUnplausibleTemperatureJumpsAreExcludedFromLearning(): void
    {
        $model = ThermalModel::create();
        $model = ThermalModel::observe($model, [
            'time'        => 1000,
            'temperature' => 20.0,
            'socketOn'    => true,
            'powerW'      => 900.0,
        ], 900.0, 10.0);
        $model = ThermalModel::observe($model, [
            'time'        => 1600,
            'temperature' => 25.0,
            'socketOn'    => true,
            'powerW'      => 900.0,
        ], 900.0, 10.0);

        $this->assertSame(0, $model['heatingSamples']);
        $this->assertSame(0.0, $model['heatingRateKPerHour']);
    }

    public function testResidualCutoffRequiresReliableLearningAndStopsBeforeTarget(): void
    {
        $model = ThermalModel::create();
        $model['heatingSamples'] = 3;
        $model['residualSamples'] = 2;
        $model['residualHeatK'] = 0.4;

        $this->assertTrue(ThermalModel::shouldStopForResidualHeat($model, 23.7, 24.0));
        $this->assertFalse(ThermalModel::shouldStopForResidualHeat($model, 23.5, 24.0));

        $model['residualSamples'] = 1;
        $this->assertFalse(ThermalModel::shouldStopForResidualHeat($model, 23.8, 24.0));
    }
}

\n