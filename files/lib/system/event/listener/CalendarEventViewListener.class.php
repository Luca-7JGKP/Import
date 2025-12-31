<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;

/**
 * Event-Listener für Kalender-Event-Ansichten.
 * Markiert Events korrekt als gelesen wenn sie angesehen werden.
 * 
 * FIX #2: Events (auch zukünftige) werden beim Ansehen als gelesen markiert
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 */
class CalendarEventViewListener implements IParameterizedEventListener {
    
    /**
     * @inheritDoc
     */
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        if ($eventName === 'readData' || $eventName === 'show') {
            $this->markEventAsRead($eventObj, $parameters);
        }
    }
    
    /**
     * Markiert Kalender-Event als gelesen für aktuellen Benutzer.
     * Funktioniert für alle Events, auch zukünftige.
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function markEventAsRead($eventObj, array &$parameters) {
        $eventID = $this->getEventID($eventObj, $parameters);
        
        if (!$eventID) {
            return;
        }
        
        $userID = WCF::getUser()->userID;
        if (!$userID) {
            // Gast-Benutzer haben kein Gelesen/Ungelesen-Tracking
            return;
        }
        
        // Aktualisiere oder erstelle Visit-Record mit aktueller Zeit
        // Dies markiert das Event als "gelesen" unabhängig davon ob es in der Zukunft liegt
        $sql = "INSERT INTO wcf".WCF_N."_calendar_event_visit 
                (eventID, userID, lastVisitTime)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE lastVisitTime = ?";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            $eventID,
            $userID,
            TIME_NOW,
            TIME_NOW
        ]);
    }
    
    /**
     * Ermittelt Event-ID aus verschiedenen Quellen.
     * 
     * @param object $eventObj
     * @param array $parameters
     * @return int|null
     */
    protected function getEventID($eventObj, array &$parameters) {
        // Aus Parameters
        if (isset($parameters['eventID'])) {
            return intval($parameters['eventID']);
        }
        
        // Aus Event-Objekt Property
        if (isset($eventObj->eventID)) {
            return intval($eventObj->eventID);
        }
        
        // Aus Event-Objekt Methode
        if (method_exists($eventObj, 'getObjectID')) {
            return intval($eventObj->getObjectID());
        }
        
        // Aus Event-Objekt event Property
        if (isset($eventObj->event) && isset($eventObj->event->eventID)) {
            return intval($eventObj->event->eventID);
        }
        
        return null;
    }
    
    /**
     * Prüft ob Event ungelesen ist für aktuellen Benutzer.
     * 
     * @param int $eventID
     * @param int $eventLastModified Zeitstempel letzter Event-Änderung
     * @return bool
     */
    public static function isEventUnread($eventID, $eventLastModified = 0) {
        $userID = WCF::getUser()->userID;
        if (!$userID) {
            return false;
        }
        
        $sql = "SELECT lastVisitTime 
                FROM wcf".WCF_N."_calendar_event_visit 
                WHERE eventID = ? AND userID = ?";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$eventID, $userID]);
        $row = $statement->fetchArray();
        
        if (!$row) {
            // Kein Visit-Record = ungelesen
            return true;
        }
        
        // Wenn Event nach letztem Besuch geändert wurde, ist es ungelesen
        if ($eventLastModified > 0 && $row['lastVisitTime'] < $eventLastModified) {
            return true;
        }
        
        // Wenn nie besucht (lastVisitTime = 0), ist es ungelesen
        if ($row['lastVisitTime'] == 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Markiert Event als ungelesen (setzt lastVisitTime zurück).
     * 
     * @param int $eventID
     * @param int $userID Optional, Standard: aktueller Benutzer
     */
    public static function markEventAsUnread($eventID, $userID = null) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        
        if (!$userID) {
            return;
        }
        
        $sql = "INSERT INTO wcf".WCF_N."_calendar_event_visit 
                (eventID, userID, lastVisitTime)
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE lastVisitTime = 0";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$eventID, $userID]);
    }
    
    /**
     * Markiert alle Events als gelesen für Benutzer.
     * 
     * @param int $userID Optional, Standard: aktueller Benutzer
     */
    public static function markAllEventsAsRead($userID = null) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        
        if (!$userID) {
            return;
        }
        
        // Hole alle Event-IDs
        $sql = "SELECT eventID FROM wcf".WCF_N."_calendar_event";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        
        while ($row = $statement->fetchArray()) {
            $insertSql = "INSERT INTO wcf".WCF_N."_calendar_event_visit 
                          (eventID, userID, lastVisitTime)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE lastVisitTime = ?";
            
            $insertStatement = WCF::getDB()->prepareStatement($insertSql);
            $insertStatement->execute([
                $row['eventID'],
                $userID,
                TIME_NOW,
                TIME_NOW
            ]);
        }
    }
}
