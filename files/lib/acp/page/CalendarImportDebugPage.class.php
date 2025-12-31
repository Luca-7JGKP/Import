<?php
namespace wcf\acp\page;
use wcf\acp\page\AbstractPage;
use wcf\system\WCF;
use wcf\system\language\LanguageFactory;

/**
 * Debug-Seite für Kalender-Import Plugin
 * Aufruf: /acp/index.php?calendar-import-debug/
 * 
 * @author  Copilot
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package com.luca.calendar.import
 */
class CalendarImportDebugPage extends AbstractPage {
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
        
        // 1. Sprachsystem-Check
        $this->debugInfo['language'] = [
            'current_language' => WCF::getLanguage()->languageName,
            'language_code' => WCF::getLanguage()->languageCode,
            'available_languages' => []
        ];
        
        foreach (LanguageFactory::getInstance()->getLanguages() as $language) {
            $this->debugInfo['language']['available_languages'][] = [
                'id' => $language->languageID,
                'name' => $language->languageName,
                'code' => $language->languageCode
            ];
        }
        
        // 2. Test der Sprachvariablen (alte + neue Keys)
        $testKeys = [
            // Menu
            'wcf.acp.menu.link.calendar.import',
            // Alte Keys (v1.2.0 und früher)
            'wcf.acp.calendar.import.settings',
            'wcf.acp.calendar.import.settings.description',
            'wcf.acp.calendar.import.targetImportID',
            'wcf.acp.calendar.import.boardID',
            'wcf.acp.calendar.import.createThreads',
            // Neue Keys (v1.2.1+)
            'wcf.acp.option.category.calendar.import',
            'wcf.acp.option.calendar_import_target_import_id',
            'wcf.acp.option.calendar_import_default_board_id',
            'wcf.acp.option.calendar_import_create_threads'
        ];
        
        $this->debugInfo['language_keys'] = [];
        foreach ($testKeys as $key) {
            $value = WCF::getLanguage()->get($key, true);
            $this->debugInfo['language_keys'][$key] = [
                'value' => $value ?: '(LEER)',
                'is_key' => ($value === $key || empty($value))
            ];
        }
        
        // 3. Datenbank-Check für Sprachvariablen (alte + neue)
        $sql = "SELECT languageItemID, languageItem, languageItemValue, languageCategoryID
                FROM wcf".WCF_N."_language_item
                WHERE languageItem LIKE ? OR languageItem LIKE ? OR languageItem LIKE ?
                ORDER BY languageItem";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['wcf.acp.calendar.import%', 'wcf.acp.menu.link.calendar.import%', 'wcf.acp.option.calendar_import%']);
        
        $this->debugInfo['database_entries'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['database_entries'][] = $row;
        }
        
        // 4. Optionen-Check
        $optionKeys = [
            'calendar_import_target_import_id',
            'calendar_import_board_id',
            'calendar_import_create_threads',
            'calendar_import_convert_timezone',
            'calendar_import_auto_mark_past_read',
            'calendar_import_mark_updated_unread',
            'calendar_import_max_events',
            'calendar_import_log_level'
        ];
        
        $this->debugInfo['options'] = [];
        foreach ($optionKeys as $optionName) {
            $constantName = 'CALENDAR_IMPORT_' . strtoupper(str_replace('calendar_import_', '', $optionName));
            $this->debugInfo['options'][$optionName] = [
                'constant' => $constantName,
                'exists' => defined($constantName),
                'value' => defined($constantName) ? constant($constantName) : 'NICHT DEFINIERT'
            ];
        }
        
        // 5. Tabellen-Check
        $tables = [
            'wcf1_calendar_event_read_status',
            'calendar1_event',
            'calendar1_event_import',
            'calendar1_event_import_log'
        ];
        
        $this->debugInfo['tables'] = [];
        foreach ($tables as $table) {
            try {
                $sql = "SHOW TABLES LIKE ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$table]);
                $exists = ($statement->fetchColumn() !== false);
                
                $this->debugInfo['tables'][$table] = [
                    'exists' => $exists,
                    'row_count' => 0
                ];
                
                if ($exists) {
                    $sql = "SELECT COUNT(*) FROM ".$table;
                    $statement = WCF::getDB()->prepareStatement($sql);
                    $statement->execute();
                    $this->debugInfo['tables'][$table]['row_count'] = $statement->fetchColumn();
                }
            } catch (\Exception $e) {
                $this->debugInfo['tables'][$table] = [
                    'exists' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // 6. Event-Listener Check
        $sql = "SELECT eventName, listenerClassName, environment
                FROM wcf".WCF_N."_event_listener
                WHERE listenerClassName LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['%CalendarImport%']);
        
        $this->debugInfo['event_listeners'] = [];
        while ($row = $statement->fetchArray()) {
            $this->debugInfo['event_listeners'][] = $row;
        }
        
        // 7. Cache-Status
        $cacheDir = WCF_DIR . 'cache/';
        $this->debugInfo['cache'] = [
            'cache_dir' => $cacheDir,
            'language_files' => glob($cacheDir . 'cache.language-*.php') ?: [],
            'template_files' => glob($cacheDir . 'cache.template-*.php') ?: []
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function show() {
        $this->readData();
        
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Kalender Import Debug</title>';
        echo '<style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            h1 { color: #333; }
            h2 { color: #666; margin-top: 30px; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; background: white; }
            th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
            th { background: #4CAF50; color: white; }
            .success { color: green; font-weight: bold; }
            .error { color: red; font-weight: bold; }
            .warning { color: orange; font-weight: bold; }
            pre { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
        </style></head><body>';
        
        echo '<h1>🔍 Kalender Import Plugin - Debug-Informationen</h1>';
        echo '<p><strong>Zeitstempel:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        
        // Sprachsystem
        echo '<h2>1. Sprachsystem</h2>';
        echo '<table>';
        echo '<tr><th>Eigenschaft</th><th>Wert</th></tr>';
        echo '<tr><td>Aktuelle Sprache</td><td>' . htmlspecialchars($this->debugInfo['language']['current_language']) . '</td></tr>';
        echo '<tr><td>Sprachcode</td><td>' . htmlspecialchars($this->debugInfo['language']['language_code']) . '</td></tr>';
        echo '</table>';
        
        // Sprachvariablen
        echo '<h2>2. Sprachvariablen-Test</h2>';
        echo '<table>';
        echo '<tr><th>Schlüssel</th><th>Wert</th><th>Status</th></tr>';
        foreach ($this->debugInfo['language_keys'] as $key => $data) {
            $status = $data['is_key'] ? '<span class="error">❌ NICHT GELADEN</span>' : '<span class="success">✅ OK</span>';
            echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($data['value']) . '</td><td>' . $status . '</td></tr>';
        }
        echo '</table>';
        
        // Datenbank-Einträge
        echo '<h2>3. Datenbank-Einträge (wcf_language_item)</h2>';
        if (empty($this->debugInfo['database_entries'])) {
            echo '<p class="error">❌ KEINE Sprachvariablen in der Datenbank gefunden!</p>';
            echo '<p><strong>Lösung:</strong> Das Plugin wurde möglicherweise nicht korrekt installiert. Deinstallieren und neu installieren.</p>';
        } else {
            echo '<p class="success">✅ ' . count($this->debugInfo['database_entries']) . ' Sprachvariablen gefunden</p>';
            echo '<table>';
            echo '<tr><th>ID</th><th>Schlüssel</th><th>Wert (gekürzt)</th><th>Kategorie-ID</th></tr>';
            foreach ($this->debugInfo['database_entries'] as $row) {
                $value = strlen($row['languageItemValue']) > 50 ? substr($row['languageItemValue'], 0, 50) . '...' : $row['languageItemValue'];
                echo '<tr><td>' . $row['languageItemID'] . '</td><td>' . htmlspecialchars($row['languageItem']) . '</td><td>' . htmlspecialchars($value) . '</td><td>' . $row['languageCategoryID'] . '</td></tr>';
            }
            echo '</table>';
        }
        
        // Optionen
        echo '<h2>4. Plugin-Optionen</h2>';
        echo '<table>';
        echo '<tr><th>Option</th><th>Konstante</th><th>Existiert</th><th>Wert</th></tr>';
        foreach ($this->debugInfo['options'] as $key => $data) {
            $exists = $data['exists'] ? '<span class="success">✅</span>' : '<span class="error">❌</span>';
            echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($data['constant']) . '</td><td>' . $exists . '</td><td>' . htmlspecialchars($data['value']) . '</td></tr>';
        }
        echo '</table>';
        
        // Tabellen
        echo '<h2>5. Datenbank-Tabellen</h2>';
        echo '<table>';
        echo '<tr><th>Tabelle</th><th>Existiert</th><th>Anzahl Zeilen</th></tr>';
        foreach ($this->debugInfo['tables'] as $table => $data) {
            $exists = $data['exists'] ? '<span class="success">✅</span>' : '<span class="error">❌</span>';
            $count = isset($data['row_count']) ? $data['row_count'] : (isset($data['error']) ? 'Fehler: ' . $data['error'] : 'N/A');
            echo '<tr><td>' . htmlspecialchars($table) . '</td><td>' . $exists . '</td><td>' . $count . '</td></tr>';
        }
        echo '</table>';
        
        // Event-Listener
        echo '<h2>6. Event-Listener</h2>';
        if (empty($this->debugInfo['event_listeners'])) {
            echo '<p class="warning">⚠️ Keine Event-Listener gefunden!</p>';
        } else {
            echo '<table>';
            echo '<tr><th>Event</th><th>Klasse</th><th>Umgebung</th></tr>';
            foreach ($this->debugInfo['event_listeners'] as $row) {
                echo '<tr><td>' . htmlspecialchars($row['eventName']) . '</td><td>' . htmlspecialchars($row['listenerClassName']) . '</td><td>' . htmlspecialchars($row['environment']) . '</td></tr>';
            }
            echo '</table>';
        }
        
        // Cache
        echo '<h2>7. Cache-Status</h2>';
        echo '<table>';
        echo '<tr><th>Cache-Typ</th><th>Anzahl</th></tr>';
        echo '<tr><td>Sprachcache-Dateien</td><td>' . count($this->debugInfo['cache']['language_files']) . '</td></tr>';
        echo '<tr><td>Template-Cache-Dateien</td><td>' . count($this->debugInfo['cache']['template_files']) . '</td></tr>';
        echo '</table>';
        
        // Empfehlungen
        echo '<h2>💡 Diagnose & Lösungen</h2>';
        echo '<div style="background: #fff; padding: 15px; border-left: 4px solid #4CAF50;">';
        
        if (empty($this->debugInfo['database_entries'])) {
            echo '<p><strong class="error">❌ HAUPTPROBLEM: Keine Sprachvariablen in Datenbank</strong></p>';
            echo '<p><strong>Lösung:</strong></p>';
            echo '<ol>';
            echo '<li>Plugin deinstallieren (ACP → Paket-Verwaltung)</li>';
            echo '<li>Alle Caches leeren (ACP → System → Daten zurücksetzen → Alle Caches)</li>';
            echo '<li>Plugin neu installieren</li>';
            echo '<li>Erneut alle Caches leeren</li>';
            echo '<li>Browser komplett neu laden (Strg+F5)</li>';
            echo '</ol>';
        } else {
            echo '<p><strong class="success">✅ Sprachvariablen sind in der Datenbank vorhanden</strong></p>';
            
            $hasErrors = false;
            foreach ($this->debugInfo['language_keys'] as $key => $data) {
                if ($data['is_key']) {
                    $hasErrors = true;
                    break;
                }
            }
            
            if ($hasErrors) {
                echo '<p><strong class="warning">⚠️ Sprachvariablen werden nicht geladen</strong></p>';
                echo '<p><strong>Lösung:</strong></p>';
                echo '<ol>';
                echo '<li>ACP → System → Daten zurücksetzen</li>';
                echo '<li>Häkchen bei "Sprachcache" setzen</li>';
                echo '<li>Häkchen bei "Template-Cache" setzen</li>';
                echo '<li>"Daten zurücksetzen" klicken</li>';
                echo '<li>Browser komplett neu laden (Strg+F5)</li>';
                echo '<li>Falls das nicht hilft: Plugin deinstallieren und neu installieren</li>';
                echo '</ol>';
            } else {
                echo '<p><strong class="success">✅ Alle Sprachvariablen werden korrekt geladen!</strong></p>';
            }
        }
        
        echo '</div>';
        
        echo '<hr style="margin: 30px 0;">';
        echo '<p><small>Debug-Seite erstellt vom Kalender Import Plugin v1.1.2+</small></p>';
        echo '</body></html>';
        
        exit;
    }
}
