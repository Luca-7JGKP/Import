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
 * - Enhanced event deduplication (UID + property-based fallback matching)
 * - Auto-migration of events without UID mappings
 * - Updates expired events instead of creating duplicates
 * - Event-Title-Fallback (Summary → Location → Description → UID)
 * - Event-Thread-Erstellung via WoltLab API
 * - Configurable timezone support with fallback to server timezone
 * - UID-based duplicate prevention with UNIQUE constraint validation
 * - Comprehensive error logging and debugging
 * - SQL injection protection via parameterized queries
 * - WoltLab API integration with SQL fallback
 * 
 * Security:
 * - All database queries use parameterized statements
 * - Input validation for all external data
 * - Safe error handling without exposing sensitive information
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 4.3.1
 */
class ICalImportCronjob extends AbstractCronjob
{
    /**
     * Time window in seconds for property-based event matching (±5 minutes)
     */
    const PROPERTY_MATCH_TIME_WINDOW = 300;
    
    /**
     * Maximum characters from title to use in LIKE pattern matching
     */
    const PROPERTY_MATCH_TITLE_LENGTH = 50;
    
    /**
     * Maximum hours before event start for participation deadline (1 week = 168 hours)
     */
    const MAX_PARTICIPATION_HOURS_BEFORE = 168;
    
    protected $importedCount = 0;
    protected $updatedCount = 0;
    protected $skippedCount = 0;
    protected $errors = [];
    protected $importID = null;
    protected $eventUserID = 1;
    protected $eventUsername = 'System';
    protected $categoryID = null;
    protected $timezone = 'Europe/Berlin'; // Default timezone, can be overridden
    
    public function execute($cronjob = null)
    {
        if ($cronjob instanceof Cronjob) {
            parent::execute($cronjob);
        }
        
        // v4.2: Ensure ALL required tables exist - 100% crash-proof!
        $this->ensureAllTablesExist();
        
        $importData = $this->getImportFromDatabase();
        
        if (!$importData || empty($importData['url'])) {
            $this->log('error', 'Keine Import-Konfiguration gefunden.', [
                'context' => 'execute',
                'table_check' => $this->checkImportTableExists()
            ]);
            return;
        }
        
        $icsUrl = $importData['url'];
        $this->importID = $importData['importID'];
        
        // Load timezone configuration (configurable, defaults to Europe/Berlin)
        $this->timezone = $this->getConfiguredTimezone();
        $this->log('debug', "Using timezone: {$this->timezone}");
        
        $this->categoryID = $importData['categoryID'] ?: $this->getDefaultCategoryID();
        if (!$this->categoryID) {
            $this->log('error', 'Keine gültige Kategorie gefunden.', [
                'importID' => $this->importID,
                'categoryID' => $importData['categoryID']
            ]);
            return;
        }
        
        $userID = $importData['userID'] ?: 1;
        $this->loadEventUserById($userID);
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl}", [
            'importID' => $this->importID,
            'categoryID' => $this->categoryID,
            'userID' => $this->eventUserID,
            'timezone' => $this->timezone
        ]);
        
        try {
            $icsContent = $this->fetchIcsContent($icsUrl);
            if (!$icsContent) {
                $this->log('error', 'ICS-Inhalt konnte nicht abgerufen werden', [
                    'url' => $icsUrl,
                    'importID' => $this->importID
                ]);
                return;
            }
            
            $events = $this->parseIcsContent($icsContent);
            $this->log('info', count($events) . ' Events in ICS gefunden');
            
            // Check all events for updates or duplicates
            $this->validateEventsForDuplicates($events);
            
            foreach ($events as $event) {
                if (empty($event['uid'])) {
                    $this->log('warning', 'Event ohne UID übersprungen', [
                        'summary' => $event['summary'] ?? 'N/A'
                    ]);
                    $this->skippedCount++;
                    continue;
                }
                $this->importEvent($event, $this->categoryID);
            }
            
            $this->log('info', "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            $this->updateImportLastRun();
            $this->logImportResult($this->categoryID, count($events));
            
        } catch (\Exception $e) {
            $this->log('error', 'Import-Fehler: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * v4.2: Ensures ALL required database tables exist.
     * Creates tables if missing - 100% crash-proof!
     * All queries use parameterized statements for SQL injection protection.
     */
    protected function ensureAllTablesExist()
    {
        // 1. UID-Mapping Tabelle (UNIQUE constraint prevents duplicates)
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
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create UID mapping table: ' . $e->getMessage());
        }
        
        // 2. Import-Log Tabelle (for comprehensive debugging)
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
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create import log table: ' . $e->getMessage());
        }
        
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
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create read status table: ' . $e->getMessage());
        }
    }
    
    /**
     * Get configured timezone or fall back to server timezone.
     * Configurable via CALENDAR_IMPORT_TIMEZONE constant or uses Europe/Berlin as default.
     * 
     * @return string Valid timezone identifier
     */
    protected function getConfiguredTimezone()
    {
        // Check for configured timezone
        if (defined('CALENDAR_IMPORT_TIMEZONE')) {
            $timezone = CALENDAR_IMPORT_TIMEZONE;
            // Validate timezone
            try {
                new \DateTimeZone($timezone);
                return $timezone;
            } catch (\Exception $e) {
                $this->log('warning', "Invalid timezone configured: {$timezone}, falling back to default");
            }
        }
        
        // Fall back to server timezone or default
        $serverTimezone = @date_default_timezone_get();
        if ($serverTimezone && $serverTimezone !== 'UTC') {
            return $serverTimezone;
        }
        
        return 'Europe/Berlin'; // Final fallback
    }
    
    /**
     * Check if calendar_event_import table exists.
     * 
     * @return bool
     */
    protected function checkImportTableExists()
    {
        try {
            $sql = "SHOW TABLES LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['calendar1_event_import']);
            return $statement->fetchArray() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate events for potential duplicates before import.
     * Logs warnings for events that may cause issues.
     * 
     * @param array $events Parsed events from ICS
     */
    protected function validateEventsForDuplicates(array $events)
    {
        $uids = [];
        $duplicateUids = [];
        
        foreach ($events as $event) {
            if (empty($event['uid'])) {
                continue;
            }
            
            $uid = $event['uid'];
            if (isset($uids[$uid])) {
                $duplicateUids[] = $uid;
            } else {
                $uids[$uid] = true;
            }
        }
        
        if (!empty($duplicateUids)) {
            $this->log('warning', 'Duplicate UIDs found in ICS file', [
                'count' => count($duplicateUids),
                'uids' => implode(', ', array_slice($duplicateUids, 0, 5))
            ]);
        }
        
        $this->log('debug', 'Validated ' . count($events) . ' events for duplicates');
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
    
    /**
     * Retrieve import configuration from database.
     * Uses parameterized query for SQL injection protection.
     * 
     * @return array|null Import configuration or null if not found
     */
    protected function getImportFromDatabase()
    {
        try {
            // Parameterized query - SQL injection safe
            $sql = "SELECT importID, url, categoryID, userID FROM calendar1_event_import WHERE isDisabled = 0 ORDER BY importID ASC LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $result = $statement->fetchArray();
            
            if ($result) {
                $this->log('debug', 'Import configuration loaded', [
                    'importID' => $result['importID'],
                    'url' => substr($result['url'], 0, 50) . '...'
                ]);
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to load import configuration: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get default category ID for calendar events.
     * Uses parameterized query for SQL injection protection.
     * 
     * @return int|null Category ID or null if not found
     */
    protected function getDefaultCategoryID()
    {
        try {
            // Parameterized query with WCF_N placeholder
            $sql = "SELECT c.categoryID FROM wcf" . WCF_N . "_category c 
                    JOIN wcf" . WCF_N . "_object_type ot ON c.objectTypeID = ot.objectTypeID 
                    WHERE ot.objectType = 'com.woltlab.calendar.category' 
                    ORDER BY c.categoryID LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $row = $statement->fetchArray();
            
            $categoryID = $row ? (int)$row['categoryID'] : 1;
            
            $this->log('debug', 'Default category resolved', [
                'categoryID' => $categoryID
            ]);
            
            return $categoryID;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to get default category: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Update last run timestamp for import.
     * Uses parameterized query for SQL injection protection.
     */
    protected function updateImportLastRun()
    {
        if (!$this->importID) return;
        try {
            // Parameterized query - SQL injection safe
            $sql = "UPDATE calendar1_event_import SET lastRun = ? WHERE importID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $this->importID]);
            
            $this->log('debug', 'Import lastRun timestamp updated');
        } catch (\Exception $e) {
            $this->log('error', 'Failed to update lastRun: ' . $e->getMessage());
        }
    }
    
    /**
     * Log import result summary to database.
     * Uses parameterized query for SQL injection protection.
     * 
     * @param int $categoryID Category where events were imported
     * @param int $totalEvents Total events found in ICS
     */
    protected function logImportResult($categoryID, $totalEvents)
    {
        try {
            // Parameterized query - SQL injection safe
            $sql = "INSERT INTO wcf1_calendar_import_log (eventUID, eventID, action, importTime, message, logLevel) VALUES (?, ?, ?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([
                'IMPORT_SUMMARY', 0, 'import_complete', TIME_NOW,
                "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen (von {$totalEvents})",
                'info'
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to log import result: ' . $e->getMessage());
        }
    }
    
    protected function fetchIcsContent($url)
    {
        $context = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'WoltLab Calendar Import/4.3.1'],
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
    
    /**
     * Parse iCal date/time value to Unix timestamp.
     * Handles UTC times (with 'Z' suffix) and local times (without 'Z').
     * For local times, uses the configured timezone to avoid DST/offset issues.
     * 
     * @param string $value iCal date/time value
     * @param string $key Full iCal property line (may contain TZID parameter)
     * @return int|null Unix timestamp or null if parsing fails
     */
    protected function parseIcsDate($value, $key)
    {
        $value = preg_replace('/[^0-9TZ]/', '', $value);
        
        // All-day event (DATE format: YYYYMMDD)
        if (strlen($value) === 8) {
            try {
                $dt = \DateTime::createFromFormat('Ymd', $value, new \DateTimeZone($this->timezone));
                if ($dt) { 
                    $dt->setTime(0, 0, 0); 
                    return $dt->getTimestamp(); 
                }
            } catch (\Exception $e) {
                $this->log('warning', 'Failed to parse all-day date', [
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Date-time format (YYYYMMDDTHHMMSS with optional 'Z')
        if (preg_match('/(\d{8})T(\d{6})Z?/', $value, $matches)) {
            $dateStr = $matches[1] . 'T' . $matches[2];
            
            try {
                // If ends with 'Z', it's UTC time
                if (substr($value, -1) === 'Z') {
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone('UTC'));
                    if ($dt) {
                        return $dt->getTimestamp();
                    }
                } else {
                    // No 'Z' means local time - use configured timezone
                    // This fixes the 1-hour offset issue by explicitly using the configured timezone
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone($this->timezone));
                    if ($dt) {
                        return $dt->getTimestamp();
                    }
                }
            } catch (\Exception $e) {
                $this->log('warning', 'Failed to parse date-time', [
                    'value' => $value,
                    'timezone' => $this->timezone,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return null;
    }
    
    protected function unescapeIcsValue($value)
    {
        return trim(str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $value));
    }
    
    protected function importEvent($event, $categoryID)
    {
        $existingEventID = $this->findExistingEvent($event['uid'], $event);
        if ($existingEventID) {
            $this->updateEvent($existingEventID, $event, $categoryID);
            $this->updatedCount++;
        } else {
            $this->createEvent($event, $categoryID);
            $this->importedCount++;
        }
    }
    
    /**
     * Find existing event by iCal UID with enhanced deduplication.
     * 
     * Strategy:
     * 1. Primary: Match by UID in UID mapping table (UNIQUE constraint prevents duplicates)
     * 2. Secondary: If no UID match, try to find event by properties (startTime + location/title)
     *    This handles events imported before UID mapping or when UID changes in ICS feed
     * 3. If found via properties, create UID mapping for future updates
     * 
     * Uses parameterized queries for SQL injection protection.
     * 
     * @param string $uid iCal UID
     * @param array $event Event data for fallback matching
     * @return int|null Event ID if found, null otherwise
     */
    protected function findExistingEvent($uid, $event = [])
    {
        try {
            // PRIMARY: Try UID mapping first
            // Parameterized query - SQL injection safe
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            
            if ($row) {
                // Validate event still exists
                $sql = "SELECT eventID FROM calendar1_event WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$row['eventID']]);
                
                if ($statement->fetchArray()) {
                    $this->log('debug', 'Existing event found by UID', [
                        'uid' => substr($uid, 0, 30),
                        'eventID' => $row['eventID']
                    ]);
                    return $row['eventID'];
                }
                
                // Event was deleted, clean up orphaned mapping
                $this->log('warning', 'Orphaned UID mapping found, cleaning up', [
                    'uid' => substr($uid, 0, 30),
                    'eventID' => $row['eventID']
                ]);
                $sql = "DELETE FROM calendar1_ical_uid_map WHERE icalUID = ?";
                WCF::getDB()->prepareStatement($sql)->execute([$uid]);
            }
            
            // SECONDARY: Try to find by event properties (for events without UID mapping)
            // This handles:
            // - Events imported before UID system was implemented
            // - ICS feeds that change UIDs when event details change
            // - Prevents duplicates when re-importing existing events
            $eventID = $this->findEventByProperties($event);
            if ($eventID) {
                // Found existing event without UID mapping - create mapping now
                $this->log('info', 'Found existing event by properties, creating UID mapping', [
                    'uid' => substr($uid, 0, 30),
                    'eventID' => $eventID,
                    'startTime' => $event['dtstart'] ?? 'unknown'
                ]);
                $this->saveUidMapping($eventID, $uid);
                return $eventID;
            }
            
            return null;
        } catch (\Exception $e) {
            $this->log('error', 'Error finding existing event: ' . $e->getMessage(), [
                'uid' => substr($uid, 0, 30)
            ]);
            return null;
        }
    }
    
    /**
     * Find event by properties when UID is not found in mapping.
     * Matches events based on startTime and location/title similarity.
     * This prevents duplicates for events imported before UID system
     * or when ICS feed changes UIDs.
     * 
     * Uses parameterized queries for SQL injection protection.
     * 
     * @param array $event Event data with dtstart, location, summary
     * @return int|null Event ID if found, null otherwise
     */
    protected function findEventByProperties($event)
    {
        // Validate required data
        if (empty($event['dtstart']) || !is_numeric($event['dtstart'])) {
            return null;
        }
        
        // Validate categoryID is set (required for event matching)
        // Use explicit check to allow categoryID 0 if valid
        if ($this->categoryID === null || $this->categoryID === '') {
            $this->log('error', 'Cannot match by properties: categoryID not set');
            return null;
        }
        
        try {
            // Get event title using same fallback logic as import
            $eventTitle = $this->getEventTitle($event);
            if (empty($eventTitle) || !is_string($eventTitle)) {
                $this->log('warning', 'Cannot match by properties: event has no valid title');
                return null;
            }
            
            $location = $event['location'] ?? '';
            $startTime = (int)$event['dtstart'];
            
            // Time window for matching (configurable via class constant)
            $timeWindowStart = $startTime - self::PROPERTY_MATCH_TIME_WINDOW;
            $timeWindowEnd = $startTime + self::PROPERTY_MATCH_TIME_WINDOW;
            
            // Strategy 1: Match by exact startTime and location (most reliable for sports events)
            if (!empty($location)) {
                $sql = "SELECT e.eventID, e.subject, e.location, ed.startTime
                        FROM calendar1_event e
                        JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                        LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
                        WHERE ed.startTime BETWEEN ? AND ?
                        AND e.location = ?
                        AND m.mapID IS NULL
                        AND e.categoryID = ?
                        LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$timeWindowStart, $timeWindowEnd, $location, $this->categoryID]);
                $row = $statement->fetchArray();
                
                if ($row) {
                    $this->log('debug', 'Event found by startTime + location', [
                        'eventID' => $row['eventID'],
                        'startTime' => $startTime,
                        'location' => substr($location, 0, 30)
                    ]);
                    return $row['eventID'];
                }
            }
            
            // Strategy 2: Match by startTime and title similarity (fallback)
            // Use LIKE for partial title matching to handle title changes
            $titleForPattern = substr($eventTitle, 0, self::PROPERTY_MATCH_TITLE_LENGTH);
            $titlePattern = $this->escapeLikePattern($titleForPattern);
            
            $sql = "SELECT e.eventID, e.subject, ed.startTime
                    FROM calendar1_event e
                    JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                    LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
                    WHERE ed.startTime BETWEEN ? AND ?
                    AND e.subject LIKE ?
                    AND m.mapID IS NULL
                    AND e.categoryID = ?
                    LIMIT 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$timeWindowStart, $timeWindowEnd, $titlePattern, $this->categoryID]);
            $row = $statement->fetchArray();
            
            if ($row) {
                $this->log('debug', 'Event found by startTime + title similarity', [
                    'eventID' => $row['eventID'],
                    'startTime' => $startTime,
                    'titlePattern' => substr($titlePattern, 0, 30)
                ]);
                return $row['eventID'];
            }
            
            return null;
        } catch (\Exception $e) {
            $this->log('error', 'Error finding event by properties: ' . $e->getMessage(), [
                'startTime' => $event['dtstart'] ?? 'unknown',
                'trace' => substr($e->getTraceAsString(), 0, 200)
            ]);
            return null;
        }
    }
    
    /**
     * Escape string for use in SQL LIKE pattern.
     * Escapes backslashes and LIKE wildcards (%, _) to prevent SQL injection
     * and ensure literal character matching.
     * 
     * @param string $input Input string to escape
     * @param bool $wrapWildcard Whether to wrap result with % wildcards (default: true)
     * @return string Escaped string, optionally wrapped with % for LIKE query
     */
    protected function escapeLikePattern($input, $wrapWildcard = true)
    {
        // Escape backslashes first, then LIKE wildcards
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);
        return $wrapWildcard ? '%' . $escaped . '%' : $escaped;
    }
    
    /**
     * Save UID to Event ID mapping.
     * Uses ON DUPLICATE KEY UPDATE to handle race conditions.
     * UNIQUE constraint on icalUID ensures no duplicates.
     * Uses parameterized query for SQL injection protection.
     * 
     * @param int $eventID WoltLab event ID
     * @param string $uid iCal UID
     */
    protected function saveUidMapping($eventID, $uid)
    {
        try {
            // Parameterized query with ON DUPLICATE KEY - SQL injection safe
            $sql = "INSERT INTO calendar1_ical_uid_map (eventID, icalUID, importID, lastUpdated) 
                    VALUES (?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE eventID = VALUES(eventID), lastUpdated = VALUES(lastUpdated)";
            WCF::getDB()->prepareStatement($sql)->execute([$eventID, $uid, $this->importID, TIME_NOW]);
            
            $this->log('debug', 'UID mapping saved', [
                'eventID' => $eventID,
                'uid' => substr($uid, 0, 30)
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to save UID mapping: ' . $e->getMessage(), [
                'eventID' => $eventID,
                'uid' => substr($uid, 0, 30)
            ]);
        }
    }
    
    /**
     * Create new event from iCal data.
     * Prefers WoltLab API (CalendarEventAction) with SQL fallback.
     * Uses parameterized queries for SQL injection protection.
     * Implements title fallback logic (summary → location → description → UID).
     * 
     * @param array $event Parsed iCal event data
     * @param int $categoryID Target category ID
     */
    protected function createEvent($event, $categoryID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Calculate participation end time (registration deadline)
            // By default, registration closes when the event starts
            // This can be configured to close earlier if needed
            $participationEndTime = $this->calculateParticipationEndTime($event['dtstart']);
            
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
                            'participationEndTime' => $participationEndTime,
                            'inviteOnly' => 0
                        ],
                        'eventDateData' => [
                            'startTime' => $event['dtstart'],
                            'endTime' => $endTime,
                            'isFullDay' => $event['allday'] ? 1 : 0,
                            'timezone' => $this->timezone, // Use configurable timezone
                            'repeatType' => ''
                        ]
                    ]);
                    $result = $action->executeAction();
                    $eventID = $result['returnValues']->eventID;
                    $this->saveUidMapping($eventID, $event['uid']);
                    $this->log('debug', "Event erstellt via API: {$eventTitle}", [
                        'eventID' => $eventID,
                        'uid' => substr($event['uid'], 0, 30)
                    ]);
                    return;
                } catch (\Exception $apiEx) {
                    $this->log('warning', "API Fallback aktiviert: " . $apiEx->getMessage());
                }
            }
            
            // SQL Fallback - uses parameterized queries for security
            $eventDateData = serialize([
                'startTime' => $event['dtstart'], 
                'endTime' => $endTime, 
                'isFullDay' => $event['allday'] ? 1 : 0, 
                'timezone' => $this->timezone, // Use configurable timezone
                'repeatType' => ''
            ]);
            
            // Parameterized query - SQL injection safe
            $sql = "INSERT INTO calendar1_event (categoryID, userID, username, subject, message, time, enableHtml, eventDate, location, enableParticipation, participationIsPublic, maxCompanions, participationIsChangeable, maxParticipants, participationEndTime, inviteOnly) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([
                $categoryID, 
                $this->eventUserID, 
                $this->eventUsername, 
                $eventTitle, 
                $event['description'] ?: $eventTitle, 
                TIME_NOW, 
                0, 
                $eventDateData, 
                $event['location'] ?: '', 
                1, 1, 99, 1, 0, 
                $participationEndTime, 
                0
            ]);
            $eventID = WCF::getDB()->getInsertID('calendar1_event', 'eventID');
            
            // Parameterized query - SQL injection safe
            $sql = "INSERT INTO calendar1_event_date (eventID, startTime, endTime, isFullDay) VALUES (?, ?, ?, ?)";
            WCF::getDB()->prepareStatement($sql)->execute([
                $eventID, 
                $event['dtstart'], 
                $endTime, 
                $event['allday'] ? 1 : 0
            ]);
            
            $this->saveUidMapping($eventID, $event['uid']);
            
            $this->log('debug', "Event erstellt via SQL: {$eventTitle}", [
                'eventID' => $eventID,
                'uid' => substr($event['uid'], 0, 30)
            ]);
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Erstellen: {$eventTitle} - " . $e->getMessage(), [
                'uid' => substr($event['uid'] ?? 'unknown', 0, 30),
                'exception' => get_class($e)
            ]);
            $this->skippedCount++;
        }
    }
    
    /**
     * Update existing event with iCal data.
     * Prefers WoltLab API (CalendarEventAction) with SQL fallback.
     * Uses parameterized queries for SQL injection protection.
     * Implements title fallback logic (summary → location → description → UID).
     * 
     * @param int $eventID Existing event ID
     * @param array $event Parsed iCal event data
     * @param int $categoryID Target category ID
     */
    protected function updateEvent($eventID, $event, $categoryID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Calculate participation end time (registration deadline)
            $participationEndTime = $this->calculateParticipationEndTime($event['dtstart']);
            
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
                                'participationEndTime' => $participationEndTime,
                                'inviteOnly' => 0
                            ],
                            'eventDateData' => [
                                'startTime' => $event['dtstart'],
                                'endTime' => $endTime,
                                'isFullDay' => $event['allday'] ? 1 : 0,
                                'timezone' => $this->timezone // Use configurable timezone
                            ]
                        ]);
                        $action->executeAction();
                        
                        // Parameterized query - SQL injection safe
                        $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
                        WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
                        
                        $this->log('debug', "Event aktualisiert via API: {$eventTitle}", [
                            'eventID' => $eventID,
                            'uid' => substr($event['uid'], 0, 30)
                        ]);
                        return;
                    }
                } catch (\Exception $apiEx) {
                    $this->log('warning', "API Update-Fallback aktiviert: " . $apiEx->getMessage());
                }
            }
            
            // SQL Fallback - uses parameterized queries for security
            $eventDateData = serialize([
                'startTime' => $event['dtstart'], 
                'endTime' => $endTime, 
                'isFullDay' => $event['allday'] ? 1 : 0, 
                'timezone' => $this->timezone, // Use configurable timezone
                'repeatType' => ''
            ]);
            
            // Parameterized query - SQL injection safe
            $sql = "UPDATE calendar1_event SET subject = ?, message = ?, eventDate = ?, time = ?, location = ?, categoryID = ?, enableParticipation = ?, participationIsPublic = ?, maxCompanions = ?, participationIsChangeable = ?, maxParticipants = ?, participationEndTime = ?, inviteOnly = ? WHERE eventID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([
                $eventTitle, 
                $event['description'] ?: $eventTitle, 
                $eventDateData, 
                TIME_NOW, 
                $event['location'] ?: '', 
                $categoryID, 
                1, 1, 99, 1, 0, 
                $participationEndTime, 
                0, 
                $eventID
            ]);
            
            // Parameterized query - SQL injection safe
            $sql = "UPDATE calendar1_event_date SET startTime = ?, endTime = ?, isFullDay = ? WHERE eventID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([
                $event['dtstart'], 
                $endTime, 
                $event['allday'] ? 1 : 0, 
                $eventID
            ]);
            
            // Parameterized query - SQL injection safe
            $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
            
            $this->log('debug', "Event aktualisiert via SQL: {$eventTitle}", [
                'eventID' => $eventID,
                'uid' => substr($event['uid'], 0, 30)
            ]);
        } catch (\Exception $e) {
            $this->log('error', "Update-Fehler: " . $e->getMessage(), [
                'eventID' => $eventID,
                'uid' => substr($event['uid'] ?? 'unknown', 0, 30),
                'exception' => get_class($e)
            ]);
        }
    }
    
    /**
     * Get event title with fallback logic.
     * Ensures every event has a non-empty title.
     * Fallback order: summary → location → description → UID-based title
     * 
     * @param array $event Event data array
     * @return string Non-empty event title (never null or empty)
     */
    protected function getEventTitle($event)
    {
        // Try summary first
        if (!empty($event['summary'])) {
            $summary = trim($event['summary']);
            if ($summary !== '') {
                return $summary;
            }
        }
        
        // Fallback to location
        if (!empty($event['location'])) {
            $location = trim($event['location']);
            if ($location !== '') {
                return 'Event: ' . $location;
            }
        }
        
        // Fallback to description (first 50 chars)
        if (!empty($event['description'])) {
            $desc = trim($event['description']);
            if ($desc !== '') {
                return strlen($desc) > 50 ? substr($desc, 0, 50) . '...' : $desc;
            }
        }
        
        // Last resort: Use UID or generic title
        if (!empty($event['uid'])) {
            $uid = trim($event['uid']);
            if ($uid !== '') {
                return 'Event ' . substr($uid, 0, 20);
            }
        }
        
        // Absolute last resort - should never happen but ensures non-null return
        return 'Unnamed Event';
    }
    
    /**
     * Calculate participation end time (registration deadline) for an event.
     * By default, registration closes when the event starts.
     * Can be configured to close earlier using CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE constant.
     * 
     * @param int $eventStartTime Event start timestamp
     * @return int Participation end time timestamp
     */
    protected function calculateParticipationEndTime($eventStartTime)
    {
        // Check if custom hours before event start is configured
        if (defined('CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE')) {
            $configValue = CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE;
            
            // Validate that the constant value is numeric
            if (!is_numeric($configValue)) {
                $this->log('warning', 'CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE is not numeric, using default', [
                    'configured' => $configValue
                ]);
                return $eventStartTime;
            }
            
            $hoursBefore = (int)$configValue;
            
            // Validate configuration: must be positive and within reasonable range (1-168 hours = 1 week)
            if ($hoursBefore > 0 && $hoursBefore <= self::MAX_PARTICIPATION_HOURS_BEFORE) {
                $calculatedEndTime = $eventStartTime - ($hoursBefore * 3600);
                
                // Ensure participation end time is not in the past
                if ($calculatedEndTime < TIME_NOW) {
                    $this->log('warning', 'Participation end time would be in the past, using event start time instead', [
                        'eventStartTime' => date('Y-m-d H:i:s', $eventStartTime),
                        'calculatedEndTime' => date('Y-m-d H:i:s', $calculatedEndTime),
                        'hoursBefore' => $hoursBefore
                    ]);
                    return $eventStartTime;
                }
                
                return $calculatedEndTime;
            } else {
                // Invalid value (0, negative, or exceeds maximum)
                $this->log('warning', 'CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE is invalid (must be 1-168), using default', [
                    'configured' => $hoursBefore,
                    'max' => self::MAX_PARTICIPATION_HOURS_BEFORE
                ]);
            }
        }
        
        // Default: Registration closes when event starts
        return $eventStartTime;
    }
    
    /**
     * Enhanced logging with structured context support.
     * Supports multiple log levels and optional context data.
     * 
     * @param string $level Log level (error, warning, info, debug)
     * @param string $message Log message
     * @param array $context Optional context data for debugging
     */
    protected function log($level, $message, array $context = [])
    {
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        $defaultLogLevel = 2; // info level
        $errorWarningThreshold = 1; // log to database for error and warning
        
        // Validate log level
        if (!isset($levels[$level])) {
            return;
        }
        
        $currentLevel = defined('CALENDAR_IMPORT_LOG_LEVEL') ? CALENDAR_IMPORT_LOG_LEVEL : 'info';
        $currentLevelNum = $levels[$currentLevel] ?? $defaultLogLevel;
        
        if ($levels[$level] <= $currentLevelNum) {
            $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
            $logMessage = "[Calendar Import v4.3.1] [{$level}] {$message}{$contextStr}";
            error_log($logMessage);
            
            // Also log to database for persistent debugging
            if ($levels[$level] <= $errorWarningThreshold) {
                try {
                    $sql = "INSERT INTO wcf1_calendar_import_log (eventUID, eventID, action, importTime, message, logLevel) VALUES (?, ?, ?, ?, ?, ?)";
                    WCF::getDB()->prepareStatement($sql)->execute([
                        'SYSTEM', 
                        0, 
                        'log', 
                        TIME_NOW,
                        $message . $contextStr,
                        $level
                    ]);
                } catch (\Exception $e) {
                    // Silently fail to avoid infinite loops
                }
            }
        }
    }
}
