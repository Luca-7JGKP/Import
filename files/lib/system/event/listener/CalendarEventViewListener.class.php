<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;

/**
 * Event-Listener für Kalender-Event-Ansichten.
 * Markiert Events als gelesen wenn sie angesehen werden.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.3.3
 */
class CalendarEventViewListener implements IParameterizedEventListener {
    
    private const OBJECT_TYPE_ID = 1002;
    
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        if (!WCF::getUser()->userID) {
            return;
        }
        
        $eventID = $this->extractEventID($eventObj);
        
        if ($eventID) {
            $this->markAsRead($eventID);
        }
    }
    
    protected function extractEventID($eventObj) {
        // Methode 1: eventDate als Objekt mit getEvent()
        if (isset($eventObj->eventDate) && is_object($eventObj->eventDate)) {
            if (method_exists($eventObj->eventDate, 'getEvent')) {
                $event = $eventObj->eventDate->getEvent();
                if ($event && method_exists($event, 'getObjectID')) {
                    return $event->getObjectID();
                }
                if ($event && isset($event->eventID)) {
                    return $event->eventID;
                }
            }
            if (method_exists($eventObj->eventDate, 'getObjectID')) {
                // eventDate selbst hat eine eventID
                $reflection = new \ReflectionObject($eventObj->eventDate);
                if ($reflection->hasProperty('eventID')) {
                    $prop = $reflection->getProperty('eventID');
                    $prop->setAccessible(true);
                    return $prop->getValue($eventObj->eventDate);
                }
            }
            if (isset($eventObj->eventDate->eventID)) {
                return $eventObj->eventDate->eventID;
            }
            // Versuche getData()
            if (method_exists($eventObj->eventDate, 'getData')) {
                $data = $eventObj->eventDate->getData();
                if (isset($data['eventID'])) {
                    return $data['eventID'];
                }
            }
            // Versuche __get
            try {
                $eventID = $eventObj->eventDate->eventID;
                if ($eventID) return $eventID;
            } catch (\Exception $e) {}
        }
        
        // Methode 2: event direkt
        if (isset($eventObj->event) && is_object($eventObj->event)) {
            if (method_exists($eventObj->event, 'getObjectID')) {
                return $eventObj->event->getObjectID();
            }
            if (isset($eventObj->event->eventID)) {
                return $eventObj->event->eventID;
            }
        }
        
        // Methode 3: getEvent() auf Page
        if (method_exists($eventObj, 'getEvent')) {
            $event = $eventObj->getEvent();
            if ($event && method_exists($event, 'getObjectID')) {
                return $event->getObjectID();
            }
        }
        
        // Methode 4: eventID direkt auf Page
        if (isset($eventObj->eventID)) {
            return $eventObj->eventID;
        }
        
        // Methode 5: URL-Parameter
        if (isset($_REQUEST['id'])) {
            return intval($_REQUEST['id']);
        }
        
        return null;
    }
    
    protected function markAsRead($eventID) {
        try {
            $sql = "INSERT INTO wcf".WCF_N."_tracked_visit (objectTypeID, objectID, userID, visitTime)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE visitTime = VALUES(visitTime)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([self::OBJECT_TYPE_ID, $eventID, WCF::getUser()->userID, TIME_NOW]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
