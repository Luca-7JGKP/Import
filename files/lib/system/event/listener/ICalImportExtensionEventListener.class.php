<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;

/**
 * Event-Listener für iCal-Import-Erweiterung und Event-Aktionen.
 * 
 * Gelesen/Ungelesen-Logik:
 * - Abgelaufene Events werden automatisch als gelesen markiert (via MarkPastEventsReadCronjob)
 * - Neue zukünftige Events sind ungelesen
 * - Wenn ein Event aktualisiert wird, wird es für ALLE wieder ungelesen
 * 
 * Security:
 * - All database queries use parameterized statements for SQL injection protection
 * - Safe error handling without exposing sensitive information
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.5.0
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
    
    /**
     * Neues Event erstellt.
     * Neue Events sind automatisch ungelesen (kein Eintrag in visit-Tabelle).
     * Nur abgelaufene Events werden sofort als gelesen markiert.
     */
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
        
        // Nur abgelaufene Events sofort als gelesen markieren
        // Zukünftige Events bleiben ungelesen
        if ($this->shouldAutoMarkPastAsRead()) {
            $startTime = $this->getEventStartTime($event);
            if ($startTime && $startTime < TIME_NOW) {
                // Abgelaufenes Event - für ALLE Benutzer als gelesen markieren
                $this->markEventAsReadForAllUsers($eventID);
            }
            // Zukünftige Events: Nichts tun, sie sind automatisch ungelesen
        }
    }
    
    /**
     * Event wurde aktualisiert.
     * Wenn aktiviert, wird das Event für ALLE Benutzer wieder ungelesen.
     */
    protected function onEventUpdated($eventObj) {
        if (!$this->shouldMarkUpdatedAsUnread()) {
            return;
        }
        
        $objects = $eventObj->getObjects();
        
        foreach ($objects as $event) {
            $eventID = $this->getEventID($event);
            if ($eventID) {
                // Event für ALLE Benutzer als ungelesen markieren
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
    
    /**
     * Get event start time from various sources.
     * Uses parameterized query for SQL injection protection.
     * 
     * @param object $event Calendar event object
     * @return int|null Start time timestamp or null
     */
    protected function getEventStartTime($event) {
        // Versuche startTime aus verschiedenen Quellen zu holen
        if (isset($event->startTime)) {
            return $event->startTime;
        }
        
        // Fallback: Aus eventDate holen (parameterized query)
        if (isset($event->eventID)) {
            try {
                $sql = "SELECT startTime FROM calendar1_event_date WHERE eventID = ? ORDER BY startTime LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$event->eventID]);
                $row = $statement->fetchArray();
                return $row ? $row['startTime'] : null;
            } catch (\Exception $e) {
                $this->log('Failed to get event start time', [
                    'error' => $e->getMessage(),
                    'eventID' => $event->eventID ?? 'unknown'
                ]);
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Markiert ein Event als gelesen für ALLE aktiven Benutzer.
     * Wird verwendet für abgelaufene Events.
     * Uses parameterized query with INSERT...SELECT for efficiency.
     * 
     * @param int $eventID Event ID to mark as read
     */
    protected function markEventAsReadForAllUsers($eventID) {
        $objectTypeID = $this->getCalendarEventObjectTypeID();
        if (!$objectTypeID) {
            return;
        }
        
        try {
            // Parameterized INSERT...SELECT - SQL injection safe
            $sql = "INSERT IGNORE INTO wcf".WCF_N."_tracked_visit 
                    (objectTypeID, objectID, userID, visitTime)
                    SELECT ?, ?, userID, ?
                    FROM wcf".WCF_N."_user
                    WHERE banned = 0 AND activationCode = 0";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$objectTypeID, $eventID, TIME_NOW]);
            
            $this->log('Marked event as read for all users', [
                'eventID' => $eventID,
                'affectedRows' => $statement->getAffectedRows()
            ]);
        } catch (\Exception $e) {
            $this->log('Failed to mark event as read', [
                'error' => $e->getMessage(),
                'eventID' => $eventID
            ]);
        }
    }
    
    /**
     * Markiert ein Event als ungelesen für ALLE Benutzer.
     * Löscht alle visit-Einträge für dieses Event.
     * Uses parameterized queries for SQL injection protection.
     * 
     * @param int $eventID Event ID to mark as unread
     */
    protected function markEventAsUnreadForAll($eventID) {
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            if ($objectTypeID) {
                // Parameterized query - SQL injection safe
                $sql = "DELETE FROM wcf".WCF_N."_tracked_visit WHERE objectTypeID = ? AND objectID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID]);
                
                $this->log('Marked event as unread for all users', [
                    'eventID' => $eventID,
                    'deletedRows' => $statement->getAffectedRows()
                ]);
            }
        } catch (\Exception $e) {
            $this->log('Failed to mark event as unread', [
                'error' => $e->getMessage(),
                'eventID' => $eventID
            ]);
        }
        
        // Legacy-Tabelle auch bereinigen falls vorhanden
        try {
            // Parameterized query - SQL injection safe
            $sql = "DELETE FROM wcf".WCF_N."_calendar_event_visit WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
        } catch (\Exception $e) {
            // Silently fail if table doesn't exist
        }
    }
    
    protected function deleteVisitRecords($eventID) {
        $this->markEventAsUnreadForAll($eventID);
    }
    
    /**
     * Get calendar event object type ID.
     * Uses parameterized query for SQL injection protection.
     * Caches result for performance.
     * 
     * @return int|null Object type ID or null if not found
     */
    protected function getCalendarEventObjectTypeID() {
        static $objectTypeID = null;
        if ($objectTypeID === null) {
            try {
                // Parameterized query - SQL injection safe
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute(['com.woltlab.calendar.event']);
                $row = $statement->fetchArray();
                $objectTypeID = $row ? $row['objectTypeID'] : 0;
            } catch (\Exception $e) {
                $this->log('Failed to get object type', [
                    'error' => $e->getMessage()
                ]);
                $objectTypeID = 0;
            }
        }
        return $objectTypeID ?: null;
    }
    
    /**
     * Check if auto-marking past events as read is enabled.
     * 
     * @return bool
     */
    protected function shouldAutoMarkPastAsRead() {
        return defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ') ? (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ : true;
    }
    
    /**
     * Check if updated events should be marked as unread.
     * 
     * @return bool
     */
    protected function shouldMarkUpdatedAsUnread() {
        return defined('CALENDAR_IMPORT_MARK_UPDATED_UNREAD') ? (bool)CALENDAR_IMPORT_MARK_UPDATED_UNREAD : true;
    }
    
    /**
     * Log action for debugging purposes.
     * 
     * @param string $action Action name
     * @param int $eventID Event ID
     * @param object $event Event object
     */
    protected function logAction($action, $eventID, $event) {
        if (!defined('CALENDAR_IMPORT_LOG_LEVEL') || CALENDAR_IMPORT_LOG_LEVEL !== 'debug') return;
        $title = isset($event->subject) ? $event->subject : '';
        $this->log("Event {$action}", [
            'eventID' => $eventID,
            'title' => $title
        ]);
    }
    
    /**
     * Log message with optional context.
     * 
     * @param string $message Log message
     * @param array $context Optional context data
     */
    protected function log($message, array $context = []) {
        try {
            $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
            error_log("[CalendarEventListener v1.5] {$message}{$contextStr}");
            
            if (class_exists('wcf\system\log\LogHandler')) {
                \wcf\system\log\LogHandler::getInstance()->log('calendar.import', $message);
            }
        } catch (\Exception $e) {
            // Silently fail to avoid infinite loops
        }
    }
    
    protected function handleBeforeImport($eventObj, array &$parameters) {
        // Timezone handling is now done correctly in ICalImportCronjob
    }
    
    protected function handleAfterImport($eventObj, array &$parameters) {
        if (isset($parameters['eventID'])) {
            $this->logAction('import', $parameters['eventID'], (object)$parameters);
        }
    }
}