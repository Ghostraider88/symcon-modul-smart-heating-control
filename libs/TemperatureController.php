<?php

declare(strict_types=1);

namespace ModuleSHC;

/** Stateless base thermostat and final safety priority for a control cycle. */
final class TemperatureController
{
    public static function hysteresisDemand(
        float $temperature,
        float $target,
        float $hysteresis,
        bool $previousDemand
    ): bool {
        if ($temperature <= $target - $hysteresis) {
            return true;
        }
        if ($temperature >= $target + $hysteresis) {
            return false;
        }

        return $previousDemand;
    }

    public static function shouldSwitchOn(
        bool $primarySensorUsable,
        int $mode,
        float $temperature,
        float $maximumTemperature,
        bool $runtimeLockout,
        bool $regularDemand,
        bool $additionalDemand
    ): bool {
        if (
            !$primarySensorUsable
            || $mode === 2
            || $temperature >= $maximumTemperature
            || $runtimeLockout
        ) {
            return false;
        }

        return $regularDemand || $additionalDemand;
    }
}
