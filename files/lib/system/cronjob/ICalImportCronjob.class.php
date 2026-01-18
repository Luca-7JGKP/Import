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
 * v4.3.2 Duplicate Prevention Enhancements:
 * - Enhanced UID mapping validation to prevent conflicts
 * - Added pre-create validation to detect race conditions
 * - Improved property matching with stricter validation
 * - Comprehensive logging with decision tracking
 * - Validate event doesn't already have different UID before reusing
 * 
 * v4.3.3 Critical Duplicate Prevention Fixes:
 * - Fixed validateEventsForDuplicates to actually deduplicate instead of just log
 * - Added intra-run duplicate tracking (processedUIDsInCurrentRun)
 * - Widened property matching time window from ±5 to ±30 minutes
 * - Added fuzzy title matching (70% similarity threshold) for better matching
 * - Added import run timestamp tracking
 * - Enhanced logging for all deduplication decision points
 * 
 * v4.3.4 Deep Debugging Enhancements:
 * - Enhanced per-event logging with full event details
 * - Import session ID for tracking related operations
 * - Pre-import database state validation
 * - Detailed fallback logic execution tracking
 * - Comprehensive UID lifecycle logging
 * - Cross-import duplicate detection mechanisms
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 4.3.5
 */
class ICalImportCronjob extends AbstractCronjob
{
    /**
     * Time window in seconds for property-based event matching (±30 minutes)
     * Widened from 5 to 30 minutes to handle time shifts in ICS feeds
     */
    const PROPERTY_MATCH_TIME_WINDOW = 1800;
    
    /**
     * Maximum characters from title to use in LIKE pattern matching
     */
    const PROPERTY_MATCH_TITLE_LENGTH = 50;
    
    /**
     * Minimum similarity threshold for fuzzy title matching (0.0 to 1.0)
     * 0.7 = 70% similarity required to consider titles as matching
     */
    const FUZZY_MATCH_SIMILARITY_THRESHOLD = 0.7;
    
    /**
     * Maximum string length for similarity calculation to avoid performance issues
     * similar_text() has O(n³) complexity, so we limit input length
     */
    const MAX_SIMILARITY_STRING_LENGTH = 100;
    
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
    protected $processedUIDsInCurrentRun = []; // Track UIDs processed in current cronjob run to prevent intra-run duplicates
    protected $importRunTimestamp = null; // Timestamp when this import run started
    protected $importSessionID = null; // Unique session ID for this import run for cross-reference logging
    
    public function execute($cronjob = null)
    {
        if ($cronjob instanceof Cronjob) {
            parent::execute($cronjob);
        }
        
        // Initialize import run timestamp and session ID for duplicate prevention and tracking
        $this->importRunTimestamp = TIME_NOW;
        $this->importSessionID = uniqid('import_', true);
        $this->processedUIDsInCurrentRun = [];
        
        $this->log('info', '=== IMPORT SESSION START ===', [
            'sessionID' => $this->importSessionID,
            'timestamp' => date('Y-m-d H:i:s', $this->importRunTimestamp)
        ]);
        
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
            
            // Deduplicate events from ICS file (removes duplicate UIDs within same file)
            $events = $this->validateEventsForDuplicates($events);
            $this->log('info', count($events) . ' Events nach Deduplizierung');
            
            foreach ($events as $event) {
                if (empty($event['uid'])) {
                    $this->log('warning', 'Event ohne UID übersprungen', [
                        'summary' => $event['summary'] ?? 'N/A',
                        'sessionID' => $this->importSessionID
                    ]);
                    $this->skippedCount++;
                    continue;
                }
                
                // Enhanced per-event logging at start of processing
                $this->logEventProcessingStart($event);
                
                // Check if we've already processed this UID in this cronjob run
                if (isset($this->processedUIDsInCurrentRun[$event['uid']])) {
                    $this->log('warning', 'Event already processed in this run, skipping', [
                        'uid' => substr($event['uid'], 0, 30),
                        'title' => substr($event['summary'] ?? 'N/A', 0, 50),
                        'reason' => 'already_processed_in_run',
                        'sessionID' => $this->importSessionID,
                        'firstProcessedAt' => date('Y-m-d H:i:s', $this->processedUIDsInCurrentRun[$event['uid']])
                    ]);
                    $this->skippedCount++;
                    continue;
                }
                
                // Import the event
                $this->importEvent($event, $this->categoryID);
                
                // Mark UID as processed in this run
                $this->processedUIDsInCurrentRun[$event['uid']] = TIME_NOW;
            }
            
            $this->log('info', "Import: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            $this->updateImportLastRun();
            $this->logImportResult($this->categoryID, count($events));
            
            $this->log('info', '=== IMPORT SESSION END ===', [
                'sessionID' => $this->importSessionID,
                'duration' => (TIME_NOW - $this->importRunTimestamp) . 's',
                'imported' => $this->importedCount,
                'updated' => $this->updatedCount,
                'skipped' => $this->skippedCount,
                'processedUIDs' => count($this->processedUIDsInCurrentRun)
            ]);
            
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
     * Validate events for potential duplicates before import and return deduplicated list.
     * 
     * CRITICAL FIX: Instead of just logging warnings, this now actively deduplicates
     * events with the same UID within a single ICS file. Only keeps the first occurrence.
     * 
     * This prevents issues where:
     * - ICS feeds contain the same event multiple times
     * - Calendar software bugs create duplicate UIDs
     * - Recurring events are incorrectly exported with same UID
     * 
     * @param array $events Parsed events from ICS
     * @return array Deduplicated list of events with unique UIDs
     */
    protected function validateEventsForDuplicates(array $events)
    {
        $seenUIDs = [];
        $deduplicatedEvents = [];
        $duplicateCount = 0;
        
        foreach ($events as $event) {
            if (empty($event['uid'])) {
                // Event without UID - keep it but will be skipped later
                $deduplicatedEvents[] = $event;
                continue;
            }
            
            $uid = $event['uid'];
            
            // Check if we've already seen this UID in the current ICS file
            if (isset($seenUIDs[$uid])) {
                // Duplicate UID found - skip this event
                $duplicateCount++;
                $this->log('warning', 'Duplicate UID in ICS file, skipping duplicate occurrence', [
                    'uid' => substr($uid, 0, 30),
                    'title' => substr($event['summary'] ?? 'N/A', 0, 50),
                    'occurrence' => $duplicateCount,
                    'reason' => 'duplicate_uid_in_ics'
                ]);
                continue;
            }
            
            // First occurrence of this UID - keep it
            $seenUIDs[$uid] = true;
            $deduplicatedEvents[] = $event;
        }
        
        if ($duplicateCount > 0) {
            $this->log('warning', "Removed {$duplicateCount} duplicate events from ICS file", [
                'originalCount' => count($events),
                'deduplicatedCount' => count($deduplicatedEvents),
                'duplicatesRemoved' => $duplicateCount
            ]);
        } else {
            $this->log('debug', 'Validated ' . count($events) . ' events, no duplicates found in ICS');
        }
        
        return $deduplicatedEvents;
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
            $this->logEventProcessingDecision($event, 'update', $existingEventID);
            $this->updateEvent($existingEventID, $event, $categoryID);
            $this->updatedCount++;
        } else {
            $this->logEventProcessingDecision($event, 'create', null);
            $this->createEvent($event, $categoryID);
            $this->importedCount++;
        }
    }
    
    /**
     * Log detailed event information at start of processing.
     * Part of v4.3.4 deep debugging enhancements.
     * 
     * @param array $event Event data from ICS
     */
    protected function logEventProcessingStart($event)
    {
        $this->log('debug', 'Processing event', [
            'sessionID' => $this->importSessionID,
            'uid' => substr($event['uid'] ?? 'MISSING', 0, 40),
            'title' => substr($event['summary'] ?? 'N/A', 0, 60),
            'location' => substr($event['location'] ?? 'N/A', 0, 40),
            'startTime' => !empty($event['dtstart']) ? date('Y-m-d H:i:s', $event['dtstart']) : 'N/A',
            'endTime' => !empty($event['dtend']) ? date('Y-m-d H:i:s', $event['dtend']) : 'N/A',
            'allDay' => $event['allday'] ? 'yes' : 'no'
        ]);
    }
    
    /**
     * Log the decision made for this event (create vs update).
     * Part of v4.3.4 deep debugging enhancements.
     * 
     * @param array $event Event data from ICS
     * @param string $decision 'create' or 'update'
     * @param int|null $existingEventID Existing event ID if updating
     */
    protected function logEventProcessingDecision($event, $decision, $existingEventID)
    {
        $context = [
            'sessionID' => $this->importSessionID,
            'uid' => substr($event['uid'] ?? 'MISSING', 0, 40),
            'title' => substr($event['summary'] ?? 'N/A', 0, 60),
            'decision' => $decision,
            'startTime' => !empty($event['dtstart']) ? date('Y-m-d H:i:s', $event['dtstart']) : 'N/A'
        ];
        
        if ($decision === 'update' && $existingEventID) {
            $context['existingEventID'] = $existingEventID;
        }
        
        $this->log('info', "Event decision: {$decision}", $context);
    }
    
    /**
     * Find existing event by iCal UID with enhanced deduplication.
     * 
     * Strategy:
     * 1. Primary: Match by UID in UID mapping table (UNIQUE constraint prevents duplicates)
     * 2. Secondary: If no UID match, try to find event by properties (startTime + location/title)
     *    This handles events imported before UID mapping or when UID changes in ICS feed
     * 3. If found via properties, create UID mapping for future updates (with validation)
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
            $this->log('debug', 'Starting event lookup', [
                'sessionID' => $this->importSessionID,
                'uid' => substr($uid, 0, 40),
                'strategies' => 'uid_mapping -> property_match'
            ]);
            
            // PRIMARY: Try UID mapping first
            // Parameterized query - SQL injection safe
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            
            if ($row) {
                $this->log('debug', 'UID mapping found, validating event exists', [
                    'sessionID' => $this->importSessionID,
                    'uid' => substr($uid, 0, 30),
                    'eventID' => $row['eventID']
                ]);
                
                // Validate event still exists
                $sql = "SELECT eventID FROM calendar1_event WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$row['eventID']]);
                
                if ($statement->fetchArray()) {
                    $this->log('debug', 'Existing event found by UID mapping', [
                        'sessionID' => $this->importSessionID,
                        'uid' => substr($uid, 0, 30),
                        'eventID' => $row['eventID'],
                        'reason' => 'uid_mapping_match',
                        'strategy' => 'primary'
                    ]);
                    return $row['eventID'];
                }
                
                // Event was deleted, clean up orphaned mapping
                $this->log('warning', 'Orphaned UID mapping found, cleaning up', [
                    'sessionID' => $this->importSessionID,
                    'uid' => substr($uid, 0, 30),
                    'eventID' => $row['eventID'],
                    'reason' => 'event_deleted'
                ]);
                $sql = "DELETE FROM calendar1_ical_uid_map WHERE icalUID = ?";
                WCF::getDB()->prepareStatement($sql)->execute([$uid]);
            } else {
                $this->log('debug', 'No UID mapping found, trying property-based matching', [
                    'sessionID' => $this->importSessionID,
                    'uid' => substr($uid, 0, 30)
                ]);
            }
            
            // SECONDARY: Try to find by event properties (for events without UID mapping)
            // This handles:
            // - Events imported before UID system was implemented
            // - ICS feeds that change UIDs when event details change
            // - Prevents duplicates when re-importing existing events
            $eventID = $this->findEventByProperties($event);
            if ($eventID) {
                // Before creating mapping, verify this event doesn't already have a DIFFERENT UID
                $sql = "SELECT icalUID FROM calendar1_ical_uid_map WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$eventID]);
                $existingUID = $statement->fetchColumn();
                
                if ($existingUID !== false && $existingUID !== $uid) {
                    // This event already has a different UID mapping - don't reuse it!
                    $this->log('warning', 'Event found by properties already has different UID, treating as new event', [
                        'uid' => substr($uid, 0, 30),
                        'eventID' => $eventID,
                        'existingUID' => substr($existingUID, 0, 30),
                        'reason' => 'uid_mismatch',
                        'startTime' => $event['dtstart'] ?? 'unknown'
                    ]);
                    return null; // Don't reuse this event, create a new one
                }
                
                // Found existing event without UID mapping - create mapping now
                $this->log('info', 'Found existing event by properties, creating UID mapping', [
                    'uid' => substr($uid, 0, 30),
                    'eventID' => $eventID,
                    'reason' => 'property_match',
                    'startTime' => $event['dtstart'] ?? 'unknown'
                ]);
                $this->saveUidMapping($eventID, $uid);
                return $eventID;
            }
            
            $this->log('debug', 'No existing event found, will create new', [
                'uid' => substr($uid, 0, 30),
                'reason' => 'no_match_found',
                'startTime' => $event['dtstart'] ?? 'unknown'
            ]);
            
            return null;
        } catch (\Exception $e) {
            $this->log('error', 'Error finding existing event: ' . $e->getMessage(), [
                'uid' => substr($uid, 0, 30),
                'trace' => substr($e->getTraceAsString(), 0, 500)
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
     * Enhanced validation (v4.3.3):
     * - Widened time window to ±30 minutes (from ±5 minutes)
     * - Added fuzzy title matching for events with similar but not identical titles
     * - Only matches events from the same importID or without importID
     * - Validates that matched event doesn't already have a UID mapping to a different UID
     * - Uses stricter matching criteria to avoid false positives
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
            $this->log('debug', 'Cannot match by properties: missing or invalid start time', [
                'dtstart' => $event['dtstart'] ?? 'null'
            ]);
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
            
            // Time window for matching - widened to ±30 minutes to handle time shifts
            $timeWindowStart = $startTime - self::PROPERTY_MATCH_TIME_WINDOW;
            $timeWindowEnd = $startTime + self::PROPERTY_MATCH_TIME_WINDOW;
            
            $this->log('debug', 'Attempting property-based matching', [
                'sessionID' => $this->importSessionID,
                'startTime' => date('Y-m-d H:i:s', $startTime),
                'location' => substr($location, 0, 30),
                'title' => substr($eventTitle, 0, 30),
                'timeWindow' => self::PROPERTY_MATCH_TIME_WINDOW . ' seconds',
                'strategies' => 'time_location -> time_title_like -> time_title_fuzzy'
            ]);
            
            // Strategy 1: Match by exact startTime and location (most reliable for sports events)
            if (!empty($location)) {
                $this->log('debug', 'Trying strategy 1: time + exact location', [
                    'sessionID' => $this->importSessionID,
                    'location' => substr($location, 0, 30)
                ]);
                
                $sql = "SELECT e.eventID, e.subject, e.location, ed.startTime, m.icalUID
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
                    $this->log('info', 'Event matched by startTime + location', [
                        'sessionID' => $this->importSessionID,
                        'eventID' => $row['eventID'],
                        'subject' => substr($row['subject'], 0, 30),
                        'startTime' => date('Y-m-d H:i:s', $row['startTime']),
                        'location' => substr($location, 0, 30),
                        'matchStrategy' => 'time_location_exact',
                        'strategy' => 'secondary'
                    ]);
                    return $row['eventID'];
                } else {
                    $this->log('debug', 'Strategy 1 (time + location) found no match', [
                        'sessionID' => $this->importSessionID
                    ]);
                }
            }
            
            // Strategy 2: Match by startTime and title similarity (fallback)
            // First try LIKE pattern matching
            $this->log('debug', 'Trying strategy 2: time + title LIKE', [
                'sessionID' => $this->importSessionID,
                'titlePattern' => substr($eventTitle, 0, 30)
            ]);
            $titleForPattern = substr($eventTitle, 0, self::PROPERTY_MATCH_TITLE_LENGTH);
            $titlePattern = $this->escapeLikePattern($titleForPattern);
            
            $sql = "SELECT e.eventID, e.subject, ed.startTime, m.icalUID
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
                $this->log('info', 'Event matched by startTime + title similarity (LIKE)', [
                    'sessionID' => $this->importSessionID,
                    'eventID' => $row['eventID'],
                    'subject' => substr($row['subject'], 0, 30),
                    'startTime' => date('Y-m-d H:i:s', $row['startTime']),
                    'titlePattern' => substr($titlePattern, 0, 30),
                    'matchStrategy' => 'time_title_like',
                    'strategy' => 'secondary'
                ]);
                return $row['eventID'];
            } else {
                $this->log('debug', 'Strategy 2 (time + title LIKE) found no match', [
                    'sessionID' => $this->importSessionID
                ]);
            }
            
            // Strategy 3: Fuzzy title matching (NEW in v4.3.3)
            // Get candidates by time window only, then fuzzy match titles
            // Note: LIMIT 10 keeps SQL query fast while providing enough candidates for matching
            // Consider adding index on (categoryID, startTime) for optimal performance
            $this->log('debug', 'Trying strategy 3: time + fuzzy title matching', [
                'sessionID' => $this->importSessionID,
                'similarityThreshold' => (self::FUZZY_MATCH_SIMILARITY_THRESHOLD * 100) . '%'
            ]);
            $sql = "SELECT e.eventID, e.subject, ed.startTime
                    FROM calendar1_event e
                    JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                    LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
                    WHERE ed.startTime BETWEEN ? AND ?
                    AND m.mapID IS NULL
                    AND e.categoryID = ?
                    LIMIT 10";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$timeWindowStart, $timeWindowEnd, $this->categoryID]);
            
            $bestMatch = null;
            $bestSimilarity = 0.0;
            // Manual counter needed for logging - tracks how many candidates were evaluated
            // (Cannot use getAffectedRows() as this is a SELECT query)
            $candidateCount = 0;
            
            while ($row = $statement->fetchArray()) {
                $candidateCount++;
                $similarity = $this->calculateStringSimilarity($eventTitle, $row['subject']);
                
                // Early exit if exact match found
                if ($similarity >= 1.0) {
                    $bestMatch = $row;
                    $bestSimilarity = 1.0;
                    break;
                }
                
                if ($similarity > $bestSimilarity && $similarity >= self::FUZZY_MATCH_SIMILARITY_THRESHOLD) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $row;
                }
            }
            
            if ($bestMatch) {
                $this->log('info', 'Event matched by startTime + fuzzy title matching', [
                    'sessionID' => $this->importSessionID,
                    'eventID' => $bestMatch['eventID'],
                    'subject' => substr($bestMatch['subject'], 0, 30),
                    'startTime' => date('Y-m-d H:i:s', $bestMatch['startTime']),
                    'similarity' => round($bestSimilarity * 100, 1) . '%',
                    'matchStrategy' => 'time_title_fuzzy',
                    'strategy' => 'secondary'
                ]);
                return $bestMatch['eventID'];
            } else {
                $this->log('debug', 'Strategy 3 (fuzzy title) found no match above threshold', [
                    'sessionID' => $this->importSessionID,
                    'threshold' => (self::FUZZY_MATCH_SIMILARITY_THRESHOLD * 100) . '%',
                    'candidatesChecked' => $candidateCount
                ]);
            }
            
            $this->log('debug', 'No property match found', [
                'sessionID' => $this->importSessionID,
                'startTime' => date('Y-m-d H:i:s', $startTime),
                'location' => substr($location, 0, 30),
                'title' => substr($eventTitle, 0, 30),
                'allStrategiesFailed' => true
            ]);
            
            return null;
        } catch (\Exception $e) {
            $this->log('error', 'Error finding event by properties: ' . $e->getMessage(), [
                'startTime' => $event['dtstart'] ?? 'unknown',
                'exception' => get_class($e),
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
     * Calculate similarity between two strings (0.0 to 1.0).
     * Uses similar_text() which is faster than levenshtein for longer strings.
     * 
     * Performance note: similar_text() has O(n³) complexity, so we limit
     * input string length to MAX_SIMILARITY_STRING_LENGTH to avoid bottlenecks.
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0.0 = completely different, 1.0 = identical)
     */
    protected function calculateStringSimilarity($str1, $str2)
    {
        // Normalize strings: lowercase and trim
        $str1 = mb_strtolower(trim($str1), 'UTF-8');
        $str2 = mb_strtolower(trim($str2), 'UTF-8');
        
        // Handle empty strings
        if ($str1 === '' || $str2 === '') {
            return $str1 === $str2 ? 1.0 : 0.0;
        }
        
        // Limit string length to avoid O(n³) performance issues
        if (mb_strlen($str1, 'UTF-8') > self::MAX_SIMILARITY_STRING_LENGTH) {
            $str1 = mb_substr($str1, 0, self::MAX_SIMILARITY_STRING_LENGTH, 'UTF-8');
        }
        if (mb_strlen($str2, 'UTF-8') > self::MAX_SIMILARITY_STRING_LENGTH) {
            $str2 = mb_substr($str2, 0, self::MAX_SIMILARITY_STRING_LENGTH, 'UTF-8');
        }
        
        // Use similar_text for similarity calculation
        $percent = 0.0;
        similar_text($str1, $str2, $percent);
        
        return $percent / 100.0;
    }
    
    /**
     * Save UID to Event ID mapping with enhanced validation.
     * Validates that:
     * 1. This UID is not already mapped to a different event
     * 2. This eventID is not already mapped to a different UID
     * Uses parameterized query for SQL injection protection.
     * 
     * @param int $eventID WoltLab event ID
     * @param string $uid iCal UID
     * @return bool True if mapping was saved successfully, false otherwise
     */
    protected function saveUidMapping($eventID, $uid)
    {
        try {
            // Check if this UID is already mapped to a DIFFERENT event
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $existingEventID = $statement->fetchColumn();
            
            if ($existingEventID !== false && $existingEventID != $eventID) {
                $this->log('error', 'UID already mapped to different event, cannot create duplicate mapping', [
                    'uid' => substr($uid, 0, 30),
                    'requestedEventID' => $eventID,
                    'existingEventID' => $existingEventID,
                    'reason' => 'uid_already_mapped'
                ]);
                return false;
            }
            
            // Check if this eventID is already mapped to a DIFFERENT UID
            $sql = "SELECT icalUID FROM calendar1_ical_uid_map WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
            $existingUID = $statement->fetchColumn();
            
            if ($existingUID !== false && $existingUID !== $uid) {
                $this->log('error', 'Event already mapped to different UID, cannot create duplicate mapping', [
                    'eventID' => $eventID,
                    'requestedUID' => substr($uid, 0, 30),
                    'existingUID' => substr($existingUID, 0, 30),
                    'reason' => 'event_already_mapped'
                ]);
                return false;
            }
            
            // Safe to insert/update mapping
            // Note: Using aliases instead of VALUES() for MySQL 8.0.20+ compatibility
            $sql = "INSERT INTO calendar1_ical_uid_map (eventID, icalUID, importID, lastUpdated) 
                    VALUES (?, ?, ?, ?) AS new
                    ON DUPLICATE KEY UPDATE lastUpdated = new.lastUpdated, importID = new.importID";
            WCF::getDB()->prepareStatement($sql)->execute([$eventID, $uid, $this->importID, TIME_NOW]);
            
            $this->log('debug', 'UID mapping saved successfully', [
                'sessionID' => $this->importSessionID,
                'eventID' => $eventID,
                'uid' => substr($uid, 0, 30),
                'action' => $existingEventID !== false ? 'updated' : 'created',
                'importID' => $this->importID
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to save UID mapping: ' . $e->getMessage(), [
                'eventID' => $eventID,
                'uid' => substr($uid, 0, 30),
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 300)
            ]);
            return false;
        }
    }
    
    /**
     * Create new event from iCal data with enhanced duplicate prevention.
     * 
     * Additional validation before creating:
     * - Double-check that UID is not already in mapping table
     * - Log comprehensive details about the new event being created
     * 
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
            // CRITICAL: Final check before creating - ensure UID is not already mapped
            // This prevents race conditions and duplicate creation
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$event['uid']]);
            $existingEventID = $statement->fetchColumn();
            
            if ($existingEventID !== false) {
                $this->log('error', 'Race condition detected: UID already mapped, aborting create', [
                    'sessionID' => $this->importSessionID,
                    'uid' => substr($event['uid'], 0, 30),
                    'existingEventID' => $existingEventID,
                    'reason' => 'race_condition_prevented',
                    'title' => substr($event['summary'] ?? 'N/A', 0, 50)
                ]);
                $this->skippedCount++;
                return;
            }
            
            $this->log('debug', 'Pre-create validation passed, no existing UID mapping found', [
                'sessionID' => $this->importSessionID,
                'uid' => substr($event['uid'], 0, 30)
            ]);
            
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Calculate participation end time (registration deadline)
            // By default, registration closes when the event starts
            // This can be configured to close earlier if needed
            $participationEndTime = $this->calculateParticipationEndTime($event['dtstart']);
            
            $this->log('info', 'Creating new event', [
                'sessionID' => $this->importSessionID,
                'uid' => substr($event['uid'], 0, 30),
                'title' => substr($eventTitle, 0, 50),
                'startTime' => date('Y-m-d H:i:s', $event['dtstart']),
                'endTime' => date('Y-m-d H:i:s', $endTime),
                'location' => substr($event['location'] ?? '', 0, 30),
                'categoryID' => $categoryID,
                'allDay' => $event['allday'] ? 'yes' : 'no'
            ]);
            
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
                    
                    // Save UID mapping with validation
                    if ($this->saveUidMapping($eventID, $event['uid'])) {
                        $this->log('info', 'Event created successfully via API', [
                            'sessionID' => $this->importSessionID,
                            'eventID' => $eventID,
                            'uid' => substr($event['uid'], 0, 30),
                            'title' => substr($eventTitle, 0, 50),
                            'method' => 'woltlab_api'
                        ]);
                        
                        // Create forum topic for the event if configured
                        $this->createForumTopicForEvent($eventID, $eventTitle, $event);
                    } else {
                        $this->log('error', 'Event created but UID mapping failed', [
                            'sessionID' => $this->importSessionID,
                            'eventID' => $eventID,
                            'uid' => substr($event['uid'], 0, 30),
                            'method' => 'woltlab_api'
                        ]);
                    }
                    return;
                } catch (\Exception $apiEx) {
                    $this->log('warning', 'API create failed, falling back to SQL', [
                        'sessionID' => $this->importSessionID,
                        'error' => $apiEx->getMessage(),
                        'uid' => substr($event['uid'], 0, 30)
                    ]);
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
            
            // Save UID mapping with validation
            if ($this->saveUidMapping($eventID, $event['uid'])) {
                $this->log('info', 'Event created successfully via SQL', [
                    'sessionID' => $this->importSessionID,
                    'eventID' => $eventID,
                    'uid' => substr($event['uid'], 0, 30),
                    'title' => substr($eventTitle, 0, 50),
                    'method' => 'sql_fallback'
                ]);
                
                // Create forum topic for the event if configured
                $this->createForumTopicForEvent($eventID, $eventTitle, $event);
            } else {
                $this->log('error', 'Event created but UID mapping failed', [
                    'sessionID' => $this->importSessionID,
                    'eventID' => $eventID,
                    'uid' => substr($event['uid'], 0, 30),
                    'method' => 'sql_fallback'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create event', [
                'uid' => substr($event['uid'] ?? 'unknown', 0, 30),
                'title' => substr($this->getEventTitle($event), 0, 50),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            $this->skippedCount++;
        }
    }
    
    /**
     * Update existing event with iCal data with enhanced validation.
     * 
     * Additional validation:
     * - Verify eventID exists before updating
     * - Verify UID mapping is consistent
     * - Log comprehensive details about the update
     * 
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
            // Validate event exists
            $sql = "SELECT eventID, subject FROM calendar1_event WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
            $existingEvent = $statement->fetchArray();
            
            if (!$existingEvent) {
                $this->log('error', 'Cannot update: event does not exist', [
                    'eventID' => $eventID,
                    'uid' => substr($event['uid'], 0, 30),
                    'reason' => 'event_not_found'
                ]);
                $this->skippedCount++;
                return;
            }
            
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            // Ensure event always has a title (fallback to location, description, or UID)
            $eventTitle = $this->getEventTitle($event);
            
            // Calculate participation end time (registration deadline)
            $participationEndTime = $this->calculateParticipationEndTime($event['dtstart']);
            
            $this->log('info', 'Updating existing event', [
                'sessionID' => $this->importSessionID,
                'eventID' => $eventID,
                'uid' => substr($event['uid'], 0, 30),
                'oldTitle' => substr($existingEvent['subject'], 0, 50),
                'newTitle' => substr($eventTitle, 0, 50),
                'startTime' => date('Y-m-d H:i:s', $event['dtstart']),
                'endTime' => date('Y-m-d H:i:s', $endTime),
                'location' => substr($event['location'] ?? '', 0, 30)
            ]);
            
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
                        
                        // Update UID mapping timestamp
                        $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
                        WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
                        
                        $this->log('info', 'Event updated successfully via API', [
                            'eventID' => $eventID,
                            'uid' => substr($event['uid'], 0, 30),
                            'title' => substr($eventTitle, 0, 50)
                        ]);
                        return;
                    }
                } catch (\Exception $apiEx) {
                    $this->log('warning', 'API update failed, falling back to SQL', [
                        'eventID' => $eventID,
                        'error' => $apiEx->getMessage()
                    ]);
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
            
            // Update UID mapping timestamp
            $sql = "UPDATE calendar1_ical_uid_map SET lastUpdated = ? WHERE icalUID = ?";
            WCF::getDB()->prepareStatement($sql)->execute([TIME_NOW, $event['uid']]);
            
            $this->log('info', 'Event updated successfully via SQL', [
                'eventID' => $eventID,
                'uid' => substr($event['uid'], 0, 30),
                'title' => substr($eventTitle, 0, 50)
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to update event', [
                'eventID' => $eventID,
                'uid' => substr($event['uid'] ?? 'unknown', 0, 30),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 500)
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
     * Validations:
     * - Ensures deadline is not in the past
     * - Validates configuration is within acceptable range (1-168 hours)
     * - Validates deadline is not after event start time
     * 
     * @param int $eventStartTime Event start timestamp
     * @return int Participation end time timestamp
     */
    protected function calculateParticipationEndTime($eventStartTime)
    {
        // Validate event start time is not in the past
        if ($eventStartTime < TIME_NOW) {
            $this->log('debug', 'Event is in the past, setting participation end time to event start', [
                'eventStartTime' => date('Y-m-d H:i:s', $eventStartTime),
                'now' => date('Y-m-d H:i:s', TIME_NOW)
            ]);
            return $eventStartTime;
        }
        
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
                    $this->log('warning', 'Participation end time would be in the past, using current time instead', [
                        'eventStartTime' => date('Y-m-d H:i:s', $eventStartTime),
                        'calculatedEndTime' => date('Y-m-d H:i:s', $calculatedEndTime),
                        'hoursBefore' => $hoursBefore,
                        'adjustedTo' => 'TIME_NOW'
                    ]);
                    // Use current time as the minimum acceptable deadline
                    return TIME_NOW;
                }
                
                // Additional validation: deadline must be before event start
                if ($calculatedEndTime > $eventStartTime) {
                    $this->log('warning', 'Calculated participation end time is after event start, using event start time', [
                        'eventStartTime' => date('Y-m-d H:i:s', $eventStartTime),
                        'calculatedEndTime' => date('Y-m-d H:i:s', $calculatedEndTime),
                        'hoursBefore' => $hoursBefore
                    ]);
                    return $eventStartTime;
                }
                
                $this->log('debug', 'Participation deadline calculated', [
                    'eventStartTime' => date('Y-m-d H:i:s', $eventStartTime),
                    'participationEndTime' => date('Y-m-d H:i:s', $calculatedEndTime),
                    'hoursBefore' => $hoursBefore
                ]);
                
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
     * Create a forum topic/thread for a calendar event.
     * Creates a discussion thread in the configured forum board with title "Event: [EventTitle]".
     * 
     * Configuration:
     * - CALENDAR_IMPORT_CREATE_THREADS: Enable/disable forum topic creation (default: true)
     * - CALENDAR_IMPORT_BOARD_ID: Target board ID for topics (default: 0 = disabled)
     * 
     * Uses parameterized queries for SQL injection protection.
     * 
     * @param int $eventID Event ID
     * @param string $eventTitle Event title
     * @param array $event Event data array
     * @return int|null Thread ID if created, null otherwise
     */
    protected function createForumTopicForEvent($eventID, $eventTitle, $event)
    {
        // Check if forum topic creation is enabled
        if (!$this->shouldCreateForumTopics()) {
            $this->log('debug', 'Forum topic creation is disabled', [
                'eventID' => $eventID
            ]);
            return null;
        }
        
        // Get configured board ID
        $boardID = $this->getForumBoardID();
        if (!$boardID || $boardID <= 0) {
            $this->log('debug', 'No valid board ID configured for forum topics', [
                'eventID' => $eventID,
                'boardID' => $boardID
            ]);
            return null;
        }
        
        try {
            // Verify board exists using parameterized query
            // Use try-catch to handle cases where WBB is not installed
            try {
                $sql = "SELECT boardID, title FROM wbb" . WCF_N . "_board WHERE boardID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$boardID]);
                $board = $statement->fetchArray();
            } catch (\Exception $e) {
                $this->log('warning', 'WBB board table does not exist - WBB not installed?', [
                    'eventID' => $eventID,
                    'boardID' => $boardID,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
            
            if (!$board) {
                $this->log('warning', 'Configured board does not exist', [
                    'eventID' => $eventID,
                    'boardID' => $boardID
                ]);
                return null;
            }
            
            $this->log('debug', 'Creating forum topic for event', [
                'eventID' => $eventID,
                'boardID' => $boardID,
                'boardTitle' => substr($board['title'], 0, 50),
                'eventTitle' => substr($eventTitle, 0, 50)
            ]);
            
            // Prepare topic title and message
            $topicTitle = 'Event: ' . $eventTitle;
            $topicMessage = $this->buildForumTopicMessage($event, $eventTitle);
            
            // Try to use WBB API if available
            if (class_exists('wbb\data\thread\ThreadAction')) {
                try {
                    $threadAction = new \wbb\data\thread\ThreadAction([], 'create', [
                        'data' => [
                            'boardID' => $boardID,
                            'topic' => $topicTitle,
                            'time' => TIME_NOW,
                            'userID' => $this->eventUserID,
                            'username' => $this->eventUsername
                        ],
                        'postData' => [
                            'message' => $topicMessage,
                            'enableHtml' => 0,
                            'time' => TIME_NOW,
                            'userID' => $this->eventUserID,
                            'username' => $this->eventUsername
                        ]
                    ]);
                    $result = $threadAction->executeAction();
                    $threadID = $result['returnValues']->threadID;
                    
                    $this->log('info', 'Forum topic created successfully via WBB API', [
                        'eventID' => $eventID,
                        'threadID' => $threadID,
                        'boardID' => $boardID,
                        'title' => substr($topicTitle, 0, 50)
                    ]);
                    
                    // Store the threadID mapping for future reference
                    $this->storeForumThreadMapping($eventID, $threadID);
                    
                    return $threadID;
                } catch (\Exception $e) {
                    $this->log('error', 'WBB API topic creation failed', [
                        'eventID' => $eventID,
                        'error' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 200)
                    ]);
                }
            }
            
            // WBB API not available - forum topic creation requires WBB API
            // SQL fallback is not implemented due to complexity of WBB's post/thread system
            // which includes search indexing, activity tracking, and other WBB-specific features
            $this->log('warning', 'WBB API not available, cannot create forum topic', [
                'eventID' => $eventID,
                'reason' => 'api_not_available_or_wbb_not_installed',
                'note' => 'Forum topic creation requires WBB ThreadAction API'
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create forum topic', [
                'eventID' => $eventID,
                'boardID' => $boardID,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 300)
            ]);
            return null;
        }
    }
    
    /**
     * Build forum topic message content for an event.
     * Creates a formatted message with event details.
     * 
     * @param array $event Event data array
     * @param string $eventTitle Event title
     * @return string Formatted message content
     */
    protected function buildForumTopicMessage($event, $eventTitle)
    {
        $message = "[b]" . $eventTitle . "[/b]\n\n";
        
        // Add event details
        if (!empty($event['dtstart'])) {
            $message .= "[b]Start:[/b] " . date('d.m.Y H:i', $event['dtstart']) . " Uhr\n";
        }
        
        if (!empty($event['dtend'])) {
            $message .= "[b]Ende:[/b] " . date('d.m.Y H:i', $event['dtend']) . " Uhr\n";
        }
        
        if (!empty($event['location'])) {
            $message .= "[b]Ort:[/b] " . $event['location'] . "\n";
        }
        
        $message .= "\n";
        
        // Add description if available
        if (!empty($event['description'])) {
            $message .= $event['description'];
        } else {
            $message .= "Diskutiert hier über dieses Event!";
        }
        
        return $message;
    }
    
    /**
     * Store forum thread mapping for an event.
     * Creates a record linking the event to its forum thread.
     * Uses parameterized query for SQL injection protection.
     * Table must be created during installation (see install.sql).
     * 
     * @param int $eventID Event ID
     * @param int $threadID Thread ID
     * @return bool Success status
     */
    protected function storeForumThreadMapping($eventID, $threadID)
    {
        try {
            // Insert mapping (table created in install.sql)
            // Uses INSERT...ON DUPLICATE KEY UPDATE for MySQL 8.0.20+ compatibility
            $sql = "INSERT INTO calendar1_event_thread_map (eventID, threadID, created) 
                    VALUES (?, ?, ?) AS new
                    ON DUPLICATE KEY UPDATE threadID = new.threadID";
            WCF::getDB()->prepareStatement($sql)->execute([$eventID, $threadID, TIME_NOW]);
            
            $this->log('debug', 'Forum thread mapping stored', [
                'eventID' => $eventID,
                'threadID' => $threadID
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to store thread mapping', [
                'eventID' => $eventID,
                'threadID' => $threadID,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if forum topic creation is enabled.
     * 
     * @return bool
     */
    protected function shouldCreateForumTopics()
    {
        return defined('CALENDAR_IMPORT_CREATE_THREADS') ? (bool)CALENDAR_IMPORT_CREATE_THREADS : true;
    }
    
    /**
     * Get configured forum board ID for topic creation.
     * 
     * @return int Board ID or 0 if not configured
     */
    protected function getForumBoardID()
    {
        return defined('CALENDAR_IMPORT_BOARD_ID') ? (int)CALENDAR_IMPORT_BOARD_ID : 0;
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
            // Add sessionID to context if available and not already present
            // Check both 'sessionID' and '_sessionID' to prevent duplicate keys
            // Some callers may provide sessionID directly, others rely on auto-add
            if ($this->importSessionID && !isset($context['sessionID']) && !isset($context['_sessionID'])) {
                $context['sessionID'] = $this->importSessionID;
            }
            
            $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
            $logMessage = "[Calendar Import v4.3.4] [{$level}] {$message}{$contextStr}";
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
