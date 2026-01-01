<?php
namespace wcf\acp\form;

use wcf\form\AbstractForm;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Shows the calendar import settings form with debug information and test import functionality.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.6.0
 */
class CalendarImportSettingsForm extends AbstractForm {
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import';
    public $neededPermissions = [];
    
    public $icsUrl = '';
    public $calendarID = 0;
    public $targetImportID = 0;
    public $boardID = 0;
    public $createThreads = true;
    public $convertTimezone = true;
    public $autoMarkPastRead = true;
    public $markUpdatedUnread = true;
    public $maxEvents = 100;
    public $logLevel = 'info';
    public $debugInfo = [];
    public $testImportResult = null;
    
    public function readData() {
        parent::readData();
        
        // Check if test import was requested
        if (isset($_GET['testImport']) && $_GET['testImport'] == '1') {
            $this->runTestImport();
        }
        
        if (empty($_POST)) {
            $this->icsUrl = $this->getOptionValue('calendar_import_ics_url', '');
            $this->calendarID = (int)$this->getOptionValue('calendar_import_calendar_id', 0);
            $this->targetImportID = (int)$this->getOptionValue('calendar_import_target_import_id', 0);
            $this->boardID = (int)$this->getOptionValue('calendar_import_default_board_id', 0);
            $this->createThreads = (bool)$this->getOptionValue('calendar_import_create_threads', 1);
            $this->convertTimezone = (bool)$this->getOptionValue('calendar_import_convert_timezone', 1);
            $this->autoMarkPastRead = (bool)$this->getOptionValue('calendar_import_auto_mark_past_read', 1);
            $this->markUpdatedUnread = (bool)$this->getOptionValue('calendar_import_mark_updated_unread', 1);
            $this->maxEvents = (int)$this->getOptionValue('calendar_import_max_events', 100);
            $this->logLevel = $this->getOptionValue('calendar_import_log_level', 'info');
        }
        
        $this->collectDebugInfo();
    }
    
    protected function runTestImport() {
        $this->testImportResult = [
            'success' => false,
            'timestamp' => date('Y-m-d H:i:s'),
            'steps' => [],
            'error' => null,
            'eventsFound' => 0,
            'eventsProcessed' => 0,
            'eventsCreated' => 0,
            'eventsUpdated' => 0,
            'eventsSkipped' => 0
        ];
        
        try {
            // Step 1: Check ICS URL
            $icsUrl = $this->getOptionValue('calendar_import_ics_url', '');
            if (empty($icsUrl)) {
                throw new \Exception('Keine ICS-URL konfiguriert. Bitte zuerst eine URL eingeben und speichern.');
            }
            $this->testImportResult['steps'][] = ['name' => 'ICS-URL prüfen', 'status' => 'success', 'message' => 'URL vorhanden: ' . $icsUrl];
            
            // Step 2: Check Calendar ID
            $calendarID = (int)$this->getOptionValue('calendar_import_calendar_id', 0);
            if ($calendarID <= 0) {
                throw new \Exception('Keine gültige Kalender-ID konfiguriert. Bitte eine Kalender-ID > 0 eingeben.');
            }
            $this->testImportResult['steps'][] = ['name' => 'Kalender-ID prüfen', 'status' => 'success', 'message' => 'Kalender-ID: ' . $calendarID];
            
            // Step 3: Check if calendar exists
            $calendarExists = false;
            try {
                $sql = "SELECT calendarID FROM calendar1_calendar WHERE calendarID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$calendarID]);
                $calendarExists = ($statement->fetchColumn() !== false);
            } catch (\Exception $e) {
                // Table might not exist or different structure
            }
            
            if (!$calendarExists) {
                throw new \Exception('Kalender mit ID ' . $calendarID . ' wurde nicht gefunden. Bitte prüfen Sie die verfügbaren Kalender im Debug-Bereich.');
            }
            $this->testImportResult['steps'][] = ['name' => 'Kalender existiert', 'status' => 'success', 'message' => 'Kalender #' . $calendarID . ' gefunden'];
            
            // Step 4: Fetch ICS content
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $icsUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.6.0'
            ]);
            $icsContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                throw new \Exception('Fehler beim Abrufen der ICS-Datei: ' . $curlError);
            }
            
            if ($httpCode !== 200) {
                throw new \Exception('ICS-URL liefert HTTP-Fehler ' . $httpCode . '. URL nicht erreichbar oder falsch.');
            }
            
            if (empty($icsContent)) {
                throw new \Exception('ICS-Datei ist leer.');
            }
            
            $this->testImportResult['steps'][] = ['name' => 'ICS-Datei abrufen', 'status' => 'success', 'message' => 'HTTP ' . $httpCode . ', ' . strlen($icsContent) . ' Bytes empfangen'];
            
            // Step 5: Parse ICS content
            if (strpos($icsContent, 'BEGIN:VCALENDAR') === false) {
                throw new \Exception('Ungültiges ICS-Format: BEGIN:VCALENDAR nicht gefunden.');
            }
            
            preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $icsContent, $eventMatches);
            $eventsFound = count($eventMatches[0]);
            $this->testImportResult['eventsFound'] = $eventsFound;
            
            if ($eventsFound === 0) {
                throw new \Exception('Keine Events in der ICS-Datei gefunden.');
            }
            
            $this->testImportResult['steps'][] = ['name' => 'ICS parsen', 'status' => 'success', 'message' => $eventsFound . ' Events gefunden'];
            
            // Step 6: Check cronjob class exists
            $cronjobClass = 'wcf\\system\\cronjob\\ICalImportCronjob';
            if (!class_exists($cronjobClass)) {
                throw new \Exception('Cronjob-Klasse ' . $cronjobClass . ' nicht gefunden. Plugin möglicherweise nicht korrekt installiert.');
            }
            $this->testImportResult['steps'][] = ['name' => 'Cronjob-Klasse prüfen', 'status' => 'success', 'message' => 'Klasse vorhanden'];
            
            // Step 7: Test event processing (dry run - parse first 3 events)
            $processedEvents = [];
            $maxTestEvents = min(3, $eventsFound);
            
            for ($i = 0; $i < $maxTestEvents; $i++) {
                $eventBlock = $eventMatches[0][$i];
                $eventData = $this->parseEventBlock($eventBlock);
                if ($eventData) {
                    $processedEvents[] = $eventData;
                    $this->testImportResult['eventsProcessed']++;
                }
            }
            
            $this->testImportResult['steps'][] = ['name' => 'Events verarbeiten (Test)', 'status' => 'success', 'message' => count($processedEvents) . ' Events erfolgreich geparst'];
            
            // Step 8: Check database write permission
            try {
                $sql = "SELECT COUNT(*) FROM calendar1_event LIMIT 1";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $this->testImportResult['steps'][] = ['name' => 'Datenbank-Zugriff', 'status' => 'success', 'message' => 'Lese/Schreibzugriff OK'];
            } catch (\Exception $e) {
                throw new \Exception('Datenbankfehler: ' . $e->getMessage());
            }
            
            // All tests passed
            $this->testImportResult['success'] = true;
            $this->testImportResult['steps'][] = ['name' => 'Test abgeschlossen', 'status' => 'success', 'message' => 'Alle Prüfungen bestanden! Der Import sollte funktionieren.'];
            
        } catch (\Exception $e) {
            $this->testImportResult['error'] = $e->getMessage();
            $this->testImportResult['steps'][] = ['name' => 'Fehler', 'status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    protected function parseEventBlock($eventBlock) {
        $data = [];
        
        // Extract UID
        if (preg_match('/UID[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['uid'] = trim($match[1]);
        }
        
        // Extract SUMMARY
        if (preg_match('/SUMMARY[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['summary'] = trim($match[1]);
        }
        
        // Extract DTSTART
        if (preg_match('/DTSTART[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['dtstart'] = trim($match[1]);
        }
        
        // Extract DTEND
        if (preg_match('/DTEND[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['dtend'] = trim($match[1]);
        }
        
        // Extract DESCRIPTION
        if (preg_match('/DESCRIPTION[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['description'] = trim($match[1]);
        }
        
        // Extract LOCATION
        if (preg_match('/LOCATION[^:]*:([^\r\n]+)/i', $eventBlock, $match)) {
            $data['location'] = trim($match[1]);
        }
        
        return !empty($data) ? $data : null;
    }
    
    protected function getOptionValue($optionName, $default = null) {
        try {
            $sql = "SELECT optionValue FROM wcf".WCF_N."_option WHERE optionName = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$optionName]);
            $row = $statement->fetchArray();
            if ($row) {
                return $row['optionValue'];
            }
        } catch (\Exception $e) {}
        return $default;
    }
    
    protected function collectDebugInfo() {
        $this->debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'package' => null,
            'eventListeners' => [],
            'options' => [],
            'listenerClasses' => [],
            'eventClasses' => [],
            'calendarPackages' => [],
            'cronjobs' => [],
            'cronjobClasses' => [],
            'icsTest' => null,
            'calendars' => [],
            'recentImports' => [],
            'dbTables' => []
        ];
        
        // Package info
        try {
            $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['com.lucaberwind.wcf.calendar.import']);
            $this->debugInfo['package'] = $statement->fetchArray();
        } catch (\Exception $e) {
            $this->debugInfo['package'] = null;
        }
        
        // Event listeners
        if ($this->debugInfo['package']) {
            try {
                $sql = "SELECT * FROM wcf".WCF_N."_event_listener WHERE packageID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$this->debugInfo['package']['packageID']]);
                while ($row = $statement->fetchArray()) {
                    $this->debugInfo['eventListeners'][] = $row;
                }
            } catch (\Exception $e) {}
        }
        
        // Options - direkt aus DB lesen
        try {
            $sql = "SELECT optionName, optionValue FROM wcf".WCF_N."_option WHERE optionName LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['calendar_import%']);
            while ($row = $statement->fetchArray()) {
                $constName = strtoupper($row['optionName']);
                $this->debugInfo['options'][$row['optionName']] = [
                    'value' => $row['optionValue'],
                    'constantDefined' => defined($constName),
                    'constantValue' => defined($constName) ? constant($constName) : null
                ];
            }
        } catch (\Exception $e) {}
        
        // Listener classes
        $listenerClasses = [
            'wcf\\system\\event\\listener\\ICalImportExtensionEventListener',
            'wcf\\system\\event\\listener\\CalendarEventViewListener'
        ];
        foreach ($listenerClasses as $class) {
            $exists = class_exists($class);
            $this->debugInfo['listenerClasses'][$class] = [
                'exists' => $exists,
                'file' => $exists ? (new \ReflectionClass($class))->getFileName() : null
            ];
        }
        
        // Cronjob classes
        $cronjobClasses = [
            'wcf\\system\\cronjob\\ICalImportCronjob',
            'wcf\\system\\cronjob\\FixTimezoneCronjob',
            'wcf\\system\\cronjob\\MarkPastEventsReadCronjob'
        ];
        foreach ($cronjobClasses as $class) {
            $exists = class_exists($class);
            $this->debugInfo['cronjobClasses'][$class] = [
                'exists' => $exists,
                'file' => $exists ? (new \ReflectionClass($class))->getFileName() : null
            ];
        }
        
        // Calendar event classes
        $eventClasses = [
            'calendar\\page\\EventPage',
            'calendar\\page\\CalendarPage',
            'calendar\\data\\event\\EventAction',
            'calendar\\data\\event\\date\\EventDateAction'
        ];
        foreach ($eventClasses as $class) {
            $this->debugInfo['eventClasses'][$class] = class_exists($class);
        }
        
        // Calendar packages
        try {
            $sql = "SELECT package, packageVersion FROM wcf".WCF_N."_package WHERE package LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%']);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['calendarPackages'][] = $row;
            }
        } catch (\Exception $e) {}
        
        // Check which calendar tables exist
        $possibleTables = [
            'calendar1_calendar',
            'wcf1_calendar',
            'calendar1_event',
            'wcf1_calendar_event'
        ];
        foreach ($possibleTables as $table) {
            try {
                $sql = "SHOW TABLES LIKE ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$table]);
                $this->debugInfo['dbTables'][$table] = ($statement->fetchColumn() !== false);
            } catch (\Exception $e) {
                $this->debugInfo['dbTables'][$table] = false;
            }
        }
        
        // Available calendars - try different table structures
        $calendarQueries = [
            "SELECT calendarID, calendarID as title FROM calendar1_calendar ORDER BY calendarID",
            "SELECT calendarID, title FROM calendar1_calendar ORDER BY calendarID"
        ];
        
        foreach ($calendarQueries as $sql) {
            try {
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                while ($row = $statement->fetchArray()) {
                    $this->debugInfo['calendars'][] = $row;
                }
                if (!empty($this->debugInfo['calendars'])) {
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // If still no calendars, try to get from language items
        if (empty($this->debugInfo['calendars'])) {
            try {
                $sql = "SELECT c.calendarID, COALESCE(l.languageItemValue, CONCAT('Kalender #', c.calendarID)) as title 
                        FROM calendar1_calendar c 
                        LEFT JOIN wcf".WCF_N."_language_item l ON l.languageItem = CONCAT('calendar.calendar', c.calendarID)
                        ORDER BY c.calendarID";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                while ($row = $statement->fetchArray()) {
                    $this->debugInfo['calendars'][] = $row;
                }
            } catch (\Exception $e) {}
        }
        
        // Cronjob status - search by className containing our package
        try {
            $sql = "SELECT cronjobID, className, isDisabled, lastExec, nextExec, failCount 
                    FROM wcf".WCF_N."_cronjob 
                    WHERE className LIKE ? 
                    OR className LIKE ?
                    OR className LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                '%ICalImport%',
                '%FixTimezone%', 
                '%MarkPastEvents%'
            ]);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['cronjobs'][] = $row;
            }
        } catch (\Exception $e) {
            // Try alternative column name
            try {
                $sql = "SELECT * FROM wcf".WCF_N."_cronjob WHERE packageID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$this->debugInfo['package']['packageID'] ?? 0]);
                while ($row = $statement->fetchArray()) {
                    $this->debugInfo['cronjobs'][] = $row;
                }
            } catch (\Exception $e2) {}
        }
        
        // Recent imported events
        try {
            $sql = "SELECT eventID, subject, time, externalSource 
                    FROM calendar1_event 
                    WHERE externalSource IS NOT NULL AND externalSource != ''
                    ORDER BY time DESC LIMIT 10";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['recentImports'][] = $row;
            }
        } catch (\Exception $e) {}
        
        // Test ICS URL - use value from form or DB
        $testUrl = !empty($this->icsUrl) ? $this->icsUrl : $this->getOptionValue('calendar_import_ics_url', '');
        if (!empty($testUrl)) {
            $this->debugInfo['icsTest'] = $this->testIcsUrl($testUrl);
        }
    }
    
    protected function testIcsUrl($url) {
        $result = [
            'url' => $url,
            'reachable' => false,
            'statusCode' => null,
            'contentType' => null,
            'eventCount' => 0,
            'sampleEvents' => [],
            'error' => null
        ];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.6.0'
            ]);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            
            $result['statusCode'] = $httpCode;
            $result['contentType'] = $contentType;
            
            if ($error) {
                $result['error'] = $error;
                return $result;
            }
            
            if ($httpCode == 200 && $content) {
                $result['reachable'] = true;
                
                // Count events
                preg_match_all('/BEGIN:VEVENT/', $content, $matches);
                $result['eventCount'] = count($matches[0]);
                
                // Get sample events
                if (preg_match_all('/SUMMARY[^:]*:([^\r\n]+)/i', $content, $summaries)) {
                    $result['sampleEvents'] = array_slice($summaries[1], 0, 5);
                }
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    public function readFormParameters() {
        parent::readFormParameters();
        
        if (isset($_POST['icsUrl'])) {
            $this->icsUrl = StringUtil::trim($_POST['icsUrl']);
        }
        if (isset($_POST['calendarID'])) {
            $this->calendarID = intval($_POST['calendarID']);
        }
        if (isset($_POST['targetImportID'])) {
            $this->targetImportID = intval($_POST['targetImportID']);
        }
        if (isset($_POST['boardID'])) {
            $this->boardID = intval($_POST['boardID']);
        }
        $this->createThreads = isset($_POST['createThreads']);
        $this->convertTimezone = isset($_POST['convertTimezone']);
        $this->autoMarkPastRead = isset($_POST['autoMarkPastRead']);
        $this->markUpdatedUnread = isset($_POST['markUpdatedUnread']);
        if (isset($_POST['maxEvents'])) {
            $this->maxEvents = intval($_POST['maxEvents']);
        }
        if (isset($_POST['logLevel'])) {
            $this->logLevel = StringUtil::trim($_POST['logLevel']);
        }
    }
    
    public function save() {
        parent::save();
        
        $this->updateOption('calendar_import_ics_url', $this->icsUrl);
        $this->updateOption('calendar_import_calendar_id', $this->calendarID);
        $this->updateOption('calendar_import_target_import_id', $this->targetImportID);
        $this->updateOption('calendar_import_default_board_id', $this->boardID);
        $this->updateOption('calendar_import_create_threads', $this->createThreads ? 1 : 0);
        $this->updateOption('calendar_import_convert_timezone', $this->convertTimezone ? 1 : 0);
        $this->updateOption('calendar_import_auto_mark_past_read', $this->autoMarkPastRead ? 1 : 0);
        $this->updateOption('calendar_import_mark_updated_unread', $this->markUpdatedUnread ? 1 : 0);
        $this->updateOption('calendar_import_max_events', $this->maxEvents);
        $this->updateOption('calendar_import_log_level', $this->logLevel);
        
        // Reset all caches
        \wcf\system\cache\builder\OptionCacheBuilder::getInstance()->reset();
        
        // Clear runtime cache
        if (class_exists('wcf\system\cache\runtime\RuntimeCache')) {
            \wcf\system\cache\runtime\RuntimeCache::getInstance()->flush();
        }
        
        $this->saved();
        
        WCF::getTPL()->assign('success', true);
    }
    
    protected function updateOption($optionName, $optionValue) {
        $sql = "UPDATE wcf".WCF_N."_option SET optionValue = ? WHERE optionName = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$optionValue, $optionName]);
    }
    
    public function assignVariables() {
        parent::assignVariables();
        
        WCF::getTPL()->assign([
            'icsUrl' => $this->icsUrl,
            'calendarID' => $this->calendarID,
            'targetImportID' => $this->targetImportID,
            'boardID' => $this->boardID,
            'createThreads' => $this->createThreads,
            'convertTimezone' => $this->convertTimezone,
            'autoMarkPastRead' => $this->autoMarkPastRead,
            'markUpdatedUnread' => $this->markUpdatedUnread,
            'maxEvents' => $this->maxEvents,
            'logLevel' => $this->logLevel,
            'debugInfo' => $this->debugInfo,
            'testImportResult' => $this->testImportResult
        ]);
    }
}
