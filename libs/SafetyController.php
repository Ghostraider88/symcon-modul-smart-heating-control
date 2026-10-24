<?php

declare(strict_types=1);

namespace ModuleSHC;

/** Pure safety checks shared by the live controller and its tests. */
final class SafetyController
{
    public static function isTemperatureUsable(
        ?float $temperature,
        int $updatedAt,
        int $now,
        int $timeoutSeconds,
        float $minimum,
        float $maximum
    ): bool {
        return $temperature !== null
            && is_finite($temperature)
            && $updatedAt > 0
            && $updatedAt <= $now
            && ($now - $updatedAt) <= $timeoutSeconds
            && $temperature >= $minimum
            && $temperature <= $maximum;
    }

    public static function hasSensorDiscrepancy(?float $primary, ?float $secondary, float $maximumDifference): bool
    {
        return $primary !== null
            && $secondary !== null
            && is_finite($primary)
            && is_finite($secondary)
            && abs($secondary - $primary) > max(0.0, $maximumDifference);
    }

    public static function hasExceededRuntime(bool $socketOn, int $socketOnSince, int $now, int $maximumOnSeconds): bool
    {
        return $maximumOnSeconds > 0
            && $socketOn
            && $socketOnSince > 0
            && $now >= $socketOnSince
            && ($now - $socketOnSince) >= $maximumOnSeconds;
    }

    public static function hasSwitchFeedbackTimeout(
        bool $desiredState,
        bool $actualState,
        int $mismatchSince,
        int $now,
        int $timeoutSeconds
    ): bool {
        return $desiredState !== $actualState
            && $mismatchSince > 0
            && $now >= $mismatchSince
            && ($now - $mismatchSince) >= $timeoutSeconds;
    }
}
