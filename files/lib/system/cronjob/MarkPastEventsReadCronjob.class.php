<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\calendar\ReadStatusHandler;
use wcf\system\WCF;

/**
 * Markiert abgelaufene Events als gelesen für alle Benutzer.
 * 
 * Logik:
 * - Events deren startTime in der Vergangenheit liegt werden als gelesen markiert
 * - Zukünftige Events bleiben ungelesen (außer der Benutzer hat sie gelesen)
 * - Aktualisierte Events werden wieder ungelesen (in ICalImportExtensionEventListener)
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.5.0
 */
class MarkPastEventsReadCronjob extends AbstractCronjob
{
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);
        
        // Prüfe ob die Option aktiviert ist
        if (!$this->shouldAutoMarkPastAsRead()) {
            return;
        }
        
        // Hole alle abgelaufenen Events die noch nicht für alle als gelesen markiert sind
        $sql = "SELECT DISTINCT e.eventID, ed.startTime
                FROM calendar1_event e
                JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                WHERE ed.startTime < ?
                AND ed.startTime > ?
                ORDER BY ed.startTime DESC";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        // Nur Events der letzten 30 Tage prüfen (Performance)
        $statement->execute([TIME_NOW, TIME_NOW - (30 * 86400)]);
        
        $pastEventIDs = [];
        while ($row = $statement->fetchArray()) {
            $pastEventIDs[] = $row['eventID'];
        }
        
        if (empty($pastEventIDs)) {
            return;
        }
        
        // Markiere alle abgelaufenen Events als gelesen für alle Benutzer
        foreach ($pastEventIDs as $eventID) {
            ReadStatusHandler::getInstance()->markAsReadForAll($eventID);
        }
    }
    
    protected function shouldAutoMarkPastAsRead()
    {
        return defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ') 
            ? (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ 
            : true;
    }
}
