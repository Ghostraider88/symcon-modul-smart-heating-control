<?php

declare(strict_types=1);

namespace ModuleSHC;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Persistable energy accounting for a cumulative kWh meter or a W-only estimate.
 * Period buckets are bounded and survive module restarts.
 */
final class EnergyTracker
{
    public static function create(): array
    {
        return [
            'version'            => 1,
            'lastTimestamp'      => 0,
            'lastMeterKWh'       => null,
            'lastPowerW'         => null,
            'lastSocketOn'       => false,
            'lastPVActive'       => false,
            'totalKWh'           => 0.0,
            'meterEnergyKWh'     => 0.0,
            'estimatedEnergyKWh' => 0.0,
            'pvEnergyKWh'        => 0.0,
            'regularEnergyKWh'   => 0.0,
            'electricalSeconds'  => 0,
            'socketOnSeconds'    => 0,
            'dayBuckets'         => [],
            'weekBuckets'        => [],
            'monthBuckets'       => [],
            'source'             => 'unavailable',
            'meterResetAt'       => 0,
            'lastGapSeconds'     => 0,
        ];
    }

    public static function normalize(mixed $state): array
    {
        $default = self::create();
        if (!is_array($state)) {
            return $default;
        }

        foreach ([
            'lastTimestamp',
            'electricalSeconds',
            'socketOnSeconds',
            'meterResetAt',
            'lastGapSeconds',
        ] as $key) {
            $default[$key] = max(0, (int) ($state[$key] ?? $default[$key]));
        }

        foreach ([
            'totalKWh',
            'meterEnergyKWh',
            'estimatedEnergyKWh',
            'pvEnergyKWh',
            'regularEnergyKWh',
        ] as $key) {
            $value = $state[$key] ?? $default[$key];
            $default[$key] = is_numeric($value) && is_finite((float) $value)
                ? max(0.0, (float) $value)
                : 0.0;
        }

        foreach (['lastMeterKWh', 'lastPowerW'] as $key) {
            $value = $state[$key] ?? null;
            $default[$key] = is_numeric($value) && is_finite((float) $value)
                ? max(0.0, (float) $value)
                : null;
        }

        foreach (['lastSocketOn', 'lastPVActive'] as $key) {
            $default[$key] = (bool) ($state[$key] ?? false);
        }

        $default['source'] = in_array(($state['source'] ?? null), ['meter', 'estimated', 'unavailable'], true)
            ? $state['source']
            : 'unavailable';
        $default['dayBuckets'] = self::normalizeBuckets($state['dayBuckets'] ?? [], 400);
        $default['weekBuckets'] = self::normalizeBuckets($state['weekBuckets'] ?? [], 104);
        $default['monthBuckets'] = self::normalizeBuckets($state['monthBuckets'] ?? [], 36);

        return $default;
    }

    public static function observe(
        mixed $storedState,
        int $timestamp,
        ?float $meterKWh,
        bool $meterConfigured,
        ?float $powerW,
        bool $socketOn,
        bool $pvActive,
        float $powerThreshold,
        string $timeZone = 'UTC',
        int $maximumIntegrationGapSeconds = 300
    ): array {
        $state = self::normalize($storedState);
        if ($timestamp <= 0) {
            return $state;
        }

        $zone = self::safeTimeZone($timeZone);
        $meterIsValid = $meterKWh !== null && is_finite($meterKWh) && $meterKWh >= 0.0;
        $powerIsValid = $powerW !== null && is_finite($powerW) && $powerW >= 0.0;
        $previousTimestamp = $state['lastTimestamp'];
        $elapsed = $previousTimestamp > 0 ? max(0, $timestamp - $previousTimestamp) : 0;
        $state['lastGapSeconds'] = $elapsed > $maximumIntegrationGapSeconds ? $elapsed : 0;

        if ($elapsed > 0 && $elapsed <= $maximumIntegrationGapSeconds) {
            if ($state['lastSocketOn']) {
                $state['socketOnSeconds'] += $elapsed;
                if (($state['lastPowerW'] ?? 0.0) > $powerThreshold) {
                    $state['electricalSeconds'] += $elapsed;
                }
            }
        }

        $energyDelta = 0.0;
        $source = 'unavailable';
        $meterReset = false;

        if ($meterConfigured) {
            if ($meterIsValid) {
                $source = 'meter';
                if ($state['lastMeterKWh'] === null) {
                    // The initial counter value establishes the lifetime baseline; it is not a new period delta.
                    $state['totalKWh'] = max($state['totalKWh'], $meterKWh);
                } elseif ($meterKWh + 0.0000001 < $state['lastMeterKWh']) {
                    $meterReset = true;
                    $state['meterResetAt'] = $timestamp;
                } elseif ($state['source'] === 'meter') {
                    $energyDelta = max(0.0, $meterKWh - $state['lastMeterKWh']);
                } else {
                    // Rebase after power integration so meter readings do not count the same interval twice.
                }

                $state['lastMeterKWh'] = $meterKWh;
            }
        } elseif ($powerIsValid) {
            $source = 'estimated';
            if (
                $elapsed > 0
                && $elapsed <= $maximumIntegrationGapSeconds
                && $state['lastSocketOn']
                && $state['lastPowerW'] !== null
                && max($state['lastPowerW'], $powerW) > $powerThreshold
            ) {
                $averagePower = ($state['lastPowerW'] + $powerW) / 2.0;
                $energyDelta = ($averagePower * $elapsed) / 3600000.0;
            }
        }

        $energyDelta = max(0.0, $energyDelta);
        if ($energyDelta > 0.0) {
            $state['totalKWh'] += $energyDelta;
            if ($source === 'meter') {
                $state['meterEnergyKWh'] += $energyDelta;
            } else {
                $state['estimatedEnergyKWh'] += $energyDelta;
            }

            if ($state['lastPVActive']) {
                $state['pvEnergyKWh'] += $energyDelta;
            } else {
                $state['regularEnergyKWh'] += $energyDelta;
            }

            self::addPeriodEnergy(
                $state,
                $energyDelta,
                $previousTimestamp,
                $timestamp,
                $zone,
                $state['lastPVActive']
            );
        }

        $state['source'] = $source;
        $state['lastTimestamp'] = $timestamp;
        $state['lastPowerW'] = $powerIsValid ? $powerW : null;
        $state['lastSocketOn'] = $socketOn;
        $state['lastPVActive'] = $pvActive;
        if ($meterReset) {
            $state['source'] = 'meter';
        }

        self::pruneBuckets($state['dayBuckets'], 400);
        self::pruneBuckets($state['weekBuckets'], 104);
        self::pruneBuckets($state['monthBuckets'], 36);

        return $state;
    }

    public static function values(array $storedState, int $timestamp, string $timeZone = 'UTC'): array
    {
        $state = self::normalize($storedState);
        $now = (new DateTimeImmutable('@' . max(1, $timestamp)))->setTimezone(self::safeTimeZone($timeZone));
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $thisWeek = $now->format('o-W');
        $lastWeek = $now->modify('-7 days')->format('o-W');
        $thisMonth = $now->format('Y-m');
        $lastMonth = $now->modify('first day of last month')->format('Y-m');

        return [
            'EnergyTotal'                    => $state['totalKWh'],
            'EnergyToday'                    => self::bucketValue($state['dayBuckets'], $today),
            'EnergyYesterday'                => self::bucketValue($state['dayBuckets'], $yesterday),
            'EnergyWeek'                     => self::bucketValue($state['weekBuckets'], $thisWeek),
            'EnergyMonth'                    => self::bucketValue($state['monthBuckets'], $thisMonth),
            'RegularHeatingEnergy'           => $state['regularEnergyKWh'],
            'EstimatedPVHeatingEnergy'       => $state['pvEnergyKWh'],
            'ElectricalHeatingDurationHours' => $state['electricalSeconds'] / 3600.0,
            'SocketOnDurationHours'          => $state['socketOnSeconds'] / 3600.0,
            'EnergyReadingEstimated'         => $state['source'] === 'estimated',
            'EnergyMeterResetAt'             => $state['meterResetAt'],
            'EnergyLastGapSeconds'           => $state['lastGapSeconds'],
            'EnergySource'                   => $state['source'],
            'EnergyLastMonth'                => self::bucketValue($state['monthBuckets'], $lastMonth),
            'EnergyLastWeek'                 => self::bucketValue($state['weekBuckets'], $lastWeek),
        ];
    }

    private static function addPeriodEnergy(
        array &$state,
        float $energyKWh,
        int $from,
        int $to,
        DateTimeZone $zone,
        bool $pvActive
    ): void {
        if ($to <= $from) {
            return;
        }

        $allocationStart = $from;
        if (($to - $from) > 86400) {
            // A long outage cannot be assigned accurately across calendar boundaries.
            $allocationStart = $to - 1;
        }

        $duration = max(1, $to - $allocationStart);
        $cursor = $allocationStart;
        while ($cursor < $to) {
            $local = (new DateTimeImmutable('@' . $cursor))->setTimezone($zone);
            $nextDay = $local->setTime(0, 0)->modify('+1 day');
            $segmentEnd = min($to, $nextDay->getTimestamp());
            if ($segmentEnd <= $cursor) {
                $segmentEnd = min($to, $cursor + 3600);
            }

            $share = $energyKWh * (($segmentEnd - $cursor) / $duration);
            $dayKey = $local->format('Y-m-d');
            $weekKey = $local->format('o-W');
            $monthKey = $local->format('Y-m');
            foreach ([
                ['dayBuckets', $dayKey],
                ['weekBuckets', $weekKey],
                ['monthBuckets', $monthKey],
            ] as [$bucketName, $key]) {
                if (!isset($state[$bucketName][$key])) {
                    $state[$bucketName][$key] = ['total' => 0.0, 'pv' => 0.0, 'regular' => 0.0];
                }
                $state[$bucketName][$key]['total'] += $share;
                $state[$bucketName][$key][$pvActive ? 'pv' : 'regular'] += $share;
            }
            $cursor = $segmentEnd;
        }
    }

    private static function normalizeBuckets(mixed $buckets, int $maximum): array
    {
        if (!is_array($buckets)) {
            return [];
        }

        $normalized = [];
        foreach ($buckets as $key => $values) {
            if (!is_string($key) || !is_array($values)) {
                continue;
            }
            $normalized[$key] = [];
            foreach (['total', 'pv', 'regular'] as $name) {
                $value = $values[$name] ?? 0.0;
                $normalized[$key][$name] = is_numeric($value) && is_finite((float) $value)
                    ? max(0.0, (float) $value)
                    : 0.0;
            }
        }

        ksort($normalized);
        return array_slice($normalized, -$maximum, null, true);
    }

    private static function pruneBuckets(array &$buckets, int $maximum): void
    {
        if (count($buckets) <= $maximum) {
            return;
        }
        ksort($buckets);
        $buckets = array_slice($buckets, -$maximum, null, true);
    }

    private static function bucketValue(array $buckets, string $key): float
    {
        return (float) ($buckets[$key]['total'] ?? 0.0);
    }

    private static function safeTimeZone(string $timeZone): DateTimeZone
    {
        try {
            return new DateTimeZone($timeZone);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
