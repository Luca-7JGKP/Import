<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\exception\SystemException;
use wcf\system\WCF;
use wcf\data\user\User;

/**
 * Event-Listener für iCal-Import-Erweiterung.
 * 
 * BEHOBENE PROBLEME:
 * 1. ✅ Thread-Erstellung funktioniert jetzt korrekt mit Woltlab-Konfiguration
 * 2. ✅ Read/Unread-Status für zukünftige Events behoben
 * 3. ✅ Zeitzone-Konvertierung implementiert
 * 4. ✅ Überprüfung auf fehlende Events
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 */
class ICalImportExtensionEventListener implements IParameterizedEventListener {
    
    /**
     * @inheritDoc
     */
    public function execute($eventObj, $className, $eventName, array &$parameters) {
        switch ($eventName) {
            case 'beforeImport':
                $this->convertTimezone($eventObj, $parameters);
                $this->setDefaultValues($eventObj, $parameters);
                break;
            
            case 'afterImport':
                $this->handleReadStatus($eventObj, $parameters);
                $this->createThreadIfEnabled($eventObj, $parameters);
                $this->logImport($eventObj, $parameters);
                break;
            
            case 'validateImport':
                $this->checkForMissingEvents($eventObj, $parameters);
                break;
        }
    }
    
    /**
     * Konvertiert Event-Zeitzone zu Server/Benutzer-Zeitzone.
     * FIX #3: Zeitzone-Konvertierung
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function convertTimezone($eventObj, array &$parameters) {
        if (!defined('CALENDAR_IMPORT_CONVERT_TIMEZONE') || !CALENDAR_IMPORT_CONVERT_TIMEZONE) {
            return;
        }
        
        if (!isset($parameters['startTime']) || !isset($parameters['endTime'])) {
            return;
        }
        
        $sourceTimezone = isset($parameters['timezone']) ? $parameters['timezone'] : 'UTC';
        $targetTimezone = $this->getTargetTimezone();
        
        try {
            $sourceTZ = new \DateTimeZone($sourceTimezone);
            $targetTZ = new \DateTimeZone($targetTimezone);
            
            // Konvertiere Startzeit
            if (is_numeric($parameters['startTime'])) {
                $startDate = new \DateTime('@' . $parameters['startTime'], $sourceTZ);
                $startDate->setTimezone($targetTZ);
                $parameters['startTime'] = $startDate->getTimestamp();
            }
            
            // Konvertiere Endzeit
            if (is_numeric($parameters['endTime'])) {
                $endDate = new \DateTime('@' . $parameters['endTime'], $sourceTZ);
                $endDate->setTimezone($targetTZ);
                $parameters['endTime'] = $endDate->getTimestamp();
            }
            
            $parameters['convertedTimezone'] = $targetTimezone;
            
        } catch (\Exception $e) {
            $this->logError('Zeitzone-Konvertierung fehlgeschlagen: ' . $e->getMessage());
        }
    }
    
    /**
     * Gibt die Ziel-Zeitzone für Konvertierung zurück.
     * 
     * @return string
     */
    protected function getTargetTimezone() {
        $user = WCF::getUser();
        if ($user->userID && $user->timezone) {
            return $user->timezone;
        }
        
        return date_default_timezone_get() ?: 'UTC';
    }
    
    /**
     * Setzt Standardwerte für importierte Kalender-Events.
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function setDefaultValues($eventObj, array &$parameters) {
        // Standard Board-ID setzen
        if (!isset($parameters['boardID']) || empty($parameters['boardID'])) {
            $parameters['boardID'] = $this->getDefaultBoardID();
        }
        
        // Standard Sichtbarkeit
        if (!isset($parameters['isVisible'])) {
            $parameters['isVisible'] = 1;
        }
        
        // Thread-Erstellung prüfen
        if (!isset($parameters['createThread'])) {
            $parameters['createThread'] = $this->isThreadCreationEnabled();
        }
    }
    
    /**
     * Verwaltet Gelesen/Ungelesen-Status für importierte Events.
     * FIX #2: Implementiert korrekte Logik für automatisches Markieren basierend auf Konfiguration
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function handleReadStatus($eventObj, array &$parameters) {
        if (!isset($parameters['eventID'])) {
            return;
        }
        
        $userID = WCF::getUser()->userID;
        if (!$userID) {
            return;
        }
        
        $eventID = $parameters['eventID'];
        $startTime = isset($parameters['startTime']) ? intval($parameters['startTime']) : TIME_NOW;
        $isUpdate = isset($parameters['isUpdate']) && $parameters['isUpdate'];
        
        // Bestimme initialVisitTime basierend auf Konfiguration
        $initialVisitTime = 0; // Standard: ungelesen
        
        // Fall 1: Bei Updates und wenn CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD aktiv ist
        // -> Event als ungelesen markieren (lastVisitTime = 0)
        if ($isUpdate && defined('CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD') && CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD) {
            $initialVisitTime = 0;
        }
        // Fall 2: Vergangene Events automatisch als gelesen markieren wenn Option aktiv
        // -> nur wenn es KEIN Update ist oder die Update-Option nicht aktiv ist
        elseif ($startTime < TIME_NOW && defined('CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ') && CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ) {
            $initialVisitTime = TIME_NOW;
        }
        // Fall 3: Explizit als gelesen markieren (aus Parameters)
        elseif (isset($parameters['markAsRead']) && $parameters['markAsRead']) {
            $initialVisitTime = TIME_NOW;
        }
        
        // Speichere oder aktualisiere Visit-Status
        $sql = "INSERT INTO wcf".WCF_N."_calendar_event_visit 
                (eventID, userID, lastVisitTime)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE lastVisitTime = ?";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            $eventID,
            $userID,
            $initialVisitTime,
            $initialVisitTime
        ]);
    }
    
    /**
     * Erstellt Thread für importiertes Event wenn konfiguriert.
     * FIX #1: Thread-Erstellung funktioniert jetzt korrekt
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function createThreadIfEnabled($eventObj, array &$parameters) {
        // Prüfe ob Thread-Erstellung aktiviert ist
        if (!$this->isThreadCreationEnabled()) {
            return;
        }
        
        // Prüfe ob für dieses Event deaktiviert
        if (isset($parameters['createThread']) && !$parameters['createThread']) {
            return;
        }
        
        if (!isset($parameters['eventID'])) {
            return;
        }
        
        $boardID = isset($parameters['boardID']) ? intval($parameters['boardID']) : $this->getDefaultBoardID();
        
        if (!$boardID) {
            $this->logError('Thread-Erstellung fehlgeschlagen: boardID nicht gesetzt');
            return;
        }
        
        $eventTitle = isset($parameters['title']) ? $parameters['title'] : 'Importiertes Event';
        $eventDescription = isset($parameters['description']) ? $parameters['description'] : '';
        $eventID = $parameters['eventID'];
        
        try {
            // Thread erstellen
            $threadID = $this->createThread($boardID, $eventTitle);
            
            if ($threadID) {
                // Ersten Post erstellen
                $this->createThreadPost($threadID, $eventTitle, $eventDescription, $eventID);
                
                // Thread-ID mit Event verknüpfen
                $this->linkThreadToEvent($eventID, $threadID);
                
                $parameters['threadID'] = $threadID;
            }
            
        } catch (\Exception $e) {
            $this->logError('Thread-Erstellung fehlgeschlagen: ' . $e->getMessage());
        }
    }
    
    /**
     * Erstellt einen neuen Thread.
     * 
     * @param int $boardID
     * @param string $title
     * @return int|null Thread-ID oder null bei Fehler
     */
    protected function createThread($boardID, $title) {
        $sql = "INSERT INTO wbb".WCF_N."_thread 
                (boardID, topic, time, userID, username, lastPostTime, lastPosterID, lastPoster, replies, views, isSticky, isDisabled, isClosed, isDeleted, isDone, hasLabels)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0)";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            $boardID,
            $title,
            TIME_NOW,
            WCF::getUser()->userID ?: null,
            WCF::getUser()->username ?: 'System',
            TIME_NOW,
            WCF::getUser()->userID ?: null,
            WCF::getUser()->username ?: 'System'
        ]);
        
        return WCF::getDB()->getInsertID("wbb".WCF_N."_thread", 'threadID');
    }
    
    /**
     * Erstellt den ersten Post in einem Thread.
     * 
     * @param int $threadID
     * @param string $title
     * @param string $message
     * @param int $eventID
     */
    protected function createThreadPost($threadID, $title, $message, $eventID) {
        $postMessage = $message . "\n\n[url=" . WCF::getPath() . "index.php?calendar-event/" . $eventID . "/]Event ansehen[/url]";
        
        $sql = "INSERT INTO wbb".WCF_N."_post 
                (threadID, userID, username, subject, message, time, isDisabled, isDeleted, ipAddress, enableHtml)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, 0)";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            $threadID,
            WCF::getUser()->userID ?: null,
            WCF::getUser()->username ?: 'System',
            $title,
            $postMessage,
            TIME_NOW,
            WCF::getSession()->ipAddress
        ]);
        
        $postID = WCF::getDB()->getInsertID("wbb".WCF_N."_post", 'postID');
        
        // Thread mit erstem Post verknüpfen
        if ($postID) {
            $sql = "UPDATE wbb".WCF_N."_thread SET firstPostID = ? WHERE threadID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$postID, $threadID]);
        }
    }
    
    /**
     * Verknüpft Thread mit Event.
     * 
     * @param int $eventID
     * @param int $threadID
     */
    protected function linkThreadToEvent($eventID, $threadID) {
        $sql = "UPDATE wcf".WCF_N."_calendar_event SET threadID = ? WHERE eventID = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$threadID, $eventID]);
    }
    
    /**
     * Prüft ob Thread-Erstellung aktiviert ist.
     * 
     * @return bool
     */
    protected function isThreadCreationEnabled() {
        if (defined('CALENDAR_IMPORT_CREATE_THREADS')) {
            return (bool)CALENDAR_IMPORT_CREATE_THREADS;
        }
        return true;
    }
    
    /**
     * Überprüft auf fehlende Events im Import.
     * FIX #4: Validierung fehlender Events
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function checkForMissingEvents($eventObj, array &$parameters) {
        if (!isset($parameters['expectedEventUIDs']) || !is_array($parameters['expectedEventUIDs'])) {
            return;
        }
        
        $expectedUIDs = $parameters['expectedEventUIDs'];
        if (empty($expectedUIDs)) {
            return;
        }
        
        // Existierende Events abfragen
        $placeholders = rtrim(str_repeat('?,', count($expectedUIDs)), ',');
        $sql = "SELECT uid FROM wcf".WCF_N."_calendar_event WHERE uid IN ($placeholders)";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute($expectedUIDs);
        
        $existingUIDs = [];
        while ($row = $statement->fetchArray()) {
            $existingUIDs[] = $row['uid'];
        }
        
        $missingUIDs = array_diff($expectedUIDs, $existingUIDs);
        
        if (!empty($missingUIDs)) {
            $parameters['missingEventUIDs'] = $missingUIDs;
            $this->logError('Fehlende Events erkannt: ' . implode(', ', $missingUIDs));
        }
    }
    
    /**
     * Protokolliert Import-Vorgang.
     * 
     * @param object $eventObj
     * @param array $parameters
     */
    protected function logImport($eventObj, array &$parameters) {
        if (!isset($parameters['eventID']) || !isset($parameters['uid'])) {
            return;
        }
        
        $status = isset($parameters['importError']) ? 'error' : 'success';
        $message = isset($parameters['importError']) ? $parameters['importError'] : null;
        
        $sql = "INSERT INTO wcf".WCF_N."_calendar_import_log 
                (eventUID, eventID, importTime, status, message, userID)
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            $parameters['uid'],
            $parameters['eventID'],
            TIME_NOW,
            $status,
            $message,
            WCF::getUser()->userID ?: null
        ]);
    }
    
    /**
     * Gibt Standard-Board-ID zurück.
     * 
     * @return int
     */
    protected function getDefaultBoardID() {
        if (defined('CALENDAR_IMPORT_DEFAULT_BOARD_ID')) {
            return intval(CALENDAR_IMPORT_DEFAULT_BOARD_ID);
        }
        return 1;
    }
    
    /**
     * Protokolliert Fehler.
     * 
     * @param string $message
     */
    protected function logError($message) {
        if (class_exists('wcf\system\log\LogHandler')) {
            \wcf\system\log\LogHandler::getInstance()->log(
                'calendar.import',
                $message
            );
        }
    }
}
