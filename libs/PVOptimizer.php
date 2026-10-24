<?php

declare(strict_types=1);

namespace ModuleSHC;

/** Pure decision logic for the supplemental PV heating interface. */
final class PVOptimizer
{
    public static function evaluate(array $input): array
    {
        $now = max(0, (int) ($input['now'] ?? time()));
        $mode = (int) ($input['mode'] ?? 0);
        $temperature = self::number($input['temperature'] ?? null, -100.0);
        $regularTarget = self::number($input['regularTarget'] ?? null, 20.0);
        $requestedPVTarget = self::number($input['pvTarget'] ?? null, $regularTarget);
        $maximumRoomTemperature = self::number($input['maximumRoomTemperature'] ?? null, 28.0);
        $maximumOffset = max(0.0, self::number($input['maximumTemperatureOffset'] ?? null, 1.0));
        $hysteresis = max(0.05, self::number($input['hysteresis'] ?? null, 0.3));
        $planningHorizonSeconds = max(0, (int) ($input['planningHorizonHours'] ?? 24)) * 3600;
        $coolingRate = max(0.0, self::number($input['coolingRateKPerHour'] ?? null, 0.0));
        $residualHeat = max(0.0, self::number($input['residualHeatK'] ?? null, 0.0));
        $nextComfortTimestamp = max(0, (int) ($input['nextComfortTimestamp'] ?? 0));
        $nextComfortTarget = self::number($input['nextComfortTarget'] ?? null, $regularTarget);
        $scheduleEnabled = (bool) ($input['scheduleEnabled'] ?? false);
        $permissionRequested = (bool) ($input['permission'] ?? false);
        $permissionUpdatedAt = max(0, (int) ($input['permissionUpdatedAt'] ?? 0));
        $permissionTimeout = max(0, (int) ($input['permissionTimeoutSeconds'] ?? 0));

        $permissionFresh = !$permissionRequested || $permissionTimeout === 0
            || ($permissionUpdatedAt > 0 && $now >= $permissionUpdatedAt && ($now - $permissionUpdatedAt) <= $permissionTimeout);
        $permissionEffective = $permissionRequested && $permissionFresh;
        $referenceTarget = $regularTarget;
        $comfortInHorizon = !$scheduleEnabled;
        $hoursToComfort = 0.0;
        $expectedTemperatureAtComfort = $temperature;

        if ($scheduleEnabled && $nextComfortTimestamp > $now) {
            $secondsToComfort = $nextComfortTimestamp - $now;
            $hoursToComfort = $secondsToComfort / 3600.0;
            $comfortInHorizon = $secondsToComfort <= $planningHorizonSeconds;
            if ($comfortInHorizon && $nextComfortTarget > $regularTarget) {
                $referenceTarget = $nextComfortTarget;
                $expectedTemperatureAtComfort = $temperature + $residualHeat - ($coolingRate * $hoursToComfort);
            }
        }

        $targetCeiling = min(
            $requestedPVTarget,
            $maximumRoomTemperature,
            $referenceTarget + $maximumOffset
        );
        $capacityAvailable = $mode !== 2
            && $targetCeiling > ($regularTarget + 0.05)
            && $temperature < ($targetCeiling + $hysteresis);
        $futureNeed = !$scheduleEnabled
            || ($comfortInHorizon && $expectedTemperatureAtComfort < ($nextComfortTarget - $hysteresis));
        $possible = $capacityAvailable && $futureNeed;

        $active = false;
        if ($possible && $permissionEffective) {
            $active = $temperature <= ($targetCeiling - $hysteresis)
                || ($temperature < ($targetCeiling + $hysteresis) && (bool) ($input['previousActive'] ?? false));
        }

        $reason = match (true) {
            $mode === 2           => 'off',
            !$comfortInHorizon    => 'comfort_outside_horizon',
            !$futureNeed          => 'no_future_heat_need',
            !$capacityAvailable   => 'temperature_limit',
            !$permissionFresh     => 'permission_stale',
            !$permissionRequested => 'waiting_for_permission',
            !$active              => 'target_reached',
            default               => 'active',
        };

        return [
            'possible'                     => $possible,
            'active'                       => $active,
            'permissionFresh'              => $permissionFresh,
            'effectivePermission'          => $permissionEffective,
            'targetCeiling'                => $targetCeiling,
            'referenceTarget'              => $referenceTarget,
            'expectedTemperatureAtComfort' => $expectedTemperatureAtComfort,
            'hoursToComfort'               => $hoursToComfort,
            'reason'                       => $reason,
        ];
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : $default;
    }
}
