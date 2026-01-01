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
 * @version 1.5.0
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
        
        if (empty($_POST)) {
            if (defined('CALENDAR_IMPORT_ICS_URL')) {
                $this->icsUrl = CALENDAR_IMPORT_ICS_URL;
            }
            if (defined('CALENDAR_IMPORT_CALENDAR_ID')) {
                $this->calendarID = CALENDAR_IMPORT_CALENDAR_ID;
            }
            if (defined('CALENDAR_IMPORT_TARGET_IMPORT_ID')) {
                $this->targetImportID = CALENDAR_IMPORT_TARGET_IMPORT_ID;
            }
            if (defined('CALENDAR_IMPORT_DEFAULT_BOARD_ID')) {
                $this->boardID = CALENDAR_IMPORT_DEFAULT_BOARD_ID;
            }
            if (defined('CALENDAR_IMPORT_CREATE_THREADS')) {
                $this->createThreads = (bool)CALENDAR_IMPORT_CREATE_THREADS;
            }
            if (defined('CALENDAR_IMPORT_CONVERT_TIMEZONE')) {
                $this->convertTimezone = (bool)CALENDAR_IMPORT_CONVERT_TIMEZONE;
            }
            if (defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ')) {
                $this->autoMarkPastRead = (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ;
            }
            if (defined('CALENDAR_IMPORT_MARK_UPDATED_UNREAD')) {
                $this->markUpdatedUnread = (bool)CALENDAR_IMPORT_MARK_UPDATED_UNREAD;
            }
            if (defined('CALENDAR_IMPORT_MAX_EVENTS')) {
                $this->maxEvents = CALENDAR_IMPORT_MAX_EVENTS;
            }
            if (defined('CALENDAR_IMPORT_LOG_LEVEL')) {
                $this->logLevel = CALENDAR_IMPORT_LOG_LEVEL;
            }
        }
        
        $this->collectDebugInfo();
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
            'icsTest' => null,
            'calendars' => [],
            'recentImports' => []
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
        
        // Options
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
        
        // Available calendars
        try {
            $sql = "SELECT calendarID, title FROM calendar1_calendar ORDER BY calendarID";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['calendars'][] = $row;
            }
        } catch (\Exception $e) {}
        
        // Cronjob status
        try {
            $sql = "SELECT cronjobID, cronjobClassName, isDisabled, lastExec, nextExec, failCount 
                    FROM wcf".WCF_N."_cronjob 
                    WHERE cronjobClassName LIKE ? OR cronjobClassName LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%', '%ICal%']);
            while ($row = $statement->fetchArray()) {
                $this->debugInfo['cronjobs'][] = $row;
            }
        } catch (\Exception $e) {}
        
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
        
        // Test ICS URL if configured
        if (!empty($this->icsUrl)) {
            $this->debugInfo['icsTest'] = $this->testIcsUrl($this->icsUrl);
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
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.5.0'
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
            'calendarID' => $this->calendarID,
            'targetImportID' => $this->targetImportID,
            'boardID' => $this->boardID,
            'createThreads' => $this->createThreads,
            'convertTimezone' => $this->convertTimezone,
            'autoMarkPastRead' => $this->autoMarkPastRead,
            'markUpdatedUnread' => $this->markUpdatedUnread,
            'maxEvents' => $this->maxEvents,
            'logLevel' => $this->logLevel,
            'debugInfo' => $this->debugInfo
        ]);
    }
}
