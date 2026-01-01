<?php

namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Korrigiert die Timestamps nach dem ICS-Import.
 * Der WoltLab-Kalender addiert den Timezone-Offset doppelt.
 * Dieser Cronjob subtrahiert ihn wieder.
 */
class FixTimezoneCronjob extends AbstractCronjob
{
    /**
     * @inheritDoc
     */
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);

        $sql = "SELECT e.eventID, e.eventDate, ed.eventDateID, ed.startTime, ed.endTime
                FROM calendar1_event e
                JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                WHERE ed.startTime > ?
                ORDER BY ed.startTime";

        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([\TIME_NOW]);

        $updateSql = "UPDATE calendar1_event_date SET startTime = ?, endTime = ? WHERE eventDateID = ?";
        $updateStatement = WCF::getDB()->prepareStatement($updateSql);

        while ($row = $statement->fetchArray()) {
            $eventDateData = @\unserialize($row['eventDate']);
            if (!$eventDateData) {
                continue;
            }

            $timezone = $eventDateData['timezone'] ?? 'UTC';
            if ($timezone === 'UTC') {
                continue;
            }

            $isFullDay = $eventDateData['isFullDay'] ?? false;
            if ($isFullDay) {
                continue;
            }

            try {
                $tz = new \DateTimeZone($timezone);
                $dt = new \DateTime('@' . $row['startTime']);
                $offset = $tz->getOffset($dt);
            } catch (\Exception $e) {
                continue;
            }

            if ($offset == 0) {
                continue;
            }

            $originalStartTime = $eventDateData['startTime'] ?? 0;
            $dbStartTime = $row['startTime'];

            // Wenn DB-Zeit = Original + Offset, dann wurde der Offset doppelt addiert
            if ($dbStartTime == $originalStartTime + $offset) {
                $newStartTime = $row['startTime'] - $offset;
                $newEndTime = $row['endTime'] - $offset;
                $updateStatement->execute([$newStartTime, $newEndTime, $row['eventDateID']]);
            }
        }
    }
}
