<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
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
 * @version 1.4.1
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
        
        // Hole die Object-Type-ID für Kalender-Events
        $objectTypeID = $this->getCalendarEventObjectTypeID();
        if (!$objectTypeID) {
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
        
        // Hole alle aktiven Benutzer
        $sql = "SELECT userID FROM wcf".WCF_N."_user WHERE banned = 0 AND activationCode = 0";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        
        $userIDs = [];
        while ($row = $statement->fetchArray()) {
            $userIDs[] = $row['userID'];
        }
        
        if (empty($userIDs)) {
            return;
        }
        
        // Markiere alle abgelaufenen Events als gelesen für alle Benutzer
        // Verwende Batch-INSERT für bessere Performance
        $batchSize = 100;
        $values = [];
        $parameters = [];
        
        foreach ($pastEventIDs as $eventID) {
            foreach ($userIDs as $userID) {
                $values[] = "(?, ?, ?, ?)";
                $parameters[] = $objectTypeID;
                $parameters[] = $eventID;
                $parameters[] = $userID;
                $parameters[] = TIME_NOW;
                
                // Insert in Batches von 100
                if (count($values) >= $batchSize) {
                    $this->executeBatchInsert($values, $parameters);
                    $values = [];
                    $parameters = [];
                }
            }
        }
        
        // Verbleibende Werte einfügen
        if (!empty($values)) {
            $this->executeBatchInsert($values, $parameters);
        }
    }
    
    protected function executeBatchInsert($values, $parameters) {
        try {
            $sql = "INSERT IGNORE INTO wcf".WCF_N."_tracked_visit 
                    (objectTypeID, objectID, userID, visitTime) 
                    VALUES " . implode(', ', $values);
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute($parameters);
        } catch (\Exception $e) {
            // Ignoriere Fehler
        }
    }
    
    protected function shouldAutoMarkPastAsRead()
    {
        return defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ') 
            ? (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ 
            : true;
    }
    
    protected function getCalendarEventObjectTypeID()
    {
        try {
            $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type 
                    WHERE objectType = 'com.woltlab.calendar.event'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $row = $statement->fetchArray();
            return $row ? $row['objectTypeID'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}