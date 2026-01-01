<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Importiert Events aus einer ICS-URL in den WoltLab-Kalender.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 2.0.0
 */
class ICalImportCronjob extends AbstractCronjob
{
    protected $importedCount = 0;
    protected $updatedCount = 0;
    protected $skippedCount = 0;
    protected $errors = [];
    protected $importID = null;
    
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);
        
        // Ensure UID mapping table exists
        $this->ensureUidMapTableExists();
        
        // Get ICS URL from config or from calendar1_event_import
        $icsUrl = $this->getOption('CALENDAR_IMPORT_ICS_URL');
        $categoryID = (int)$this->getOption('CALENDAR_IMPORT_CATEGORY_ID', 0);
        
        // If no URL configured, try to get from calendar1_event_import
        if (empty($icsUrl)) {
            $importData = $this->getImportFromDatabase();
            if ($importData) {
                $icsUrl = $importData['url'];
                $categoryID = $importData['categoryID'] ?: $categoryID;
                $this->importID = $importData['importID'];
            }
        }
        
        if (empty($icsUrl)) {
            $this->log('error', 'Keine ICS-URL konfiguriert');
            return;
        }
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl}");
        
        try {
            $icsContent = $this->fetchIcsContent($icsUrl);
            if (!$icsContent) {
                $this->log('error', 'ICS-Inhalt konnte nicht abgerufen werden');
                return;
            }
            
            $events = $this->parseIcsContent($icsContent);
            $this->log('info', count($events) . ' Events in ICS gefunden');
            
            $maxEvents = (int)$this->getOption('CALENDAR_IMPORT_MAX_EVENTS', 100);
            $events = array_slice($events, 0, $maxEvents);
            
            foreach ($events as $event) {
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
     */
    protected function getImportFromDatabase()
    {
        try {
            $targetImportID = (int)$this->getOption('CALENDAR_IMPORT_TARGET_IMPORT_ID', 0);
            
            if ($targetImportID > 0) {
                $sql = "SELECT importID, url, categoryID, userID FROM calendar1_event_import WHERE importID = ? AND isDisabled = 0";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$targetImportID]);
            } else {
                $sql = "SELECT importID, url, categoryID, userID FROM calendar1_event_import WHERE isDisabled = 0 ORDER BY importID ASC LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
            }
            
            return $statement->fetchArray();
        } catch (\Exception $e) {
            $this->log('error', 'Fehler beim Laden der Import-Konfiguration: ' . $e->getMessage());
            return null;
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
                'user_agent' => 'WoltLab Calendar Import/2.0.0'
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
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/2.0.0'
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
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            // Use categoryID instead of calendarID (calendar1_event has categoryID, not calendarID!)
            $sql = "INSERT INTO calendar1_event 
                    (categoryID, userID, username, subject, message, time, enableHtml, eventDate, location)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $categoryID ?: null,
                WCF::getUser()->userID ?: 1,
                WCF::getUser()->username ?: 'System',
                $event['summary'],
                $event['description'] ?: $event['summary'],
                TIME_NOW,
                0,
                $eventDateData,
                $event['location'] ?: ''
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
            
            // Save UID mapping
            $this->saveUidMapping($eventID, $event['uid']);
            
            $this->log('debug', "Event erstellt: {$event['summary']} (ID: {$eventID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Erstellen: {$event['summary']} - " . $e->getMessage());
            $this->skippedCount++;
        }
    }
    
    protected function updateEvent($eventID, $event, $categoryID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            $sql = "UPDATE calendar1_event 
                    SET subject = ?, message = ?, eventDate = ?, time = ?, location = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['summary'],
                $event['description'] ?: $event['summary'],
                $eventDateData,
                TIME_NOW,
                $event['location'] ?: '',
                $eventID
            ]);
            
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
            
            // Update UID mapping timestamp
            $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([TIME_NOW, $event['uid']]);
            
            if ($this->getOption('CALENDAR_IMPORT_MARK_UPDATED_UNREAD', true)) {
                $this->markEventAsUnread($eventID);
            }
            
            $this->log('debug', "Event aktualisiert: {$event['summary']} (ID: {$eventID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Aktualisieren: {$event['summary']} - " . $e->getMessage());
        }
    }
    
    protected function markEventAsUnread($eventID)
    {
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            if ($objectTypeID) {
                $sql = "DELETE FROM wcf".WCF_N."_tracked_visit WHERE objectTypeID = ? AND objectID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID]);
            }
        } catch (\Exception $e) {}
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
    
    protected function getOption($name, $default = null)
    {
        if (defined($name)) {
            return constant($name);
        }
        return $default;
    }
    
    protected function log($level, $message)
    {
        $configuredLevel = $this->getOption('CALENDAR_IMPORT_LOG_LEVEL', 'info');
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        
        if (!isset($levels[$level]) || !isset($levels[$configuredLevel])) {
            return;
        }
        
        if ($levels[$level] <= $levels[$configuredLevel]) {
            error_log("[Calendar Import] [{$level}] {$message}");
        }
    }
}
