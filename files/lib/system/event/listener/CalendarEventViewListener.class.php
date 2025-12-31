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
 * @version 1.3.2
 */
class CalendarEventViewListener implements IParameterizedEventListener {
    
    private const OBJECT_TYPE_ID = 1002;
    
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        if (!WCF::getUser()->userID) {
            return;
        }
        
        $this->markEventAsRead($eventObj);
    }
    
    protected function markEventAsRead($eventObj) {
        $eventID = null;
        
        // EventPage: eventDate->event->eventID
        if (isset($eventObj->eventDate) && is_object($eventObj->eventDate)) {
            if (isset($eventObj->eventDate->eventID)) {
                $eventID = $eventObj->eventDate->eventID;
            } elseif (isset($eventObj->eventDate->event) && isset($eventObj->eventDate->event->eventID)) {
                $eventID = $eventObj->eventDate->event->eventID;
            } elseif (method_exists($eventObj->eventDate, 'getEvent')) {
                $event = $eventObj->eventDate->getEvent();
                if ($event && isset($event->eventID)) {
                    $eventID = $event->eventID;
                }
            }
        }
        
        // Fallback: event property
        if (!$eventID && isset($eventObj->event)) {
            if (is_object($eventObj->event) && isset($eventObj->event->eventID)) {
                $eventID = $eventObj->event->eventID;
            }
        }
        
        if (!$eventID) {
            return;
        }
        
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