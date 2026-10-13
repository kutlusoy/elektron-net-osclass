<?php

namespace ElektronNet\Escrow;

/**
 * Date math for the reminders: a few days before T1 to the buyer, a few days
 * before T2 to the seller. Delivery is up to whoever listens to the hooks
 * raised by the daily cron.
 */
final class ReminderScheduler
{
    /** @var int[] days-before-threshold offsets to remind at */
    private $reminderOffsetsDays;

    /**
     * @param int[] $reminderOffsetsDays e.g. [5, 1] = remind 5 days and 1 day before
     */
    public function __construct(array $reminderOffsetsDays = [5, 1])
    {
        $this->reminderOffsetsDays = $reminderOffsetsDays;
    }

    /**
     * True if $nowUnixTime falls within the same day as one of the
     * configured offsets before $thresholdUnixTime.
     */
    public function isReminderDue(int $thresholdUnixTime, int $nowUnixTime): bool
    {
        foreach ($this->reminderOffsetsDays as $offsetDays) {
            $reminderDay = $thresholdUnixTime - $offsetDays * 86400;
            if ($nowUnixTime >= $reminderDay && $nowUnixTime < $reminderDay + 86400) {
                return true;
            }
        }

        return false;
    }
}
