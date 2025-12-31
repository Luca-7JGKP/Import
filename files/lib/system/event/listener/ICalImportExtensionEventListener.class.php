<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;

/**
 * Event-Listener für iCal-Import-Erweiterung und Event-Aktionen.
 * 
 * Reagiert auf:
 * - calendar\data\event\EventAction::finalizeAction (create, update, delete)
 * - calendar\data\event\date\EventDateAction::finalizeAction
 * 
 * Funktionen:
 * - Gelesen/Ungelesen-Status für neue Events
 * - Aktualisierte Events als ungelesen markieren
 * - Import-Logging
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.3.1
 */
class ICalImportExtensionEventListener implements IParameterizedEventListener {
    
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        if ($eventName === 'finalizeAction') {
            $this->handleEventAction($eventObj);
            return;
        }
        
        switch ($eventName) {
            case 'beforeImport':
                $this->handleBeforeImport($eventObj, $parameters);
                break;
            case 'afterImport':
                $this->handleAfterImport($eventObj, $parameters);
                break;
        }
    }
    
    protected function handleEventAction($eventObj) {
        if (!method_exists($eventObj, 'getActionName')) {
            return;
        }
        
        $actionName = $eventObj->getActionName();
        
        switch ($actionName) {
            case 'create':
                $this->onEventCreated($eventObj);
                break;
            case 'update':
                $this->onEventUpdated($eventObj);
                break;
            case 'delete':
                $this->onEventDeleted($eventObj);
                break;
        }
    }
    
    protected function onEventCreated($eventObj) {
        $returnValues = $eventObj->getReturnValues();
        
        if (!isset($returnValues['returnValues']) || !is_object($returnValues['returnValues'])) {
            return;
        }
        
        $event = $returnValues['returnValues'];
        $eventID = $this->getEventID($event);
        
        if (!$eventID) {
            return;
        }
        
        $this->logAction('create', $eventID, $event);
        
        if ($this->shouldAutoMarkPastAsRead()) {
            $startTime = isset($event->startTime) ? $event->startTime : null;
            if ($startTime && $startTime < TIME_NOW) {
                $this->markEventAsReadForCurrentUser($eventID);
            }
        }
    }
    
    protected function onEventUpdated($eventObj) {
        if (!$this->shouldMarkUpdatedAsUnread()) {
            return;
        }
        
        $objects = $eventObj->getObjects();
        
        foreach ($objects as $event) {
            $eventID = $this->getEventID($event);
            if ($eventID) {
                $this->markEventAsUnreadForAll($eventID);
                $this->logAction('update', $eventID, $event);
            }
        }
    }
    
    protected function onEventDeleted($eventObj) {
        $objects = $eventObj->getObjects();
        
        foreach ($objects as $event) {
            $eventID = $this->getEventID($event);
            if ($eventID) {
                $this->deleteVisitRecords($eventID);
                $this->logAction('delete', $eventID, $event);
            }
        }
    }
    
    protected function getEventID($event) {
        if (method_exists($event, 'getObjectID')) {
            return $event->getObjectID();
        }
        if (isset($event->eventID)) {
            return $event->eventID;
        }
        return null;
    }
    
    protected function markEventAsReadForCurrentUser($eventID) {
        $userID = WCF::getUser()->userID;
        if (!$userID) return;
        $this->trackVisit($eventID, $userID, TIME_NOW);
    }
    
    protected function markEventAsUnreadForAll($eventID) {
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            if ($objectTypeID) {
                $sql = "DELETE FROM wcf".WCF_N."_tracked_visit WHERE objectTypeID = ? AND objectID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID]);
            }
        } catch (\Exception $e) {}
        
        try {
            $sql = "DELETE FROM wcf".WCF_N."_calendar_event_visit WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
        } catch (\Exception $e) {}
    }
    
    protected function deleteVisitRecords($eventID) {
        $this->markEventAsUnreadForAll($eventID);
    }
    
    protected function trackVisit($eventID, $userID, $visitTime) {
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            if ($objectTypeID) {
                $sql = "INSERT INTO wcf".WCF_N."_tracked_visit (objectTypeID, objectID, userID, visitTime) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE visitTime = VALUES(visitTime)";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID, $userID, $visitTime]);
                return;
            }
        } catch (\Exception $e) {}
        
        try {
            $sql = "INSERT INTO wcf".WCF_N."_calendar_event_visit (eventID, userID, lastVisitTime) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE lastVisitTime = VALUES(lastVisitTime)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $userID, $visitTime]);
        } catch (\Exception $e) {}
    }
    
    protected function getCalendarEventObjectTypeID() {
        static $objectTypeID = null;
        if ($objectTypeID === null) {
            try {
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType = ? OR objectType LIKE ?";
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
    
    protected function shouldAutoMarkPastAsRead() {
        return defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ') ? (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ : true;
    }
    
    protected function shouldMarkUpdatedAsUnread() {
        return defined('CALENDAR_IMPORT_MARK_UPDATED_UNREAD') ? (bool)CALENDAR_IMPORT_MARK_UPDATED_UNREAD : true;
    }
    
    protected function logAction($action, $eventID, $event) {
        if (!defined('CALENDAR_IMPORT_LOG_LEVEL') || CALENDAR_IMPORT_LOG_LEVEL !== 'debug') return;
        $title = isset($event->subject) ? $event->subject : '';
        $this->log("Event {$action}: ID={$eventID}, Title={$title}");
    }
    
    protected function log($message) {
        try {
            if (class_exists('wcf\system\log\LogHandler')) {
                \wcf\system\log\LogHandler::getInstance()->log('calendar.import', $message);
            }
        } catch (\Exception $e) {}
    }
    
    protected function handleBeforeImport($eventObj, array &$parameters) {
        $this->convertTimezone($eventObj, $parameters);
    }
    
    protected function handleAfterImport($eventObj, array &$parameters) {
        if (isset($parameters['eventID'])) {
            $this->logAction('import', $parameters['eventID'], (object)$parameters);
        }
    }
    
    protected function convertTimezone($eventObj, array &$parameters) {
        if (!defined('CALENDAR_IMPORT_CONVERT_TIMEZONE') || !CALENDAR_IMPORT_CONVERT_TIMEZONE) return;
        if (!isset($parameters['startTime']) || !isset($parameters['endTime'])) return;
        
        $sourceTimezone = $parameters['timezone'] ?? 'UTC';
        $targetTimezone = $this->getTargetTimezone();
        
        try {
            $targetTZ = new \DateTimeZone($targetTimezone);
            if (is_numeric($parameters['startTime'])) {
                $startDate = new \DateTime('@' . $parameters['startTime']);
                $startDate->setTimezone($targetTZ);
                $parameters['startTime'] = $startDate->getTimestamp();
            }
            if (is_numeric($parameters['endTime'])) {
                $endDate = new \DateTime('@' . $parameters['endTime']);
                $endDate->setTimezone($targetTZ);
                $parameters['endTime'] = $endDate->getTimestamp();
            }
        } catch (\Exception $e) {}
    }
    
    protected function getTargetTimezone() {
        $user = WCF::getUser();
        if ($user->userID && $user->timezone) return $user->timezone;
        return date_default_timezone_get() ?: 'UTC';
    }
}