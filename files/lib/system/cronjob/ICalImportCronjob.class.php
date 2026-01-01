<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Importiert Events aus einer ICS-URL in den WoltLab-Kalender.
 * 
 * Mit verbesserter dynamischer Kalender-Erkennung die mehrere
 * Tabellen-Präfixe und die WoltLab Calendar API unterstützt.
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
    
    /**
     * Detected calendar table name (cached)
     */
    protected static $calendarTableName = null;
    
    /**
     * Detected event table name (cached)
     */
    protected static $eventTableName = null;
    
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);
        
        $icsUrl = $this->getOption('CALENDAR_IMPORT_ICS_URL');
        $calendarID = (int)$this->getOption('CALENDAR_IMPORT_CALENDAR_ID');
        
        if (empty($icsUrl)) {
            $this->log('error', 'Keine ICS-URL konfiguriert');
            return;
        }
        
        // Detect calendar tables first
        $this->detectCalendarTables();
        
        // Automatische Kalender-Erkennung wenn keine ID konfiguriert
        if ($calendarID <= 0) {
            $this->log('warning', 'Keine Kalender-ID konfiguriert - versuche automatische Erkennung');
            $calendarID = $this->findFirstAvailableCalendar();
            if ($calendarID <= 0) {
                $this->log('error', 'Kein Kalender gefunden. Bitte erstellen Sie zuerst einen Kalender im WoltLab ACP.');
                return;
            }
            $this->log('info', "Automatisch erkannter Kalender: ID {$calendarID}");
        }
        
        // Validiere dass Kalender existiert
        if (!$this->validateCalendarExists($calendarID)) {
            $this->log('error', "Kalender mit ID {$calendarID} existiert nicht!");
            $fallbackID = $this->findFirstAvailableCalendar();
            if ($fallbackID > 0) {
                $this->log('warning', "Verwende Fallback-Kalender mit ID {$fallbackID}");
                $calendarID = $fallbackID;
            } else {
                $this->log('error', 'Kein gültiger Kalender verfügbar. Bitte erstellen Sie einen Kalender im WoltLab ACP.');
                return;
            }
        }
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl} in Kalender {$calendarID}");
        $this->log('debug', "Verwende Tabellen: Calendar=" . (self::$calendarTableName ?: 'N/A') . ", Event=" . (self::$eventTableName ?: 'N/A'));
        
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
                $this->importEvent($event, $calendarID);
            }
            
            $this->log('info', "Import abgeschlossen: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            
            // Log in Import-Log Tabelle
            $this->logImportResult($calendarID, count($events));
            
        } catch (\Exception $e) {
            $this->log('error', 'Import-Fehler: ' . $e->getMessage());
        }
    }
    
    /**
     * Erkennt dynamisch die korrekten Tabellennamen für Calendar und Events.
     * Unterstützt verschiedene WoltLab-Installationen mit unterschiedlichen Präfixen.
     */
    protected function detectCalendarTables()
    {
        if (self::$calendarTableName !== null && self::$eventTableName !== null) {
            return; // Already detected
        }
        
        // Possible calendar table patterns (in order of preference)
        $calendarPatterns = [
            'calendar' . WCF_N . '_calendar',
            'calendar1_calendar',
            'wcf' . WCF_N . '_calendar',
            'wcf1_calendar'
        ];
        
        $eventPatterns = [
            'calendar' . WCF_N . '_event',
            'calendar1_event',
            'wcf' . WCF_N . '_calendar_event',
            'wcf1_calendar_event'
        ];
        
        // Try to find calendar table
        foreach ($calendarPatterns as $tableName) {
            if ($this->tableExists($tableName)) {
                self::$calendarTableName = $tableName;
                $this->log('debug', "Kalender-Tabelle gefunden: {$tableName}");
                break;
            }
        }
        
        // Try to find event table
        foreach ($eventPatterns as $tableName) {
            if ($this->tableExists($tableName)) {
                self::$eventTableName = $tableName;
                $this->log('debug', "Event-Tabelle gefunden: {$tableName}");
                break;
            }
        }
        
        // Fallback: Scan database for calendar tables
        if (!self::$calendarTableName || !self::$eventTableName) {
            $this->scanDatabaseForCalendarTables();
        }
        
        // Use WoltLab Calendar API as fallback
        if (!self::$calendarTableName) {
            $this->log('warning', 'Keine Calendar-Tabelle gefunden - versuche WoltLab Calendar API');
        }
    }
    
    /**
     * Scannt die Datenbank nach Kalender-Tabellen.
     */
    protected function scanDatabaseForCalendarTables()
    {
        try {
            $sql = "SHOW TABLES LIKE '%calendar%'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            
            while ($row = $statement->fetchArray(\PDO::FETCH_NUM)) {
                $tableName = $row[0];
                
                // Check if it's a calendar table (ends with _calendar)
                if (preg_match('/_calendar$/', $tableName) && !self::$calendarTableName) {
                    // Verify it has expected columns
                    if ($this->hasColumn($tableName, 'calendarID')) {
                        self::$calendarTableName = $tableName;
                        $this->log('debug', "Dynamisch gefundene Kalender-Tabelle: {$tableName}");
                    }
                }
                
                // Check if it's an event table (ends with _event but not _event_*)
                if (preg_match('/_event$/', $tableName) && !self::$eventTableName) {
                    if ($this->hasColumn($tableName, 'eventID')) {
                        self::$eventTableName = $tableName;
                        $this->log('debug', "Dynamisch gefundene Event-Tabelle: {$tableName}");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('error', 'Fehler beim Scannen der Datenbank: ' . $e->getMessage());
        }
    }
    
    /**
     * Prüft ob eine Tabelle existiert.
     */
    protected function tableExists($tableName)
    {
        try {
            $sql = "SHOW TABLES LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$tableName]);
            return $statement->fetchColumn() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Prüft ob eine Tabelle eine bestimmte Spalte hat.
     */
    protected function hasColumn($tableName, $columnName)
    {
        try {
            $sql = "SHOW COLUMNS FROM {$tableName} LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$columnName]);
            return $statement->fetchColumn() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Findet den ersten verfügbaren Kalender.
     * Unterstützt dynamische Tabellennamen und WoltLab Calendar API.
     */
    protected function findFirstAvailableCalendar()
    {
        // First, try WoltLab Calendar API
        try {
            if (class_exists('calendar\\data\\calendar\\CalendarList')) {
                $calendarList = new \calendar\data\calendar\CalendarList();
                $calendarList->readObjects();
                $calendars = $calendarList->getObjects();
                if (!empty($calendars)) {
                    $calendar = reset($calendars);
                    $this->log('debug', 'Kalender über WoltLab API gefunden');
                    return $calendar->calendarID;
                }
            }
        } catch (\Exception $e) {
            $this->log('debug', 'WoltLab Calendar API nicht verfügbar: ' . $e->getMessage());
        }
        
        // Fallback: Direct database query
        if (self::$calendarTableName) {
            try {
                $sql = "SELECT calendarID FROM " . self::$calendarTableName . " ORDER BY calendarID ASC LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $row = $statement->fetchArray();
                if ($row) {
                    return (int)$row['calendarID'];
                }
            } catch (\Exception $e) {
                $this->log('error', 'Fehler bei Kalender-Suche in ' . self::$calendarTableName . ': ' . $e->getMessage());
            }
        }
        
        return 0;
    }
    
    /**
     * Gibt alle verfügbaren Kalender zurück (statische Methode für ACP-Formular).
     * Unterstützt dynamische Tabellennamen und WoltLab Calendar API.
     */
    public static function getAvailableCalendars()
    {
        $calendars = [];
        
        // Try WoltLab Calendar API first
        try {
            if (class_exists('calendar\\data\\calendar\\CalendarList')) {
                $calendarList = new \calendar\data\calendar\CalendarList();
                $calendarList->readObjects();
                foreach ($calendarList->getObjects() as $calendar) {
                    $calendars[$calendar->calendarID] = $calendar->title;
                }
                if (!empty($calendars)) {
                    return $calendars;
                }
            }
        } catch (\Exception $e) {}
        
        // Fallback: Direct database query with multiple table patterns
        $tablePatterns = [
            'calendar' . WCF_N . '_calendar',
            'calendar1_calendar',
            'wcf' . WCF_N . '_calendar'
        ];
        
        foreach ($tablePatterns as $tableName) {
            try {
                $sql = "SELECT calendarID, title FROM {$tableName} ORDER BY title ASC";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                while ($row = $statement->fetchArray()) {
                    $calendars[$row['calendarID']] = $row['title'];
                }
                if (!empty($calendars)) {
                    return $calendars;
                }
            } catch (\Exception $e) {
                // Table doesn't exist, try next pattern
            }
        }
        
        return $calendars;
    }
    
    /**
     * Validiert dass ein Kalender mit der gegebenen ID existiert.
     */
    protected function validateCalendarExists($calendarID)
    {
        // Try WoltLab Calendar API first
        try {
            if (class_exists('calendar\\data\\calendar\\Calendar')) {
                $calendar = new \calendar\data\calendar\Calendar($calendarID);
                if ($calendar->calendarID) {
                    return true;
                }
            }
        } catch (\Exception $e) {}
        
        // Fallback: Direct database query
        if (self::$calendarTableName) {
            try {
                $sql = "SELECT calendarID FROM " . self::$calendarTableName . " WHERE calendarID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$calendarID]);
                return $statement->fetchColumn() !== false;
            } catch (\Exception $e) {
                $this->log('error', 'Fehler bei Kalender-Validierung: ' . $e->getMessage());
            }
        }
        
        return false;
    }
    
    /**
     * Loggt das Import-Ergebnis in die Datenbank.
     */
    protected function logImportResult($calendarID, $totalEvents)
    {
        // Try multiple table patterns for import log
        $logTables = [
            'calendar' . WCF_N . '_event_import_log',
            'calendar1_event_import_log'
        ];
        
        foreach ($logTables as $tableName) {
            try {
                $sql = "INSERT INTO {$tableName} 
                        (calendarID, importTime, eventsFound, eventsImported, eventsUpdated, eventsSkipped)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([
                    $calendarID,
                    TIME_NOW,
                    $totalEvents,
                    $this->importedCount,
                    $this->updatedCount,
                    $this->skippedCount
                ]);
                return; // Success
            } catch (\Exception $e) {
                // Table doesn't exist, try next
            }
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
        
        // Handle folded lines (RFC 5545)
        $unfoldedLines = [];
        $currentLine = '';
        foreach ($lines as $line) {
            if (strlen($line) > 0 && ($line[0] === ' ' || $line[0] === "\t")) {
                $currentLine .= substr($line, 1);
            } else {
                if ($currentLine !== '') {
                    $unfoldedLines[] = $currentLine;
                }
                $currentLine = $line;
            }
        }
        if ($currentLine !== '') {
            $unfoldedLines[] = $currentLine;
        }
        
        $currentEvent = null;
        $inEvent = false;
        
        foreach ($unfoldedLines as $line) {
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
        
        // All-day event (date only)
        if (strlen($value) === 8) {
            $dt = \DateTime::createFromFormat('Ymd', $value);
            if ($dt) {
                $dt->setTime(0, 0, 0);
                return $dt->getTimestamp();
            }
        }
        
        // Date with time
        if (preg_match('/(\d{8})T(\d{6})Z?/', $value, $matches)) {
            $dateStr = $matches[1] . 'T' . $matches[2];
            if (substr($value, -1) === 'Z') {
                $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone('UTC'));
            } else {
                // Check for TZID parameter
                if (preg_match('/TZID=([^;:]+)/', $key, $tzMatch)) {
                    try {
                        $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone($tzMatch[1]));
                    } catch (\Exception $e) {
                        $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr);
                    }
                } else {
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr);
                }
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
        $value = str_replace('\\N', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\;', ';', $value);
        $value = str_replace('\\\\', '\\', $value);
        return trim($value);
    }
    
    protected function importEvent($event, $calendarID)
    {
        $existingEventID = $this->findExistingEvent($event['uid']);
        
        if ($existingEventID) {
            $this->updateEvent($existingEventID, $event, $calendarID);
            $this->updatedCount++;
        } else {
            $this->createEvent($event, $calendarID);
            $this->importedCount++;
        }
    }
    
    protected function findExistingEvent($uid)
    {
        if (!self::$eventTableName) {
            return null;
        }
        
        try {
            $sql = "SELECT eventID FROM " . self::$eventTableName . " WHERE externalSource = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            return $row ? $row['eventID'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function createEvent($event, $calendarID)
    {
        if (!self::$eventTableName) {
            $this->log('error', 'Keine Event-Tabelle gefunden - Event kann nicht erstellt werden');
            $this->skippedCount++;
            return;
        }
        
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            $sql = "INSERT INTO " . self::$eventTableName . " 
                    (calendarID, userID, username, subject, message, time, enableHtml, externalSource, eventDate)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $calendarID,
                WCF::getUser()->userID ?: 1,
                WCF::getUser()->username ?: 'System',
                $event['summary'],
                $event['description'] ?: $event['summary'],
                TIME_NOW,
                0,
                $event['uid'],
                $eventDateData
            ]);
            
            $eventID = WCF::getDB()->getInsertID(self::$eventTableName, 'eventID');
            
            // Insert into event_date table
            $eventDateTable = str_replace('_event', '_event_date', self::$eventTableName);
            try {
                $sql = "INSERT INTO {$eventDateTable} (eventID, startTime, endTime, isFullDay)
                        VALUES (?, ?, ?, ?)";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([
                    $eventID,
                    $event['dtstart'],
                    $endTime,
                    $event['allday'] ? 1 : 0
                ]);
            } catch (\Exception $e) {
                // event_date table might not exist or have different structure
                $this->log('warning', 'Event_date Eintrag konnte nicht erstellt werden: ' . $e->getMessage());
            }
            
            $this->log('debug', "Event erstellt: {$event['summary']} (ID: {$eventID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Erstellen: {$event['summary']} - " . $e->getMessage());
            $this->skippedCount++;
        }
    }
    
    protected function updateEvent($eventID, $event, $calendarID)
    {
        if (!self::$eventTableName) {
            return;
        }
        
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            $sql = "UPDATE " . self::$eventTableName . " 
                    SET subject = ?, message = ?, eventDate = ?, time = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['summary'],
                $event['description'] ?: $event['summary'],
                $eventDateData,
                TIME_NOW,
                $eventID
            ]);
            
            // Update event_date table
            $eventDateTable = str_replace('_event', '_event_date', self::$eventTableName);
            try {
                $sql = "UPDATE {$eventDateTable} 
                        SET startTime = ?, endTime = ?, isFullDay = ?
                        WHERE eventID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([
                    $event['dtstart'],
                    $endTime,
                    $event['allday'] ? 1 : 0,
                    $eventID
                ]);
            } catch (\Exception $e) {
                // event_date table might not exist
            }
            
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
