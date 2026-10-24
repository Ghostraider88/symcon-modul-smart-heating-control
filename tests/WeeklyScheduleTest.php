<?php

declare(strict_types=1);

use ModuleSHC\WeeklySchedule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/WeeklySchedule.php';

final class WeeklyScheduleTest extends TestCase
{
    public function testDefaultScheduleHasSevenDaysAndChangesTargetsAtConfiguredTimes(): void
    {
        $schedule = WeeklySchedule::default();
        $timezone = new DateTimeZone('Europe/Berlin');

        $this->assertCount(7, $schedule);
        $this->assertSame(20.0, WeeklySchedule::targetAt($schedule, new DateTimeImmutable('2026-10-26 06:59:00', $timezone)));
        $this->assertSame(24.0, WeeklySchedule::targetAt($schedule, new DateTimeImmutable('2026-10-26 07:00:00', $timezone)));
        $this->assertSame(20.0, WeeklySchedule::targetAt($schedule, new DateTimeImmutable('2026-10-26 09:00:00', $timezone)));
    }

    public function testNextComfortTimeCrossesToTheFollowingDay(): void
    {
        $schedule = WeeklySchedule::default();
        $from = new DateTimeImmutable('2026-10-26 22:00:00', new DateTimeZone('Europe/Berlin'));

        $nextComfort = WeeklySchedule::nextComfort($schedule, $from);

        $this->assertNotNull($nextComfort);
        $this->assertSame('2026-10-27 07:00', $nextComfort['date']->format('Y-m-d H:i'));
        $this->assertSame(24.0, $nextComfort['target']);
    }

    public function testScheduleCanAddAdjustRemoveAndCopyEvents(): void
    {
        $schedule = WeeklySchedule::default();
        $added = WeeklySchedule::applyCommand($schedule, ['op' => 'add', 'day' => 1]);
        $this->assertNotNull($added);
        $this->assertCount(6, $added[0]);

        $adjusted = WeeklySchedule::applyCommand($added, [
            'op'    => 'adjust',
            'day'   => 1,
            'index' => 1,
            'field' => 'temperature',
            'delta' => 0.5,
        ]);
        $this->assertNotNull($adjusted);
        $this->assertSame(20.5, $adjusted[0][1]['temperature']);

        $removed = WeeklySchedule::applyCommand($adjusted, ['op' => 'remove', 'day' => 1, 'index' => 1]);
        $this->assertNotNull($removed);
        $this->assertCount(5, $removed[0]);

        $copied = WeeklySchedule::applyCommand($removed, ['op' => 'copy', 'source' => 1, 'target' => 7]);
        $this->assertSame($removed[0], $copied[6]);
    }

    public function testScheduleRejectsDuplicateTimesAndRemovalOfMidnightAnchor(): void
    {
        $schedule = WeeklySchedule::default();

        $collision = WeeklySchedule::applyCommand($schedule, [
            'op'    => 'adjust',
            'day'   => 1,
            'index' => 2,
            'field' => 'time',
            'delta' => -120,
        ]);
        $removeAnchor = WeeklySchedule::applyCommand($schedule, ['op' => 'remove', 'day' => 1, 'index' => 0]);

        $this->assertNull($collision);
        $this->assertNull($removeAnchor);
    }

    public function testComfortTimeKeepsLocalWallClockAcrossDaylightSavingChange(): void
    {
        $schedule = WeeklySchedule::default();
        $from = new DateTimeImmutable('2026-03-29 18:30:00', new DateTimeZone('Europe/Berlin'));

        $nextComfort = WeeklySchedule::nextComfort($schedule, $from);

        $this->assertNotNull($nextComfort);
        $this->assertSame('2026-03-29 19:00 +02:00', $nextComfort['date']->format('Y-m-d H:i P'));
    }
}
