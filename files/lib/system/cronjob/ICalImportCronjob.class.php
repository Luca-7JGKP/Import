<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\data\user\User;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Importiert Events aus einer ICS-URL in den WoltLab-Kalender.
 * Vollautomatische Version ohne manuelle Konfiguration.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 4.0.0
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
    
    /**
     * Execute the import - can be called with Cronjob object or null for manual execution.
     * Vollautomatisch: Holt ALLE Konfiguration aus calendar1_event_import.
     * 
     * @param Cronjob|object|null $cronjob
     */
    public function execute($cronjob = null)
    {
        // Only call parent if we have a real Cronjob object
        if ($cronjob instanceof Cronjob) {
            parent::execute($cronjob);
        }
        
        // Ensure UID mapping table exists
        $this->ensureUidMapTableExists();
        
        // Get ALL configuration from calendar1_event_import table (v4.0: fully automatic!)
        $importData = $this->getImportFromDatabase();
        
        if (!$importData || empty($importData['url'])) {
            $this->log('error', 'Keine Import-Konfiguration gefunden. Bitte einen Import in calendar1_event_import anlegen.');
            return;
        }
        
        $icsUrl = $importData['url'];
        $this->importID = $importData['importID'];
        
        // Get categoryID from import or use fallback
        $categoryID = $importData['categoryID'] ?: $this->getDefaultCategoryID();
        if (!$categoryID) {
            $this->log('error', 'Keine gültige Kategorie gefunden. Bitte categoryID in calendar1_event_import setzen.');
            return;
        }
        
        // Get userID from import or use fallback
        $userID = $importData['userID'] ?: 1;
        $this->loadEventUserById($userID);
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl}");
        $this->log('info', "Kategorie: {$categoryID}");
        $this->log('info', "Event-Ersteller: {$this->eventUsername} (ID: {$this->eventUserID})");
        
        try {
            $icsContent = $this->fetchIcsContent($icsUrl);
            if (!$icsContent) {
                $this->log('error', 'ICS-Inhalt konnte nicht abgerufen werden');
                return;
            }
            
            $events = $this->parseIcsContent($icsContent);
            $this->log('info', count($events) . ' Events in ICS gefunden');
            
            // Import all events (no limit in v4.0 - we want all 63 events!)
            foreach ($events as $event) {
                // Skip events without UID - we need UID for mapping!
                if (empty($event['uid'])) {
                    $this->log('warning', "Event ohne UID übersprungen: {$event['summary']}");
                    $this->skippedCount++;
                    continue;
                }
                $this->importEvent($event, $categoryID);
            }
            
            $this->log('info', "Import abgeschlossen: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            
            // Update lastRun in calendar1_event_import
            $this->updateImportLastRun();
            
            // Log result
            $this->logImportResult($categoryID, count($events));
            
        } catch (\Exception $e) {
            $this->log('error', 'Import-Fehler: ' . $e->getMessage());
        }
    }
    
    /**
     * Loads the configured user for event creation by user ID.
     * 
     * @param int $userID The user ID to load
     */
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
            } catch (\Exception $e) {
                $this->log('warning', "Benutzer mit ID {$userID} nicht gefunden, verwende User ID 1");
            }
        }
        
        // Fallback to user ID 1
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
    
    /**
     * Run import manually (without Cronjob object).
     */
    public function runManually()
    {
        $this->execute(null);
    }
    
    /**
     * Ensures the UID mapping table exists.
     */
    protected function ensureUidMapTableExists()
    {
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
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
        } catch (\Exception $e) {
            // Table might already exist or other error - continue
        }
    }
    
    /**
     * Gets import configuration from calendar1_event_import table.
     * V4.0: This is the ONLY source of configuration now!
     */
    protected function getImportFromDatabase()
    {
        try {
            // Get first active import
            $sql = "SELECT importID, url, categoryID, userID FROM calendar1_event_import WHERE isDisabled = 0 ORDER BY importID ASC LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            
            return $statement->fetchArray();
        } catch (\Exception $e) {
            $this->log('error', 'Fehler beim Laden der Import-Konfiguration: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Gets default categoryID with proper fallbacks.
     * V4.0: Implements the exact fallback logic from requirements.
     * 
     * @return int|null Category ID or null if none found
     */
    protected function getDefaultCategoryID()
    {
        try {
            // Fallback: Get first calendar category from wcf1_category
            $sql = "SELECT c.categoryID 
                    FROM wcf" . WCF_N . "_category c 
                    JOIN wcf" . WCF_N . "_object_type ot ON c.objectTypeID = ot.objectTypeID 
                    WHERE ot.objectType = 'com.woltlab.calendar.category' 
                    ORDER BY c.categoryID 
                    LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $row = $statement->fetchArray();
            if ($row && $row['categoryID']) {
                return (int)$row['categoryID'];
            }
            
            // Last resort fallback - return 1
            return 1;
        } catch (\Exception $e) {
            $this->log('error', 'Fehler beim Ermitteln der Standard-Kategorie: ' . $e->getMessage());
            return 1; // Absolute fallback
        }
    }
    
    /**
     * Updates the lastRun timestamp in calendar1_event_import.
     */
    protected function updateImportLastRun()
    {
        if (!$this->importID) {
            return;
        }
        
        try {
            $sql = "UPDATE calendar1_event_import SET lastRun = ? WHERE importID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([TIME_NOW, $this->importID]);
        } catch (\Exception $e) {
            // Ignore errors
        }
    }
    
    /**
     * Logs import result to database.
     */
    protected function logImportResult($categoryID, $totalEvents)
    {
        try {
            $sql = "INSERT INTO wcf1_calendar_import_log 
                    (eventUID, eventID, action, importTime, message, logLevel)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                'IMPORT_SUMMARY',
                0,
                'import_complete',
                TIME_NOW,
                "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen (von {$totalEvents} Events)",
                'info'
            ]);
        } catch (\Exception $e) {
            // Table might not exist - ignore
        }
    }
    
    protected function fetchIcsContent($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'WoltLab Calendar Import/2.1.0'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/2.1.0'
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
                $currentEvent = [
                    'uid' => '',
                    'summary' => '',
                    'description' => '',
                    'location' => '',
                    'dtstart' => null,
                    'dtend' => null,
                    'allday' => false
                ];
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
            
            if (!$inEvent || !$currentEvent) {
                continue;
            }
            
            if (strpos($line, ':') === false) {
                continue;
            }
            
            list($key, $value) = explode(':', $line, 2);
            $keyParts = explode(';', $key);
            $keyName = strtoupper($keyParts[0]);
            
            switch ($keyName) {
                case 'UID':
                    $currentEvent['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $currentEvent['summary'] = $this->unescapeIcsValue($value);
                    break;
                case 'DESCRIPTION':
                    $currentEvent['description'] = $this->unescapeIcsValue($value);
                    break;
                case 'LOCATION':
                    $currentEvent['location'] = $this->unescapeIcsValue($value);
                    break;
                case 'DTSTART':
                    $currentEvent['dtstart'] = $this->parseIcsDate($value, $key);
                    if (strpos($key, 'VALUE=DATE') !== false && strpos($key, 'VALUE=DATE-TIME') === false) {
                        $currentEvent['allday'] = true;
                    }
                    break;
                case 'DTEND':
                    $currentEvent['dtend'] = $this->parseIcsDate($value, $key);
                    break;
            }
        }
        
        return $events;
    }
    
    protected function parseIcsDate($value, $key)
    {
        $value = preg_replace('/[^0-9TZ]/', '', $value);
        
        if (strlen($value) === 8) {
            $dt = \DateTime::createFromFormat('Ymd', $value);
            if ($dt) {
                $dt->setTime(0, 0, 0);
                return $dt->getTimestamp();
            }
        }
        
        if (preg_match('/(\d{8})T(\d{6})Z?/', $value, $matches)) {
            $dateStr = $matches[1] . 'T' . $matches[2];
            if (substr($value, -1) === 'Z') {
                $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone('UTC'));
            } else {
                $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr);
            }
            if ($dt) {
                return $dt->getTimestamp();
            }
        }
        
        return null;
    }
    
    protected function unescapeIcsValue($value)
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\;', ';', $value);
        $value = str_replace('\\\\', '\\', $value);
        return trim($value);
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
    
    /**
     * Finds existing event by UID using the mapping table.
     */
    protected function findExistingEvent($uid)
    {
        try {
            // Use UID mapping table
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            
            if ($row) {
                // Verify event still exists
                $sql = "SELECT eventID FROM calendar1_event WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$row['eventID']]);
                if ($statement->fetchArray()) {
                    return $row['eventID'];
                } else {
                    // Event was deleted, remove mapping
                    $sql = "DELETE FROM calendar1_ical_uid_map WHERE icalUID = ?";
                    $statement = WCF::getDB()->prepareStatement($sql);
                    $statement->execute([$uid]);
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Saves UID mapping after creating an event.
     */
    protected function saveUidMapping($eventID, $uid)
    {
        try {
            $sql = "INSERT INTO calendar1_ical_uid_map (eventID, icalUID, importID, lastUpdated) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE eventID = VALUES(eventID), lastUpdated = VALUES(lastUpdated)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $uid, $this->importID, TIME_NOW]);
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Speichern des UID-Mappings: " . $e->getMessage());
        }
    }
    
    protected function createEvent($event, $categoryID)
    {
        try {
            // Ensure categoryID is NOT NULL (requirement!)
            if (!$categoryID) {
                $categoryID = $this->getDefaultCategoryID();
            }
            
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            // V4.0: Set all participation settings according to requirements
            $participationEndTime = $event['dtstart']; // Anmeldeschluss = Event-Start
            
            $sql = "INSERT INTO calendar1_event 
                    (categoryID, userID, username, subject, message, time, enableHtml, eventDate, location,
                     enableParticipation, participationIsPublic, maxCompanions, participationIsChangeable,
                     maxParticipants, participationEndTime, inviteOnly)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $categoryID,                      // NICHT NULL!
                $this->eventUserID,
                $this->eventUsername,
                $event['summary'],
                $event['description'] ?: $event['summary'],
                TIME_NOW,                         // time = TIME_NOW → UNGELESEN
                0,
                $eventDateData,
                $event['location'] ?: '',
                1,  // enableParticipation
                1,  // participationIsPublic
                99, // maxCompanions
                1,  // participationIsChangeable
                0,  // maxParticipants (unbegrenzt)
                $participationEndTime,
                0   // inviteOnly
            ]);
            
            $eventID = WCF::getDB()->getInsertID('calendar1_event', 'eventID');
            
            // Insert event date
            $sql = "INSERT INTO calendar1_event_date (eventID, startTime, endTime, isFullDay)
                    VALUES (?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $eventID,
                $event['dtstart'],
                $endTime,
                $event['allday'] ? 1 : 0
            ]);
            
            // V4.0: Save UID mapping for EVERY event to prevent duplicates!
            $this->saveUidMapping($eventID, $event['uid']);
            
            $this->log('debug', "Event erstellt: {$event['summary']} (ID: {$eventID}, UID: {$event['uid']}, Kategorie: {$categoryID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Erstellen: {$event['summary']} - " . $e->getMessage());
            $this->skippedCount++;
        }
    }
    
    protected function updateEvent($eventID, $event, $categoryID)
    {
        try {
            // Ensure categoryID is NOT NULL (requirement!)
            if (!$categoryID) {
                $categoryID = $this->getDefaultCategoryID();
            }
            
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            // V4.0: Update all participation settings on EVERY update
            $participationEndTime = $event['dtstart']; // Anmeldeschluss = Event-Start
            
            $sql = "UPDATE calendar1_event 
                    SET subject = ?, message = ?, eventDate = ?, time = ?, location = ?,
                        categoryID = ?,
                        enableParticipation = ?, participationIsPublic = ?, maxCompanions = ?,
                        participationIsChangeable = ?, maxParticipants = ?, participationEndTime = ?,
                        inviteOnly = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['summary'],
                $event['description'] ?: $event['summary'],
                $eventDateData,
                TIME_NOW,  // V4.0: time = TIME_NOW macht Event UNGELESEN für alle!
                $event['location'] ?: '',
                $categoryID,  // Ensure categoryID is set
                1,  // enableParticipation
                1,  // participationIsPublic
                99, // maxCompanions
                1,  // participationIsChangeable
                0,  // maxParticipants (unbegrenzt)
                $participationEndTime,
                0,  // inviteOnly
                $eventID
            ]);
            
            // Update event date
            $sql = "UPDATE calendar1_event_date 
                    SET startTime = ?, endTime = ?, isFullDay = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['dtstart'],
                $endTime,
                $event['allday'] ? 1 : 0,
                $eventID
            ]);
            
            // V4.0: Update UID mapping timestamp
            $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([TIME_NOW, $event['uid']]);
            
            $this->log('debug', "Event aktualisiert: {$event['summary']} (ID: {$eventID}, UID: {$event['uid']})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Aktualisieren: {$event['summary']} - " . $e->getMessage());
        }
    }
    
    protected function getCalendarEventObjectTypeID()
    {
        static $objectTypeID = null;
        if ($objectTypeID === null) {
            try {
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType = 'com.woltlab.calendar.event'";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $row = $statement->fetchArray();
                $objectTypeID = $row ? $row['objectTypeID'] : 0;
            } catch (\Exception $e) {
                $objectTypeID = 0;
            }
        }
        return $objectTypeID ?: null;
    }
    
    protected function log($level, $message)
    {
        // V4.0: Always log info and above (no configuration needed)
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        $configuredLevel = 'info'; // Default to info level
        
        if (!isset($levels[$level]) || !isset($levels[$configuredLevel])) {
            return;
        }
        
        if ($levels[$level] <= $levels[$configuredLevel]) {
            error_log("[Calendar Import v4.0] [{$level}] {$message}");
        }
    }
}
