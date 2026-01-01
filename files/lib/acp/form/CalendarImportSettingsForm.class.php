<?php

namespace wcf\acp\form;

use wcf\form\AbstractForm;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Shows the calendar import settings form with debug information.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.6.3
 */
class CalendarImportSettingsForm extends AbstractForm {
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import';
    public $neededPermissions = [];
    
    public $icsUrl = '';
    public $categoryID = 0;
    public $importID = 0;
    public $boardID = 0;
    public $createThreads = true;
    public $convertTimezone = true;
    public $autoMarkPastRead = true;
    public $markUpdatedUnread = true;
    public $maxEvents = 100;
    public $logLevel = 'info';
    public $debugInfo = [];
    public $testImportResult = null;
    public $availableCalendars = [];
    public $categoryValidationError = '';
    public $runImportNow = false;
    
    public function readData() {
        parent::readData();
        
        // Load available calendars
        $this->availableCalendars = $this->loadAvailableCalendars();
        
        if (isset($_GET['testImport']) && $_GET['testImport'] == '1') {
            $this->runTestImport();
        }
        
        // Check if manual import should be triggered
        if (isset($_GET['runImport']) && $_GET['runImport'] == '1') {
            $this->runImportNow = true;
            $this->triggerManualImport();
        }
        
        if (empty($_POST)) {
            $this->icsUrl = $this->getOptionValue('calendar_import_ics_url', '');
            $this->categoryID = (int)$this->getOptionValue('calendar_import_category_id', 0);
            $this->importID = (int)$this->getOptionValue('calendar_import_import_id', 1);
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
            'eventsFound' => 0
        ];
        
        try {
            $icsUrl = $this->getOptionValue('calendar_import_ics_url', '');
            if (empty($icsUrl)) {
                throw new \Exception('Keine ICS-URL konfiguriert.');
            }
            $this->testImportResult['steps'][] = ['name' => 'ICS-URL prüfen', 'status' => 'success', 'message' => 'URL vorhanden'];
            
            $categoryID = (int)$this->getOptionValue('calendar_import_category_id', 0);
            if ($categoryID <= 0) {
                throw new \Exception('Keine gültige Category-ID konfiguriert.');
            }
            $this->testImportResult['steps'][] = ['name' => 'Category-ID prüfen', 'status' => 'success', 'message' => 'ID: ' . $categoryID];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $icsUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.6.3'
            ]);
            $icsContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new \Exception('HTTP-Fehler ' . $httpCode);
            }
            
            $this->testImportResult['steps'][] = ['name' => 'ICS abrufen', 'status' => 'success', 'message' => 'HTTP ' . $httpCode];
            
            preg_match_all('/BEGIN:VEVENT/', $icsContent, $matches);
            $this->testImportResult['eventsFound'] = count($matches[0]);
            $this->testImportResult['steps'][] = ['name' => 'Events gefunden', 'status' => 'success', 'message' => count($matches[0]) . ' Events'];
            
            $this->testImportResult['success'] = true;
            
        } catch (\Exception $e) {
            $this->testImportResult['error'] = $e->getMessage();
            $this->testImportResult['steps'][] = ['name' => 'Fehler', 'status' => 'error', 'message' => $e->getMessage()];
        }
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
            'cronjobs' => [],
            'cronjobClasses' => [],
            'calendars' => [],
            'icsTest' => null,
            'calendarPackages' => [],
            'dbTables' => []
        ];
        
        // Package info
        try {
            $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['com.lucaberwind.wcf.calendar.import']);
            $this->debugInfo['package'] = $statement->fetchArray();
        } catch (\Exception $e) {}
        
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
        
        // Options
        try {
            $sql = "SELECT optionName, optionValue FROM wcf".WCF_N."_option WHERE optionName LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['calendar_import%']);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['options'][$row['optionName']] = [
                    'value' => $row['optionValue']
                ];
            }
        } catch (\Exception $e) {}
        
        // Cronjob classes
        $cronjobClasses = [
            'wcf\\system\\cronjob\\ICalImportCronjob',
            'wcf\\system\\cronjob\\FixTimezoneCronjob',
            'wcf\\system\\cronjob\\MarkPastEventsReadCronjob'
        ];
        foreach ($cronjobClasses as $class) {
            $this->debugInfo['cronjobClasses'][$class] = [
                'exists' => class_exists($class)
            ];
        }
        
        // Check calendar tables - use dynamic table prefix
        $possibleTables = [
            'calendar'.WCF_N.'_calendar',
            'wcf'.WCF_N.'_calendar', 
            'calendar'.WCF_N.'_event', 
            'wcf'.WCF_N.'_calendar_event'
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
        
        // Calendars - try multiple table names with dynamic prefix
        $calendarTableNames = [
            'calendar'.WCF_N.'_calendar',
            'wcf'.WCF_N.'_calendar'
        ];
        
        foreach ($calendarTableNames as $tableName) {
            try {
                // First check if table exists
                $sql = "SHOW TABLES LIKE ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$tableName]);
                if ($statement->fetchColumn() !== false) {
                    // Table exists, query calendars
                    $sql = "SELECT calendarID, title FROM ".$tableName." ORDER BY calendarID";
                    $statement = WCF::getDB()->prepareStatement($sql);
                    $statement->execute();
                    while ($row = $statement->fetchArray()) {
                        $this->debugInfo['calendars'][] = $row;
                    }
                    break; // Found calendars, stop searching
                }
            } catch (\Exception $e) {
                // Try next table name
            }
        }
        
        // Note: Categories are not currently shown in debug info
        // This is intentional as calendar1_category table doesn't exist
        $this->debugInfo['note'] = 'Using categoryID from calendar1_event table, not from calendar table';
        
        // If still no calendars found, try WoltLab Calendar API
        if (empty($this->debugInfo['calendars'])) {
            try {
                if (class_exists('calendar\\data\\calendar\\CalendarList')) {
                    $calendarList = new \calendar\data\calendar\CalendarList();
                    $calendarList->readObjects();
                    foreach ($calendarList->getObjects() as $calendar) {
                        $this->debugInfo['calendars'][] = [
                            'calendarID' => $calendar->calendarID,
                            'title' => $calendar->title
                        ];
                    }
                }
            } catch (\Exception $e) {}
        }
        
        // Cronjobs
        try {
            $sql = "SELECT cronjobID, className, isDisabled, nextExec FROM wcf".WCF_N."_cronjob WHERE className LIKE ? OR className LIKE ? OR className LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%ICalImport%', '%FixTimezone%', '%MarkPastEvents%']);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['cronjobs'][] = $row;
            }
        } catch (\Exception $e) {}
        
        // Calendar packages
        try {
            $sql = "SELECT package, packageVersion FROM wcf".WCF_N."_package WHERE package LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%']);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['calendarPackages'][] = $row;
            }
        } catch (\Exception $e) {}
        
        // ICS Test
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
            'eventCount' => 0,
            'sampleEvents' => []
        ];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.6.3'
            ]);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $result['statusCode'] = $httpCode;
            
            if ($httpCode == 200 && $content) {
                $result['reachable'] = true;
                preg_match_all('/BEGIN:VEVENT/', $content, $matches);
                $result['eventCount'] = count($matches[0]);
                
                if (preg_match_all('/SUMMARY[^:]*:([^\r\n]+)/i', $content, $summaries)) {
                    $result['sampleEvents'] = array_slice($summaries[1], 0, 5);
                }
            }
        } catch (\Exception $e) {}
        
        return $result;
    }
    
    protected function loadAvailableCalendars() {
        $calendars = [];
        
        // Note: We're loading calendars for informational purposes only
        // The actual events use categoryID, not calendarID
        
        // Try calendar1_calendar table first (standard WoltLab Calendar)
        try {
            $sql = "SELECT calendarID, title FROM calendar1_calendar ORDER BY calendarID";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $calendars[] = $row;
            }
            if (!empty($calendars)) {
                return $calendars;
            }
        } catch (\Exception $e) {}
        
        // Fallback: try with dynamic table prefix
        try {
            $sql = "SELECT calendarID, title FROM calendar".WCF_N."_calendar ORDER BY calendarID";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $calendars[] = $row;
            }
            if (!empty($calendars)) {
                return $calendars;
            }
        } catch (\Exception $e) {}
        
        // Fallback: try WoltLab Calendar API
        try {
            if (class_exists('calendar\\data\\calendar\\CalendarList')) {
                $calendarList = new \calendar\data\calendar\CalendarList();
                $calendarList->readObjects();
                foreach ($calendarList->getObjects() as $calendar) {
                    $calendars[] = [
                        'calendarID' => $calendar->calendarID,
                        'title' => $calendar->title
                    ];
                }
            }
        } catch (\Exception $e) {}
        
        return $calendars;
    }
    
    protected function validateCategoryExists($categoryID) {
        if ($categoryID <= 0) {
            return false;
        }
        
        // Since calendar1_category table doesn't exist, we just check if categoryID is positive
        // The actual validation happens when inserting into calendar1_event
        return true;
    }
    
    protected function triggerManualImport() {
        try {
            require_once(WCF_DIR . 'lib/system/cronjob/ICalImportCronjob.class.php');
            $cronjob = new \wcf\system\cronjob\ICalImportCronjob();
            
            // Create a mock cronjob object
            $mockCronjob = new \stdClass();
            $mockCronjob->cronjobID = 0;
            
            // Execute the import
            $cronjob->execute($mockCronjob);
            
            $this->testImportResult = [
                'success' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Import wurde erfolgreich ausgeführt. Überprüfen Sie die Logs für Details.'
            ];
        } catch (\Exception $e) {
            $this->testImportResult = [
                'success' => false,
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Fehler beim Import: ' . $e->getMessage()
            ];
        }
    }
    
    public function readFormParameters() {
        parent::readFormParameters();
        
        if (isset($_POST['icsUrl'])) $this->icsUrl = StringUtil::trim($_POST['icsUrl']);
        if (isset($_POST['categoryID'])) $this->categoryID = intval($_POST['categoryID']);
        if (isset($_POST['importID'])) $this->importID = intval($_POST['importID']);
        if (isset($_POST['boardID'])) $this->boardID = intval($_POST['boardID']);
        $this->createThreads = isset($_POST['createThreads']);
        $this->convertTimezone = isset($_POST['convertTimezone']);
        $this->autoMarkPastRead = isset($_POST['autoMarkPastRead']);
        $this->markUpdatedUnread = isset($_POST['markUpdatedUnread']);
        if (isset($_POST['maxEvents'])) $this->maxEvents = intval($_POST['maxEvents']);
        if (isset($_POST['logLevel'])) $this->logLevel = StringUtil::trim($_POST['logLevel']);
    }
    
    public function save() {
        parent::save();
        
        // Validate category ID before saving
        if ($this->categoryID > 0 && !$this->validateCategoryExists($this->categoryID)) {
            $this->categoryValidationError = "Warnung: Category-ID {$this->categoryID} konnte nicht validiert werden. Stellen Sie sicher, dass die ID korrekt ist.";
            // Still save the value but show warning
        }
        
        $this->updateOption('calendar_import_ics_url', $this->icsUrl);
        $this->updateOption('calendar_import_category_id', $this->categoryID);
        $this->updateOption('calendar_import_import_id', $this->importID);
        $this->updateOption('calendar_import_default_board_id', $this->boardID);
        $this->updateOption('calendar_import_create_threads', $this->createThreads ? 1 : 0);
        $this->updateOption('calendar_import_convert_timezone', $this->convertTimezone ? 1 : 0);
        $this->updateOption('calendar_import_auto_mark_past_read', $this->autoMarkPastRead ? 1 : 0);
        $this->updateOption('calendar_import_mark_updated_unread', $this->markUpdatedUnread ? 1 : 0);
        $this->updateOption('calendar_import_max_events', $this->maxEvents);
        $this->updateOption('calendar_import_log_level', $this->logLevel);
        
        // Cache reset
        \wcf\system\cache\builder\OptionCacheBuilder::getInstance()->reset();
        
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
            'categoryID' => $this->categoryID,
            'importID' => $this->importID,
            'boardID' => $this->boardID,
            'createThreads' => $this->createThreads,
            'convertTimezone' => $this->convertTimezone,
            'autoMarkPastRead' => $this->autoMarkPastRead,
            'markUpdatedUnread' => $this->markUpdatedUnread,
            'maxEvents' => $this->maxEvents,
            'logLevel' => $this->logLevel,
            'debugInfo' => $this->debugInfo,
            'testImportResult' => $this->testImportResult,
            'availableCalendars' => $this->availableCalendars,
            'categoryValidationError' => $this->categoryValidationError
        ]);
    }
}
