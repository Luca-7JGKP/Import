<?php

namespace wcf\acp\page;

use wcf\page\AbstractPage;
use wcf\system\WCF;

/**
 * Debug page for Calendar Import - shows all database structures and settings.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 */
class CalendarImportDebugPage extends AbstractPage {
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import';
    public $neededPermissions = [];
    
    public $debugData = [];
    
    public function readData() {
        parent::readData();
        $this->collectAllDebugData();
    }
    
    protected function collectAllDebugData() {
        $this->debugData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'phpVersion' => PHP_VERSION,
            'wcfVersion' => PACKAGE_VERSION ?? 'unknown',
            'allTables' => [],
            'calendarTables' => [],
            'wcfOptions' => [],
            'tableStructures' => [],
            'tableContents' => [],
            'constants' => [],
            'packageInfo' => null,
            'cronjobs' => [],
            'errors' => []
        ];
        
        // 1. Get ALL tables in database
        try {
            $sql = "SHOW TABLES";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchColumn(0)) {
                $this->debugData['allTables'][] = $row;
                if (stripos($row, 'calendar') !== false || stripos($row, 'event') !== false) {
                    $this->debugData['calendarTables'][] = $row;
                }
            }
        } catch (\Exception $e) {
            $this->debugData['errors'][] = 'SHOW TABLES: ' . $e->getMessage();
        }
        
        // 2. Get ALL wcf options with calendar/import in name
        try {
            $sql = "SELECT * FROM wcf1_option WHERE optionName LIKE ? OR optionName LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%', '%import%']);
            while ($row = $statement->fetchArray()) {
                $this->debugData['wcfOptions'][$row['optionName']] = $row;
            }
        } catch (\Exception $e) {
            $this->debugData['errors'][] = 'wcf1_option: ' . $e->getMessage();
        }
        
        // 3. Get table structures for calendar tables
        foreach ($this->debugData['calendarTables'] as $table) {
            try {
                $sql = "DESCRIBE " . $table;
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $this->debugData['tableStructures'][$table] = [];
                while ($row = $statement->fetchArray()) {
                    $this->debugData['tableStructures'][$table][] = $row;
                }
            } catch (\Exception $e) {
                $this->debugData['errors'][] = "DESCRIBE {$table}: " . $e->getMessage();
            }
            
            // Get first 10 rows
            try {
                $sql = "SELECT * FROM " . $table . " LIMIT 10";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $this->debugData['tableContents'][$table] = [];
                while ($row = $statement->fetchArray()) {
                    $this->debugData['tableContents'][$table][] = $row;
                }
            } catch (\Exception $e) {
                $this->debugData['errors'][] = "SELECT {$table}: " . $e->getMessage();
            }
        }
        
        // 4. Check defined constants
        $constantsToCheck = [
            'CALENDAR_IMPORT_ICS_URL',
            'CALENDAR_IMPORT_CATEGORY_ID',
            'CALENDAR_IMPORT_TARGET_IMPORT_ID',
            'CALENDAR_IMPORT_USER_ID',
            'CALENDAR_IMPORT_DEFAULT_BOARD_ID',
            'CALENDAR_IMPORT_CREATE_THREADS',
            'CALENDAR_IMPORT_CONVERT_TIMEZONE',
            'CALENDAR_IMPORT_AUTO_MARK_PAST_READ',
            'CALENDAR_IMPORT_MARK_UPDATED_UNREAD',
            'CALENDAR_IMPORT_MAX_EVENTS',
            'CALENDAR_IMPORT_LOG_LEVEL'
        ];
        foreach ($constantsToCheck as $const) {
            $this->debugData['constants'][$const] = [
                'defined' => defined($const),
                'value' => defined($const) ? constant($const) : null
            ];
        }
        
        // 5. Package info
        try {
            $sql = "SELECT * FROM wcf1_package WHERE package LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%']);
            while ($row = $statement->fetchArray()) {
                $this->debugData['packageInfo'][] = $row;
            }
        } catch (\Exception $e) {
            $this->debugData['errors'][] = 'wcf1_package: ' . $e->getMessage();
        }
        
        // 6. Cronjobs
        try {
            $sql = "SELECT * FROM wcf1_cronjob WHERE className LIKE ? OR className LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(['%calendar%', '%import%']);
            while ($row = $statement->fetchArray()) {
                $this->debugData['cronjobs'][] = $row;
            }
        } catch (\Exception $e) {
            $this->debugData['errors'][] = 'wcf1_cronjob: ' . $e->getMessage();
        }
        
        // 7. Check if option table uses optionName or different column
        try {
            $sql = "DESCRIBE wcf1_option";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $this->debugData['optionTableStructure'] = [];
            while ($row = $statement->fetchArray()) {
                $this->debugData['optionTableStructure'][] = $row;
            }
        } catch (\Exception $e) {
            $this->debugData['errors'][] = 'DESCRIBE wcf1_option: ' . $e->getMessage();
        }
    }
    
    public function assignVariables() {
        parent::assignVariables();
        
        WCF::getTPL()->assign([
            'debugData' => $this->debugData,
            'debugDataJson' => json_encode($this->debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ]);
    }
}
