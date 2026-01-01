<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\calendar\ReadStatusHandler;
use wcf\system\WCF;

/**
 * Event-Listener für iCal-Import-Erweiterung und Event-Aktionen.
 * 
 * Gelesen/Ungelesen-Logik:
 * - Abgelaufene Events werden automatisch als gelesen markiert (via MarkPastEventsReadCronjob)
 * - Neue zukünftige Events sind ungelesen
 * - Wenn ein Event aktualisiert wird, wird es für ALLE wieder ungelesen
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
    
    protected function getEventStartTime($event) {
        // Versuche startTime aus verschiedenen Quellen zu holen
        if (isset($event->startTime)) {
            return $event->startTime;
        }
        
        // Fallback: Aus eventDate holen
        if (isset($event->eventID)) {
            try {
                $sql = "SELECT startTime FROM calendar1_event_date WHERE eventID = ? ORDER BY startTime LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$event->eventID]);
                $row = $statement->fetchArray();
                return $row ? $row['startTime'] : null;
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Markiert ein Event als gelesen für ALLE aktiven Benutzer.
     * Wird verwendet für abgelaufene Events.
     */
    protected function markEventAsReadForAllUsers($eventID) {
        ReadStatusHandler::getInstance()->markAsReadForAll($eventID);
    }
    
    /**
     * Markiert ein Event als ungelesen für ALLE Benutzer.
     * Löscht alle visit-Einträge für dieses Event.
     */
    protected function markEventAsUnreadForAll($eventID) {
        ReadStatusHandler::getInstance()->markAsUnreadForAll($eventID);
    }
    
    protected function deleteVisitRecords($eventID) {
        ReadStatusHandler::getInstance()->deleteForEvent($eventID);
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
        // Timezone-Konvertierung wird jetzt vom FixTimezoneCronjob übernommen
    }
    
    protected function handleAfterImport($eventObj, array &$parameters) {
        if (isset($parameters['eventID'])) {
            $this->logAction('import', $parameters['eventID'], (object)$parameters);
        }
    }
}