<?php
namespace wcf\system\cronjob;

use calendar\data\event\CalendarEventAction;
use calendar\data\event\CalendarEvent;
use wcf\data\cronjob\Cronjob;
use wcf\data\user\User;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Importiert Events aus einer ICS-URL in den WoltLab-Kalender.
 * Vollautomatische Version ohne manuelle Konfiguration.
 * 
 * Features:
 * - Event-Title-Fallback (Summary → Location → Description → UID)
 * - Event-Thread-Erstellung via WoltLab API
 * - Keine Timezone-Workarounds nötig (korrekte Implementierung)
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 4.1.1
 */
class ICalImportCronjob extends AbstractCronjob
{
    protected $importedCount = 0;
    protected $updatedCount = 0;
    protected $skippedCount = 0;
    protected $errors = [];
    protected $importID = null;
    protected $eventUserID = 1;
    protected $eventUsername = 'System';
    protected $categoryID = null;
    
    public function execute($cronjob = null)
    {
        if ($cronjob instanceof Cronjob) {
            parent::execute($cronjob);
        }
        
        // v4.1: Ensure ALL required tables exist - 100% crash-proof!
        $this->ensureAllTablesExist();
        
        $importData = $this->getImportFromDatabase();
        
        if (!$importData || empty($importData['url'])) {
            $this->log('error', 'Keine Import-Konfiguration gefunden.');
            return;
        }
        
        $icsUrl = $importData['url'];
        $this->importID = $importData['importID'];
        
        $this->categoryID = $importData['categoryID'] ?: $this->getDefaultCategoryID();
        if (!$this->categoryID) {
            $this->log('error', 'Keine gültige Kategorie gefunden.');
            return;
        }
        
        $userID = $importData['userID'] ?: 1;
        $this->loadEventUserById($userID);
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl}");
        
        try {
            $icsContent = $this->fetchIcsContent($icsUrl);
            if (!$icsContent) {
                $this->log('error', 'ICS-Inhalt konnte nicht abgerufen werden');
                return;
            }
            
            $events = $this->parseIcsContent($icsContent);
            $this->log('info', count($events) . ' Events in ICS gefunden');
            
            foreach ($events as $event) {
                if (empty($event['uid'])) {
                    $this->skippedCount++;
                    continue;
                }
                $this->importEvent($event, $this->categoryID);
            }
            
            $this->log('info', "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            $this->updateImportLastRun();
            $this->logImportResult($this->categoryID, count($events));
            
        } catch (\Exception $e) {
            $this->log('error', 'Import-Fehler: ' . $e->getMessage());
        }
    }
    
    /**
     * v4.1: Ensures ALL required database tables exist.
     * Creates tables if missing - 100% crash-proof!
     */
    protected function ensureAllTablesExist()
    {
        // 1. UID-Mapping Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS calendar1_ical_uid_map (
                mapID INT(10) NOT NULL AUTO_INCREMENT,
                eventID INT(10) NOT NULL,
                icalUID VARCHAR(255) NOT NULL,
                importID INT(10) DEFAULT NULL,
                lastUpdated INT(10) NOT NULL DEFAULT 0,
                PRIMARY KEY (mapID),
                UNIQUE KEY icalUID (icalUID),
                KEY eventID (eventID),
                KEY importID (importID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            WCF::getDB()->prepareStatement($sql)->execute();
        } catch (\Exception $e) {}
        
        // 2. Import-Log Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS wcf1_calendar_import_log (
                logID INT(10) NOT NULL AUTO_INCREMENT,
                eventUID VARCHAR(255) NOT NULL DEFAULT '',
                eventID INT(10) DEFAULT NULL,
                action VARCHAR(50) NOT NULL DEFAULT 'import',
                importTime INT(10) NOT NULL DEFAULT 0,
                message TEXT,
                logLevel VARCHAR(20) NOT NULL DEFAULT 'info',
                PRIMARY KEY (logID),
                KEY eventUID (eventUID),
                KEY importTime (importTime)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            WCF::getDB()->prepareStatement($sql)->execute();
        } catch (\Exception $e) {}
        
        // 3. Read-Status Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS wcf1_calendar_event_read_status (
                eventID INT(10) NOT NULL,
                userID INT(10) NOT NULL,
                isRead TINYINT(1) NOT NULL DEFAULT 0,
                lastVisitTime INT(10) NOT NULL DEFAULT 0,
                eventHash VARCHAR(64) NOT NULL DEFAULT '',
                markedReadAutomatically TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (eventID, userID),
                KEY userID (userID),
                KEY isRead (isRead)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            WCF::getDB()->prepareStatement($sql)->execute();
        } catch (\Exception $e) {}
    }
    
    protected function loadEventUserById($userID)
    {
        $userID = (int)$userID;
        if ($userID > 0) {
            try {
                $user = new User($userID);
                if ($user->userID) {
                    $this->eventUserID = $user->userID;
                    $this->eventUsername = $user->username;
                    return;
                }
            } catch (\Exception $e) {}
        }
        try {
            $user = new User(1);
            if ($user->userID) {
                $this->eventUserID = $user->userID;
                $this->eventUsername = $user->username;
            }
        } catch (\Exception $e) {
            $this->eventUserID = 1;
            $this->eventUsername = 'System';
        }
    }
    
    public function runManually()
    {
        $this->execute(null);
    }
    
    protected function getImportFromDatabase()
    {
        try {
            $sql = "SELECT importID, url, categoryID, userID FROM calendar1_event_import WHERE isDisabled = 0 ORDER BY importID ASC LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            return $statement->fetchArray();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function getDefaultCategoryID()
    {
        try {
            $sql = "SELECT c.categoryID FROM wcf" . WCF_N . "_category c 
                    JOIN wcf" . WCF_N . "_object_type ot ON c.objectTypeID = ot.objectTypeID 
                    WHERE ot.objectType = 'com.woltlab.calendar.category' 
                    ORDER BY c.categoryID LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $row = $statement->fetchArray();
            return $row ? (int)$row['categoryID'] : 1;
        } catch (\Exception $e) {
            return 1;
        }
    }
    
    protected function updateImportLastRun()
    {
        if (!$this->importID) return;
        try {
            $sql = "UPDATE calendar1_event_import SET lastRun = ? WHERE importID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $this->importID]);
        } catch (\Exception $e) {}
    }
    
    protected function logImportResult($categoryID, $totalEvents)
    {
        try {
            $sql = "INSERT INTO wcf1_calendar_import_log (eventUID, eventID, action, importTime, message, logLevel) VALUES (?, ?, ?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([
                'IMPORT_SUMMARY', 0, 'import_complete', TIME_NOW,
                "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen (von {$totalEvents})",
                'info'
            ]);
        } catch (\Exception $e) {}
    }
    
    protected function fetchIcsContent($url)
    {
        $context = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'WoltLab Calendar Import/4.1'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false
            ]);
            $content = curl_exec($ch);
            curl_close($ch);
        }
        return $content;
    }
    
    protected function parseIcsContent($content)
    {
        $events = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $currentEvent = null;
        $inEvent = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $currentEvent = ['uid' => '', 'summary' => '', 'description' => '', 'location' => '', 'dtstart' => null, 'dtend' => null, 'allday' => false];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($currentEvent && !empty($currentEvent['uid']) && $currentEvent['dtstart']) {
                    $events[] = $currentEvent;
                }
                $inEvent = false;
                $currentEvent = null;
                continue;
            }
            if (!$inEvent || !$currentEvent || strpos($line, ':') === false) continue;
            
            list($key, $value) = explode(':', $line, 2);
            $keyParts = explode(';', $key);
            $keyName = strtoupper($keyParts[0]);
            
            switch ($keyName) {
                case 'UID': $currentEvent['uid'] = $value; break;
                case 'SUMMARY': $currentEvent['summary'] = $this->unescapeIcsValue($value); break;
                case 'DESCRIPTION': $currentEvent['description'] = $this->unescapeIcsValue($value); break;
                case 'LOCATION': $currentEvent['location'] = $this->unescapeIcsValue($value); break;
                case 'DTSTART':
                    $currentEvent['dtstart'] = $this->parseIcsDate($value, $key);
                    if (strpos($key, 'VALUE=DATE') !== false && strpos($key, 'VALUE=DATE-TIME') === false) {
                        $currentEvent['allday'] = true;
                    }
                    break;
                case 'DTEND': $currentEvent['dtend'] = $this->parseIcsDate($value, $key); break;
            }
        }
        return $events;
    }
    
    protected function parseIcsDate($value, $key)
    {
        $value = preg_replace('/[^0-9TZ]/', '', $value);
        if (strlen($value) === 8) {
            $dt = \DateTime::createFromFormat('Ymd', $value);
            if ($dt) { $dt->setTime(0, 0, 0); return $dt->getTimestamp(); }
        }
        if (preg_match('/(\d{8})T(\d{6})Z?/', $value, $matches)) {
            $dateStr = $matches[1] . 'T' . $matches[2];
            $dt = substr($value, -1) === 'Z' 
                ? \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone('UTC'))
                : \DateTime::createFromFormat('Ymd\THis', $dateStr);
            if ($dt) return $dt->getTimestamp();
        }
        return null;
    }
    
    protected function unescapeIcsValue($value)
    {
        return trim(str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $value));
    }
    
    protected function importEvent($event, $categoryID)
    {
        $existingEventID = $this->findExistingEvent($event['uid']);
        if ($existingEventID) {
            $this->updateEvent($existingEventID, $event, $categoryID);
            $this->updatedCount++;
        } else {
            $this->createEvent($event, $categoryID);
            $this->importedCount++;
        }
    }
    
    protected function findExistingEvent($uid)
    {
        try {
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            if ($row) {
                $sql = "SELECT eventID FROM calendar1_event WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$row['eventID']]);
                if ($statement->fetchArray()) return $row['eventID'];
                $sql = "DELETE FROM calendar1_ical_uid_map WHERE icalUID = ?";
                WCF::getDB()->prepareStatement($sql)->execute([$uid]);
            }
            return null;
        } catch (\Exception $e) { return null; }
    }
    
    protected function saveUidMapping($eventID, $uid)
    {
        try {
            $sql = "INSERT INTO calendar1_ical_uid_map (eventID, icalUID, importID, lastUpdated) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE eventID = VALUES(eventID), lastUpdated = VALUES(lastUpdated)";
            WCF::getDB()->prepareStatement($sql)->execute([$eventID, $uid, $this->importID, TIME_NOW]);
        } catch (\Exception $e) {}
    }
    
    protected function createEvent($event, $categoryID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Try WoltLab API first (for Event-Thread support)
            if (class_exists('calendar\data\event\CalendarEventAction')) {
                try {
                    $action = new CalendarEventAction([], 'create', [
                        'data' => [
                            'categoryID' => $categoryID,
                            'userID' => $this->eventUserID,
                            'username' => $this->eventUsername,
                            'subject' => $eventTitle,
                            'message' => $event['description'] ?: $eventTitle,
                            'time' => TIME_NOW,
                            'enableHtml' => 0,
                            'location' => $event['location'] ?: '',
                            'enableParticipation' => 1,
                            'participationIsPublic' => 1,
                            'maxCompanions' => 99,
                            'participationIsChangeable' => 1,
                            'maxParticipants' => 0,
                            'participationEndTime' => $event['dtstart'],
                            'inviteOnly' => 0
                        ],
                        'eventDateData' => [
                            'startTime' => $event['dtstart'],
                            'endTime' => $endTime,
                            'isFullDay' => $event['allday'] ? 1 : 0,
                            'timezone' => 'Europe/Berlin',
                            'repeatType' => ''
                        ]
                    ]);
                    $result = $action->executeAction();
                    $eventID = $result['returnValues']->eventID;
                    $this->saveUidMapping($eventID, $event['uid']);
                    $this->log('debug', "Event erstellt via API: {$eventTitle}");
                    return;
                } catch (\Exception $apiEx) {
                    $this->log('warning', "API Fallback: " . $apiEx->getMessage());
                }
            }
            
            // SQL Fallback
            $eventDateData = serialize(['startTime' => $event['dtstart'], 'endTime' => $endTime, 'isFullDay' => $event['allday'] ? 1 : 0, 'timezone' => 'Europe/Berlin', 'repeatType' => '']);
            $sql = "INSERT INTO calendar1_event (categoryID, userID, username, subject, message, time, enableHtml, eventDate, location, enableParticipation, participationIsPublic, maxCompanions, participationIsChangeable, maxParticipants, participationEndTime, inviteOnly) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([$categoryID, $this->eventUserID, $this->eventUsername, $eventTitle, $event['description'] ?: $eventTitle, TIME_NOW, 0, $eventDateData, $event['location'] ?: '', 1, 1, 99, 1, 0, $event['dtstart'], 0]);
            $eventID = WCF::getDB()->getInsertID('calendar1_event', 'eventID');
            
            $sql = "INSERT INTO calendar1_event_date (eventID, startTime, endTime, isFullDay) VALUES (?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([$eventID, $event['dtstart'], $endTime, $event['allday'] ? 1 : 0]);
            
            $this->saveUidMapping($eventID, $event['uid']);
        } catch (\Exception $e) {
            $this->log('error', "Fehler: {$eventTitle} - " . $e->getMessage());
            $this->skippedCount++;
        }
    }
    
    protected function updateEvent($eventID, $event, $categoryID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Try WoltLab API first
            if (class_exists('calendar\data\event\CalendarEvent')) {
                try {
                    $calendarEvent = new CalendarEvent($eventID);
                    if ($calendarEvent->eventID) {
                        $action = new CalendarEventAction([$calendarEvent], 'update', [
                            'data' => [
                                'categoryID' => $categoryID,
                                'subject' => $eventTitle,
                                'message' => $event['description'] ?: $eventTitle,
                                'time' => TIME_NOW,
                                'location' => $event['location'] ?: '',
                                'enableParticipation' => 1,
                                'participationIsPublic' => 1,
                                'maxCompanions' => 99,
                                'participationIsChangeable' => 1,
                                'maxParticipants' => 0,
                                'participationEndTime' => $event['dtstart'],
                                'inviteOnly' => 0
                            ],
                            'eventDateData' => [
                                'startTime' => $event['dtstart'],
                                'endTime' => $endTime,
                                'isFullDay' => $event['allday'] ? 1 : 0,
                                'timezone' => 'Europe/Berlin'
                            ]
                        ]);
                        $action->executeAction();
                        $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
                        WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
                        return;
                    }
                } catch (\Exception $apiEx) {}
            }
            
            // SQL Fallback
            $eventDateData = serialize(['startTime' => $event['dtstart'], 'endTime' => $endTime, 'isFullDay' => $event['allday'] ? 1 : 0, 'timezone' => 'Europe/Berlin', 'repeatType' => '']);
            $sql = "UPDATE calendar1_event SET subject = ?, message = ?, eventDate = ?, time = ?, location = ?, categoryID = ?, enableParticipation = ?, participationIsPublic = ?, maxCompanions = ?, participationIsChangeable = ?, maxParticipants = ?, participationEndTime = ?, inviteOnly = ? WHERE eventID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([$eventTitle, $event['description'] ?: $eventTitle, $eventDateData, TIME_NOW, $event['location'] ?: '', $categoryID, 1, 1, 99, 1, 0, $event['dtstart'], 0, $eventID]);
            
            $sql = "UPDATE calendar1_event_date SET startTime = ?, endTime = ?, isFullDay = ? WHERE eventID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([$event['dtstart'], $endTime, $event['allday'] ? 1 : 0, $eventID]);
            
            $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
        } catch (\Exception $e) {
            $this->log('error', "Update-Fehler: " . $e->getMessage());
        }
    }
    
    /**
     * Get event title with fallback logic.
     * Ensures every event has a non-empty title.
     * Fallback order: summary → location → description → UID-based title
     */
    protected function getEventTitle($event)
    {
        // Try summary first
        if (!empty($event['summary']) && trim($event['summary']) !== '') {
            return trim($event['summary']);
        }
        
        // Fallback to location
        if (!empty($event['location']) && trim($event['location']) !== '') {
            return 'Event: ' . trim($event['location']);
        }
        
        // Fallback to description (first 50 chars)
        if (!empty($event['description']) && trim($event['description']) !== '') {
            $desc = trim($event['description']);
            return strlen($desc) > 50 ? substr($desc, 0, 50) . '...' : $desc;
        }
        
        // Last resort: Use UID or generic title
        if (!empty($event['uid'])) {
            return 'Event ' . substr($event['uid'], 0, 20);
        }
        
        return 'Unnamed Event';
    }
    
    protected function log($level, $message)
    {
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        if ($levels[$level] <= 2) {
            error_log("[Calendar Import v4.1] [{$level}] {$message}");
        }
    }
}
