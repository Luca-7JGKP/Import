<?php
namespace wcf\acp\page;

use wcf\page\AbstractPage;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;

/**
 * Debug-Seite für Sprachvariablen des Kalender-Imports
 * 
 * Aufruf: /acp/index.php?calendar-import-language-debug/
 * 
 * @author  Copilot
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package com.lucaberwind.wcf.calendar.import
 */
class CalendarImportLanguageDebugPage extends AbstractPage {
    /**
     * @inheritDoc
     */
    public $neededPermissions = ['admin.general.canUseAcp'];
    
    /**
     * Debug-Informationen
     */
    public $debugInfo = [];
    
    /**
     * @inheritDoc
     */
    public function readData() {
        parent::readData();
        
        // 1. Aktuelle Spracheinstellungen
        $this->debugInfo['current_language'] = [
            'id' => WCF::getLanguage()->languageID,
            'name' => WCF::getLanguage()->languageName,
            'code' => WCF::getLanguage()->languageCode
        ];
        
        // 2. Alle verfügbaren Sprachen
        $this->debugInfo['available_languages'] = [];
        foreach (LanguageFactory::getInstance()->getLanguages() as $language) {
            $this->debugInfo['available_languages'][] = [
                'id' => $language->languageID,
                'name' => $language->languageName,
                'code' => $language->languageCode
            ];
        }
        
        // 3. Sprachvariablen-Test (alle relevanten Keys)
        $testKeys = [
            // Menu Keys
            'wcf.acp.menu.link.calendar.import',
            
            // Settings Form Keys (alte Struktur)
            'wcf.acp.calendar.import.settings',
            'wcf.acp.calendar.import.settings.description',
            'wcf.acp.calendar.import.targetImportID',
            'wcf.acp.calendar.import.targetImportID.description',
            'wcf.acp.calendar.import.boardID',
            'wcf.acp.calendar.import.boardID.description',
            'wcf.acp.calendar.import.createThreads',
            'wcf.acp.calendar.import.createThreads.description',
            'wcf.acp.calendar.import.autoMarkPastEventsRead',
            'wcf.acp.calendar.import.autoMarkPastEventsRead.description',
            'wcf.acp.calendar.import.markUpdatedAsUnread',
            'wcf.acp.calendar.import.markUpdatedAsUnread.description',
            'wcf.acp.calendar.import.convertTimezone',
            'wcf.acp.calendar.import.convertTimezone.description',
            'wcf.acp.calendar.import.general',
            'wcf.acp.calendar.import.tracking',
            'wcf.acp.calendar.import.advanced',
            'wcf.acp.calendar.import.maxEvents',
            'wcf.acp.calendar.import.maxEvents.description',
            'wcf.acp.calendar.import.logLevel',
            'wcf.acp.calendar.import.logLevel.description',
            'wcf.acp.calendar.import.import',
            
            // Option Keys (neue Struktur)
            'wcf.acp.option.category.calendar.import',
            'wcf.acp.option.category.calendar.import.description',
            'wcf.acp.option.calendar_import_target_import_id',
            'wcf.acp.option.calendar_import_default_board_id',
            'wcf.acp.option.calendar_import_create_threads'
        ];
        
        $this->debugInfo['language_keys'] = [];
        foreach ($testKeys as $key) {
            $value = WCF::getLanguage()->get($key);
            $isDefined = ($value !== $key);
            $this->debugInfo['language_keys'][$key] = [
                'value' => $value,
                'is_defined' => $isDefined,
                'status' => $isDefined ? 'OK' : 'MISSING'
            ];
        }
        
        // 4. Datenbank-Einträge für Sprachvariablen
        $sql = "SELECT 
                    li.languageItemID,
                    li.languageItem,
                    li.languageItemValue,
                    li.languageCategoryID,
                    li.languageID,
                    l.languageCode,
                    lc.languageCategory
                FROM wcf".WCF_N."_language_item li
                LEFT JOIN wcf".WCF_N."_language l ON l.languageID = li.languageID
                LEFT JOIN wcf".WCF_N."_language_category lc ON lc.languageCategoryID = li.languageCategoryID
                WHERE li.languageItem LIKE ? 
                   OR li.languageItem LIKE ?
                   OR li.languageItem LIKE ?
                ORDER BY li.languageItem, l.languageCode";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([
            'wcf.acp.calendar.import%',
            'wcf.acp.menu.link.calendar.import%',
            'wcf.acp.option.calendar%'
        ]);
        
        $this->debugInfo['database_entries'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['database_entries'][] = $row;
        }
        
        // 5. Sprachkategorien prüfen
        $sql = "SELECT * FROM wcf".WCF_N."_language_category 
                WHERE languageCategory LIKE ? OR languageCategory LIKE ?
                ORDER BY languageCategory";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['wcf.acp.calendar%', 'wcf.acp.menu%']);
        
        $this->debugInfo['language_categories'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['language_categories'][] = $row;
        }
        
        // 6. Package-Info
        $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['%calendar%import%']);
        
        $this->debugInfo['package_info'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['package_info'][] = $row;
        }
        
        // 7. Installierte Sprachdateien prüfen
        $sql = "SELECT 
                    COUNT(*) as count,
                    li.languageCategoryID,
                    lc.languageCategory,
                    li.languageID,
                    l.languageCode
                FROM wcf".WCF_N."_language_item li
                LEFT JOIN wcf".WCF_N."_language l ON l.languageID = li.languageID
                LEFT JOIN wcf".WCF_N."_language_category lc ON lc.languageCategoryID = li.languageCategoryID
                WHERE li.languageItem LIKE 'wcf.acp.calendar.import%'
                GROUP BY li.languageCategoryID, li.languageID
                ORDER BY l.languageCode";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        
        $this->debugInfo['language_stats'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['language_stats'][] = $row;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function assignVariables() {
        parent::assignVariables();
        
        WCF::getTPL()->assign([
            'debugInfo' => $this->debugInfo
        ]);
    }
}
