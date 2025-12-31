<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\visitTracker\VisitTracker;
use wcf\system\WCF;

/**
 * Event-Listener für Kalender-Event-Ansichten.
 * Markiert Events als gelesen wenn sie angesehen werden.
 * 
 * Verwendet das WoltLab-eigene Tracking-System (wcf_tracked_visit)
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.3.1
 */
class CalendarEventViewListener implements IParameterizedEventListener {
    
    /**
     * @inheritDoc
     */
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        // Nur für eingeloggte Benutzer
        if (!WCF::getUser()->userID) {
            return;
        }
        
        switch ($eventName) {
            case 'readData':
            case 'show':
                $this->markEventAsRead($eventObj);
                break;
        }
    }
    
    /**
     * Markiert Kalender-Event als gelesen für aktuellen Benutzer.
     * Verwendet das WoltLab VisitTracker-System.
     * 
     * @param object $eventObj Die Page/Action-Instanz
     */
    protected function markEventAsRead($eventObj) {
        // Event-Objekt aus der Page extrahieren
        $event = null;
        
        // Versuche Event aus verschiedenen Properties zu bekommen
        if (isset($eventObj->event)) {
            $event = $eventObj->event;
        } elseif (isset($eventObj->eventDate) && isset($eventObj->eventDate->event)) {
            $event = $eventObj->eventDate->event;
        } elseif (method_exists($eventObj, 'getEvent')) {
            $event = $eventObj->getEvent();
        }
        
        if ($event === null) {
            return;
        }
        
        // Event-ID ermitteln
        $eventID = null;
        if (is_object($event)) {
            if (method_exists($event, 'getObjectID')) {
                $eventID = $event->getObjectID();
            } elseif (isset($event->eventID)) {
                $eventID = $event->eventID;
            }
        } elseif (is_array($event) && isset($event['eventID'])) {
            $eventID = $event['eventID'];
        }
        
        if (!$eventID) {
            return;
        }
        
        // Prüfe ob Option aktiviert ist
        $autoMarkPast = true;
        if (defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ')) {
            $autoMarkPast = (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ;
        }
        
        // Versuche über WoltLab VisitTracker
        try {
            if (class_exists('wcf\system\visitTracker\VisitTracker')) {
                // Object-Type für Kalender-Events finden
                $objectTypeID = $this->getCalendarEventObjectTypeID();
                
                if ($objectTypeID) {
                    VisitTracker::getInstance()->trackObjectVisit(
                        'com.woltlab.calendar.event',
                        $eventID,
                        TIME_NOW
                    );
                    return;
                }
            }
        } catch (\Exception $e) {
            // Fallback zur direkten Datenbank-Methode
        }
        
        // Fallback: Direkt in tracked_visit Tabelle schreiben
        $this->trackVisitDirectly($eventID);
    }
    
    /**
     * Ermittelt die Object-Type-ID für Kalender-Events.
     * 
     * @return int|null
     */
    protected function getCalendarEventObjectTypeID() {
        static $objectTypeID = null;
        
        if ($objectTypeID === null) {
            try {
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type 
                        WHERE objectType = ? OR objectType LIKE ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute(['com.woltlab.calendar.event', '%calendar%event%']);
                $row = $statement->fetchArray();
                $objectTypeID = $row ? $row['objectTypeID'] : 0;
            } catch (\Exception $e) {
                $objectTypeID = 0;
            }
        }
        
        return $objectTypeID ?: null;
    }
    
    /**
     * Schreibt Visit-Tracking direkt in die Datenbank.
     * Fallback wenn VisitTracker nicht funktioniert.
     * 
     * @param int $eventID
     */
    protected function trackVisitDirectly($eventID) {
        $userID = WCF::getUser()->userID;
        
        // Versuche zuerst die WoltLab tracked_visit Tabelle
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            
            if ($objectTypeID) {
                $sql = "INSERT INTO wcf".WCF_N."_tracked_visit 
                        (objectTypeID, objectID, userID, visitTime)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE visitTime = VALUES(visitTime)";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID, $userID, TIME_NOW]);
                return;
            }
        } catch (\Exception $e) {
            // Tabelle existiert nicht oder anderer Fehler
        }
        
        // Fallback: Eigene Tabelle (für Kompatibilität)
        try {
            $sql = "INSERT INTO wcf".WCF_N."_calendar_event_visit 
                    (eventID, userID, lastVisitTime)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE lastVisitTime = VALUES(lastVisitTime)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $userID, TIME_NOW]);
        } catch (\Exception $e) {
            // Tabelle existiert nicht - ignorieren
        }
    }
    
    /**
     * Prüft ob ein Event als ungelesen gilt.
     * 
     * @param int $eventID
     * @param int $eventLastModified Zeitstempel der letzten Änderung
     * @return bool
     */
    public static function isEventUnread($eventID, $eventLastModified = 0) {
        if (!WCF::getUser()->userID) {
            return false;
        }
        
        // Prüfe Option für aktualisierte Events
        $markUpdatedUnread = true;
        if (defined('CALENDAR_IMPORT_MARK_UPDATED_UNREAD')) {
            $markUpdatedUnread = (bool)CALENDAR_IMPORT_MARK_UPDATED_UNREAD;
        }
        
        try {
            // Zuerst WoltLab tracked_visit prüfen
            $sql = "SELECT visitTime FROM wcf".WCF_N."_tracked_visit 
                    WHERE objectTypeID IN (
                        SELECT objectTypeID FROM wcf".WCF_N."_object_type 
                        WHERE objectType LIKE '%calendar%event%'
                    )
                    AND objectID = ? 
                    AND userID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, WCF::getUser()->userID]);
            $row = $statement->fetchArray();
            
            if ($row) {
                // Event wurde besucht
                if ($markUpdatedUnread && $eventLastModified > 0) {
                    // Wenn Event nach letztem Besuch geändert wurde = ungelesen
                    return $eventLastModified > $row['visitTime'];
                }
                return false; // Gelesen
            }
            
            return true; // Nie besucht = ungelesen
            
        } catch (\Exception $e) {
            return false;
        }
    }
}
