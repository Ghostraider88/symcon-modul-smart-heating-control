<?php

declare(strict_types=1);

namespace ModuleSHC;

final class ThermalModel
{
    private const EMA_ALPHA = 0.25;
    private const MIN_SAMPLE_SECONDS = 60;
    private const MAX_SAMPLE_SECONDS = 1800;
    private const MAX_TEMPERATURE_STEP = 3.0;
    private const MAX_RESIDUAL_WINDOW_SECONDS = 5400;

    public static function create(): array
    {
        return [
            'heatingRateKPerHour' => 0.0,
            'coolingRateKPerHour' => 0.0,
            'residualHeatK'       => 0.0,
            'heatingSamples'      => 0,
            'coolingSamples'      => 0,
            'residualSamples'     => 0,
            'confidence'          => 0.0,
            'lastObservation'     => null,
            'residualWindow'      => null,
        ];
    }

    public static function normalize(mixed $model): array
    {
        $default = self::create();
        if (!is_array($model)) {
            return $default;
        }

        foreach ([
            'heatingRateKPerHour',
            'coolingRateKPerHour',
            'residualHeatK',
            'confidence',
        ] as $key) {
            if (isset($model[$key]) && is_numeric($model[$key]) && is_finite((float) $model[$key])) {
                $default[$key] = (float) $model[$key];
            }
        }

        foreach (['heatingSamples', 'coolingSamples', 'residualSamples'] as $key) {
            if (isset($model[$key]) && filter_var($model[$key], FILTER_VALIDATE_INT) !== false) {
                $default[$key] = max(0, (int) $model[$key]);
            }
        }

        $default['heatingRateKPerHour'] = min(3.0, max(0.0, $default['heatingRateKPerHour']));
        $default['coolingRateKPerHour'] = min(3.0, max(0.0, $default['coolingRateKPerHour']));
        $default['residualHeatK'] = min(3.0, max(0.0, $default['residualHeatK']));
        $default['confidence'] = min(1.0, max(0.0, $default['confidence']));

        if (is_array($model['lastObservation'] ?? null)) {
            $last = $model['lastObservation'];
            if (
                isset($last['time'], $last['temperature'], $last['socketOn'])
                && is_numeric($last['time'])
                && is_numeric($last['temperature'])
            ) {
                $power = $last['powerW'] ?? null;
                $default['lastObservation'] = [
                    'time'        => (int) $last['time'],
                    'temperature' => (float) $last['temperature'],
                    'socketOn'    => (bool) $last['socketOn'],
                    'powerW'      => is_numeric($power) ? (float) $power : null,
                ];
            }
        }

        if (is_array($model['residualWindow'] ?? null)) {
            $window = $model['residualWindow'];
            if (
                isset($window['startedAt'], $window['baseTemperature'], $window['peakTemperature'])
                && is_numeric($window['startedAt'])
                && is_numeric($window['baseTemperature'])
                && is_numeric($window['peakTemperature'])
            ) {
                $default['residualWindow'] = [
                    'startedAt'       => (int) $window['startedAt'],
                    'baseTemperature' => (float) $window['baseTemperature'],
                    'peakTemperature' => (float) $window['peakTemperature'],
                ];
            }
        }

        return $default;
    }

    public static function observe(array $model, array $sample, float $nominalPower, float $powerThreshold): array
    {
        $model = self::normalize($model);
        $current = self::normalizeObservation($sample);
        if ($current === null || $nominalPower <= 0.0) {
            return $model;
        }

        $previous = $model['lastObservation'];
        if ($previous === null) {
            $model['lastObservation'] = $current;
            return self::withUpdatedConfidence($model);
        }

        $elapsed = $current['time'] - $previous['time'];
        if ($elapsed <= 0) {
            return $model;
        }

        $stateChanged = $previous['socketOn'] !== $current['socketOn'];
        if ($stateChanged && $previous['socketOn'] && !$current['socketOn']) {
            $model['residualWindow'] = [
                'startedAt'       => $current['time'],
                'baseTemperature' => $current['temperature'],
                'peakTemperature' => $current['temperature'],
            ];
        }

        $window = $model['residualWindow'];
        if ($window !== null) {
            if ($current['socketOn'] || ($current['time'] - $window['startedAt']) > self::MAX_RESIDUAL_WINDOW_SECONDS) {
                $model = self::finishResidualWindow($model);
            } else {
                $window['peakTemperature'] = max($window['peakTemperature'], $current['temperature']);
                $model['residualWindow'] = $window;
            }
        }

        if ($stateChanged || $elapsed > self::MAX_SAMPLE_SECONDS) {
            $model['lastObservation'] = $current;
            return self::withUpdatedConfidence($model);
        }

        if ($elapsed < self::MIN_SAMPLE_SECONDS) {
            return self::withUpdatedConfidence($model);
        }

        $temperatureChange = $current['temperature'] - $previous['temperature'];
        if (abs($temperatureChange) > self::MAX_TEMPERATURE_STEP) {
            $model['lastObservation'] = $current;
            return self::withUpdatedConfidence($model);
        }

        if (
            $previous['socketOn']
            && $current['socketOn']
            && $previous['powerW'] !== null
            && $current['powerW'] !== null
            && $previous['powerW'] > $powerThreshold
            && $current['powerW'] > $powerThreshold
            && $temperatureChange > 0.0
        ) {
            $measuredRate = ($temperatureChange * 3600.0) / $elapsed;
            $normalizedRate = $measuredRate * ($nominalPower / max($previous['powerW'], $powerThreshold));
            if ($normalizedRate >= 0.01 && $normalizedRate <= 3.0) {
                $model['heatingRateKPerHour'] = self::ema(
                    $model['heatingRateKPerHour'],
                    $normalizedRate,
                    $model['heatingSamples']
                );
                $model['heatingSamples']++;
            }
        } elseif (
            !$previous['socketOn']
            && !$current['socketOn']
            && $temperatureChange < 0.0
            && ($previous['powerW'] === null || $previous['powerW'] <= $powerThreshold)
            && ($current['powerW'] === null || $current['powerW'] <= $powerThreshold)
        ) {
            $measuredRate = (-$temperatureChange * 3600.0) / $elapsed;
            if ($measuredRate >= 0.005 && $measuredRate <= 3.0) {
                $model['coolingRateKPerHour'] = self::ema(
                    $model['coolingRateKPerHour'],
                    $measuredRate,
                    $model['coolingSamples']
                );
                $model['coolingSamples']++;
            }
        }

        $model['lastObservation'] = $current;
        return self::withUpdatedConfidence($model);
    }

    public static function estimateHeatingMinutes(
        array $model,
        float $currentTemperature,
        float $targetTemperature,
        int $minimumSamples = 3,
        ?float $initialHeatingRateKPerHour = null
    ): ?float {
        $model = self::normalize($model);
        $heatingRate = $model['heatingRateKPerHour'];
        if ($model['heatingSamples'] < max(1, $minimumSamples) || $heatingRate <= 0.0) {
            if (
                $initialHeatingRateKPerHour === null
                || !is_finite($initialHeatingRateKPerHour)
                || $initialHeatingRateKPerHour <= 0.0
            ) {
                return null;
            }
            $heatingRate = $initialHeatingRateKPerHour;
        }

        $temperatureToAdd = max(
            0.0,
            $targetTemperature - $currentTemperature - $model['residualHeatK']
        );
        if ($temperatureToAdd <= 0.0) {
            return 0.0;
        }

        return ($temperatureToAdd / $heatingRate) * 60.0;
    }

    public static function estimatePreheatMinutes(
        array $model,
        float $currentTemperature,
        float $targetTemperature,
        int $minimumSamples,
        ?float $initialHeatingRateKPerHour,
        float $minutesUntilComfort,
        bool $heaterCurrentlyOn
    ): ?float {
        $model = self::normalize($model);
        $heatingRate = $model['heatingRateKPerHour'];
        if ($model['heatingSamples'] < max(1, $minimumSamples) || $heatingRate <= 0.0) {
            if (
                $initialHeatingRateKPerHour === null
                || !is_finite($initialHeatingRateKPerHour)
                || $initialHeatingRateKPerHour <= 0.0
            ) {
                return null;
            }
            $heatingRate = $initialHeatingRateKPerHour;
        }

        $temperatureToAdd = $targetTemperature - $currentTemperature - $model['residualHeatK'];
        $effectiveRate = $heatingRate;
        if (!$heaterCurrentlyOn) {
            $temperatureToAdd += $model['coolingRateKPerHour'] * max(0.0, $minutesUntilComfort) / 60.0;
            $effectiveRate += $model['coolingRateKPerHour'];
        }

        if ($temperatureToAdd <= 0.0) {
            return 0.0;
        }

        return ($temperatureToAdd / $effectiveRate) * 60.0;
    }

    public static function shouldStopForResidualHeat(
        array $model,
        float $currentTemperature,
        float $targetTemperature,
        int $minimumHeatingSamples = 3
    ): bool {
        $model = self::normalize($model);
        if (
            $model['heatingSamples'] < max(1, $minimumHeatingSamples)
            || $model['residualSamples'] < 2
            || $model['residualHeatK'] <= 0.0
        ) {
            return false;
        }

        return $currentTemperature >= ($targetTemperature - $model['residualHeatK']);
    }

    private static function normalizeObservation(array $sample): ?array
    {
        if (
            !isset($sample['time'], $sample['temperature'], $sample['socketOn'])
            || !is_numeric($sample['time'])
            || !is_numeric($sample['temperature'])
            || !is_finite((float) $sample['temperature'])
        ) {
            return null;
        }

        $power = $sample['powerW'] ?? null;
        return [
            'time'        => (int) $sample['time'],
            'temperature' => (float) $sample['temperature'],
            'socketOn'    => (bool) $sample['socketOn'],
            'powerW'      => is_numeric($power) && is_finite((float) $power) ? (float) $power : null,
        ];
    }

    private static function finishResidualWindow(array $model): array
    {
        $window = $model['residualWindow'];
        if ($window === null) {
            return $model;
        }

        $residual = $window['peakTemperature'] - $window['baseTemperature'];
        if ($residual > 0.0 && $residual <= self::MAX_TEMPERATURE_STEP) {
            $model['residualHeatK'] = self::ema(
                $model['residualHeatK'],
                $residual,
                $model['residualSamples']
            );
            $model['residualSamples']++;
        }
        $model['residualWindow'] = null;

        return $model;
    }

    private static function withUpdatedConfidence(array $model): array
    {
        $model['confidence'] = min(1.0, $model['heatingSamples'] / 8.0);
        return $model;
    }

    private static function ema(float $previous, float $sample, int $sampleCount): float
    {
        if ($sampleCount === 0 || $previous <= 0.0) {
            return $sample;
        }

        return (self::EMA_ALPHA * $sample) + ((1.0 - self::EMA_ALPHA) * $previous);
    }
}
