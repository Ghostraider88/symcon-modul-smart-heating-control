<?php

declare(strict_types=1);

namespace ModuleSHC;

use DateTimeImmutable;

final class WeeklySchedule
{
    public const DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public static function default(): array
    {
        $day = [
            ['time' => '00:00', 'temperature' => 20.0],
            ['time' => '07:00', 'temperature' => 24.0],
            ['time' => '09:00', 'temperature' => 20.0],
            ['time' => '19:00', 'temperature' => 24.0],
            ['time' => '21:00', 'temperature' => 20.0],
        ];

        return array_fill(0, 7, $day);
    }

    public static function normalize(mixed $schedule): ?array
    {
        if (!is_array($schedule) || !array_is_list($schedule) || count($schedule) !== 7) {
            return null;
        }

        $normalized = [];
        foreach ($schedule as $dayEvents) {
            if (!is_array($dayEvents) || !array_is_list($dayEvents) || count($dayEvents) < 1 || count($dayEvents) > 48) {
                return null;
            }

            $events = [];
            $seenTimes = [];
            foreach ($dayEvents as $event) {
                if (!is_array($event) || !isset($event['time'], $event['temperature'])) {
                    return null;
                }

                $time = self::normalizeTime($event['time']);
                if ($time === null || !is_numeric($event['temperature'])) {
                    return null;
                }

                $temperature = (float) $event['temperature'];
                if (!is_finite($temperature) || $temperature < 5.0 || $temperature > 35.0) {
                    return null;
                }
                if (isset($seenTimes[$time])) {
                    return null;
                }

                $seenTimes[$time] = true;
                $events[] = ['time' => $time, 'temperature' => round($temperature, 1)];
            }

            usort($events, static fn (array $left, array $right): int => strcmp($left['time'], $right['time']));
            if ($events[0]['time'] !== '00:00') {
                return null;
            }

            $normalized[] = $events;
        }

        return $normalized;
    }

    public static function targetAt(array $schedule, DateTimeImmutable $at): float
    {
        $schedule = self::normalize($schedule) ?? self::default();
        $dayIndex = (int) $at->format('N') - 1;
        $minuteOfDay = ((int) $at->format('G') * 60) + (int) $at->format('i');
        $target = (float) $schedule[$dayIndex][0]['temperature'];

        foreach ($schedule[$dayIndex] as $event) {
            $eventMinute = self::timeToMinutes($event['time']);
            if ($eventMinute > $minuteOfDay) {
                break;
            }
            $target = (float) $event['temperature'];
        }

        return $target;
    }

    public static function nextComfort(array $schedule, DateTimeImmutable $from): ?array
    {
        $schedule = self::normalize($schedule) ?? self::default();
        $previousTarget = self::targetAt($schedule, $from);

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $date = $from->setTime(0, 0)->modify('+' . $dayOffset . ' days');
            $dayIndex = (int) $date->format('N') - 1;
            $events = $schedule[$dayIndex];

            if ($dayOffset > 0) {
                $previousDayIndex = ($dayIndex + 6) % 7;
                $previousTarget = (float) $schedule[$previousDayIndex][count($schedule[$previousDayIndex]) - 1]['temperature'];
            }

            foreach ($events as $event) {
                $eventAt = self::dateAtTime($date, $event['time']);
                if ($eventAt <= $from) {
                    $previousTarget = (float) $event['temperature'];
                    continue;
                }

                $target = (float) $event['temperature'];
                if ($target > $previousTarget + 0.001) {
                    return [
                        'timestamp' => $eventAt->getTimestamp(),
                        'target'    => $target,
                        'date'      => $eventAt,
                    ];
                }
                $previousTarget = $target;
            }
        }

        return null;
    }

    public static function applyCommand(array $schedule, array $command): ?array
    {
        $schedule = self::normalize($schedule);
        if ($schedule === null || !isset($command['op'])) {
            return null;
        }

        $operation = (string) $command['op'];
        if ($operation === 'reset') {
            return self::default();
        }
        if ($operation === 'select') {
            return $schedule;
        }
        if ($operation === 'copy') {
            $source = self::validDay($command['source'] ?? null);
            $target = self::validDay($command['target'] ?? null);
            if ($source === null || $target === null) {
                return null;
            }
            $schedule[$target - 1] = $schedule[$source - 1];
            return $schedule;
        }

        $day = self::validDay($command['day'] ?? null);
        if ($day === null) {
            return null;
        }
        $dayIndex = $day - 1;

        if ($operation === 'add') {
            if (count($schedule[$dayIndex]) >= 48) {
                return null;
            }

            $occupied = array_fill_keys(array_map(
                static fn (array $event): int => self::timeToMinutes($event['time']),
                $schedule[$dayIndex]
            ), true);

            for ($minute = 15; $minute < 1440; $minute += 15) {
                if (isset($occupied[$minute])) {
                    continue;
                }

                $date = (new DateTimeImmutable('2000-01-03 00:00:00'))->modify('+' . $dayIndex . ' days');
                $date = $date->modify('+' . $minute . ' minutes');
                $time = $date->format('H:i');
                $temperature = self::targetAt($schedule, self::dateAtTime($date, $time));
                $schedule[$dayIndex][] = ['time' => $time, 'temperature' => $temperature];
                usort($schedule[$dayIndex], static fn (array $left, array $right): int => strcmp($left['time'], $right['time']));
                return $schedule;
            }

            return null;
        }

        $index = filter_var($command['index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || !isset($schedule[$dayIndex][$index])) {
            return null;
        }

        if ($operation === 'remove') {
            if ($index === 0) {
                return null;
            }
            array_splice($schedule[$dayIndex], $index, 1);
            return $schedule;
        }

        if ($operation !== 'adjust') {
            return null;
        }

        $field = (string) ($command['field'] ?? '');
        $delta = $command['delta'] ?? null;
        if ((!is_int($delta) && !is_float($delta)) || !is_finite((float) $delta)) {
            return null;
        }

        if ($field === 'temperature') {
            $newTemperature = round((float) $schedule[$dayIndex][$index]['temperature'] + (float) $delta, 1);
            if ($newTemperature < 5.0 || $newTemperature > 35.0) {
                return null;
            }
            $schedule[$dayIndex][$index]['temperature'] = $newTemperature;
            return $schedule;
        }

        if ($field === 'time' && $index > 0) {
            $newMinute = self::timeToMinutes($schedule[$dayIndex][$index]['time']) + (int) $delta;
            if ($newMinute < 15 || $newMinute >= 1440 || $newMinute % 15 !== 0) {
                return null;
            }

            $newTime = sprintf('%02d:%02d', intdiv($newMinute, 60), $newMinute % 60);
            foreach ($schedule[$dayIndex] as $eventIndex => $event) {
                if ($eventIndex !== $index && $event['time'] === $newTime) {
                    return null;
                }
            }

            $schedule[$dayIndex][$index]['time'] = $newTime;
            usort($schedule[$dayIndex], static fn (array $left, array $right): int => strcmp($left['time'], $right['time']));
            return $schedule;
        }

        return null;
    }

    private static function validDay(mixed $day): ?int
    {
        $day = filter_var($day, FILTER_VALIDATE_INT);
        return $day !== false && $day >= 1 && $day <= 7 ? $day : null;
    }

    private static function normalizeTime(mixed $time): ?string
    {
        if (!is_string($time) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $time) !== 1) {
            return null;
        }

        return $time;
    }

    private static function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }

    private static function dateAtTime(DateTimeImmutable $date, string $time): DateTimeImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return $date->setTime($hours, $minutes);
    }
}
