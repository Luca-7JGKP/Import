<?php
/**
 * Calendar Import Debug Script
 * Dieses Script analysiert die komplette Datenbank-Struktur und Konfiguration
 * 
 * Aufruf: /debug.php (im WCF-Verzeichnis platzieren)
 */

// WCF Bootstrap
require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Calendar Import Debug</title>';
echo '<style>
body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 20px; }
h1 { color: #00d4ff; }
h2 { color: #00d4ff; margin-top: 30px; border-bottom: 1px solid #333; padding-bottom: 10px; }
h3 { color: #ffa500; }
.success { background: #143d1e; padding: 10px; border-radius: 4px; margin: 5px 0; }
.error { background: #3d1414; padding: 10px; border-radius: 4px; margin: 5px 0; }
.warning { background: #3d3414; padding: 10px; border-radius: 4px; margin: 5px 0; }
.info { background: #0f3460; padding: 10px; border-radius: 4px; margin: 5px 0; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #333; padding: 8px; text-align: left; }
th { background: #0f3460; }
tr:nth-child(even) { background: #1f1f3d; }
pre { background: #0a0a1a; padding: 15px; border-radius: 4px; overflow-x: auto; }
.box { background: #0f0f2a; padding: 15px; border-radius: 8px; margin: 10px 0; }
</style></head><body>';

echo '<h1>🔍 Calendar Import Debug Report</h1>';
echo '<p>Erstellt: ' . date('Y-m-d H:i:s') . '</p>';

// ============================================
// 1. ALLE TABELLEN IN DER DATENBANK
// ============================================
echo '<h2>📊 1. Alle Datenbank-Tabellen</h2>';

try {
    $sql = "SHOW TABLES";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $allTables = [];
    $calendarTables = [];
    $wcfTables = [];
    
    while ($row = $statement->fetchColumn()) {
        $allTables[] = $row;
        if (stripos($row, 'calendar') !== false) {
            $calendarTables[] = $row;
        }
        if (stripos($row, 'wcf') !== false && stripos($row, 'calendar') !== false) {
            $wcfTables[] = $row;
        }
    }
    
    echo '<div class="box">';
    echo '<h3>Kalender-relevante Tabellen (' . count($calendarTables) . ' gefunden):</h3>';
    if (count($calendarTables) > 0) {
        echo '<div class="success">';
        foreach ($calendarTables as $t) {
            echo '✅ ' . $t . '<br>';
        }
        echo '</div>';
    } else {
        echo '<div class="error">❌ Keine Kalender-Tabellen gefunden!</div>';
    }
    
    echo '<h3>Alle Tabellen (' . count($allTables) . ' total):</h3>';
    echo '<pre>' . implode("\n", $allTables) . '</pre>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 2. TABELLEN-STRUKTUREN
// ============================================
echo '<h2>🏗️ 2. Tabellen-Strukturen (Kalender-relevante)</h2>';

$tablesToCheck = [
    'calendar1_event',
    'calendar1_event_import', 
    'calendar1_event_date',
    'calendar1_ical_uid_map',
    'wcf1_calendar_import_log',
    'wcf1_option'
];

foreach ($tablesToCheck as $table) {
    echo '<div class="box">';
    echo '<h3>Tabelle: ' . $table . '</h3>';
    
    try {
        // Prüfe ob Tabelle existiert
        $sql = "SHOW TABLES LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$table]);
        $exists = $statement->fetchColumn() !== false;
        
        if ($exists) {
            echo '<div class="success">✅ Tabelle existiert</div>';
            
            // Zeige Struktur - Use identifier quoting to prevent SQL injection
            $sql = "DESCRIBE " . WCF::getDB()->escapeString($table);
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            
            echo '<table><tr><th>Feld</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th></tr>';
            while ($row = $statement->fetchArray()) {
                echo '<tr>';
                echo '<td>' . $row['Field'] . '</td>';
                echo '<td>' . $row['Type'] . '</td>';
                echo '<td>' . $row['Null'] . '</td>';
                echo '<td>' . $row['Key'] . '</td>';
                echo '<td>' . ($row['Default'] ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
            // Zeige Anzahl Einträge - Use identifier quoting to prevent SQL injection
            $sql = "SELECT COUNT(*) as cnt FROM " . WCF::getDB()->escapeString($table);
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $count = $statement->fetchColumn();
            echo '<div class="info">📊 Anzahl Einträge: ' . $count . '</div>';
            
        } else {
            echo '<div class="error">❌ Tabelle existiert NICHT</div>';
        }
        
    } catch (Exception $e) {
        echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
    }
    
    echo '</div>';
}

// ============================================
// 3. WCF OPTIONS (calendar_import_*)
// ============================================
echo '<h2>⚙️ 3. Plugin-Optionen (wcf1_option)</h2>';

try {
    $sql = "SELECT optionID, optionName, optionValue, categoryName FROM wcf1_option WHERE optionName LIKE ? ORDER BY optionName";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%']);
    
    $options = [];
    while ($row = $statement->fetchArray()) {
        $options[] = $row;
    }
    
    if (count($options) > 0) {
        echo '<div class="box">';
        echo '<table><tr><th>ID</th><th>Name</th><th>Wert</th><th>Kategorie</th></tr>';
        foreach ($options as $opt) {
            echo '<tr>';
            echo '<td>' . $opt['optionID'] . '</td>';
            echo '<td>' . $opt['optionName'] . '</td>';
            echo '<td><code>' . htmlspecialchars($opt['optionValue']) . '</code></td>';
            echo '<td>' . $opt['categoryName'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="warning">⚠️ Keine calendar-Optionen in wcf1_option gefunden!</div>';
        
        // Zeige alle verfügbaren Optionen
        echo '<h3>Alle Option-Kategorien:</h3>';
        $sql = "SELECT DISTINCT categoryName FROM wcf1_option ORDER BY categoryName";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        echo '<pre>';
        while ($row = $statement->fetchColumn()) {
            echo $row . "\n";
        }
        echo '</pre>';
    }
    
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 4. PAKETE
// ============================================
echo '<h2>📦 4. Installierte Pakete (Kalender-relevant)</h2>';

try {
    $sql = "SELECT packageID, package, packageVersion, packageDate, isApplication FROM wcf1_package WHERE package LIKE ? OR package LIKE ? ORDER BY package";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%', '%import%']);
    
    echo '<div class="box">';
    echo '<table><tr><th>ID</th><th>Package</th><th>Version</th><th>Datum</th><th>App?</th></tr>';
    while ($row = $statement->fetchArray()) {
        echo '<tr>';
        echo '<td>' . $row['packageID'] . '</td>';
        echo '<td>' . $row['package'] . '</td>';
        echo '<td>' . $row['packageVersion'] . '</td>';
        echo '<td>' . $row['packageDate'] . '</td>';
        echo '<td>' . ($row['isApplication'] ? 'Ja' : 'Nein') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 5. CRONJOBS
// ============================================
echo '<h2>⏰ 5. Cronjobs</h2>';

try {
    $sql = "SELECT cronjobID, className, packageID, isDisabled, nextExec, lastExec FROM wcf1_cronjob WHERE className LIKE ? OR className LIKE ? OR className LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%Calendar%', '%Import%', '%ICS%']);
    
    echo '<div class="box">';
    echo '<table><tr><th>ID</th><th>Klasse</th><th>Package ID</th><th>Deaktiviert</th><th>Nächste Ausführung</th><th>Letzte Ausführung</th></tr>';
    $found = false;
    while ($row = $statement->fetchArray()) {
        $found = true;
        echo '<tr>';
        echo '<td>' . $row['cronjobID'] . '</td>';
        echo '<td>' . $row['className'] . '</td>';
        echo '<td>' . $row['packageID'] . '</td>';
        echo '<td>' . ($row['isDisabled'] ? '🔴 Ja' : '🟢 Nein') . '</td>';
        echo '<td>' . ($row['nextExec'] ? date('Y-m-d H:i:s', $row['nextExec']) : '-') . '</td>';
        echo '<td>' . ($row['lastExec'] ? date('Y-m-d H:i:s', $row['lastExec']) : '-') . '</td>';
        echo '</tr>';
    }
    if (!$found) {
        echo '<tr><td colspan="6">Keine relevanten Cronjobs gefunden</td></tr>';
    }
    echo '</table>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 6. PHP KLASSEN CHECK
// ============================================
echo '<h2>🐘 6. PHP Klassen</h2>';

$classesToCheck = [
    'wcf\\system\\cronjob\\ICalImportCronjob',
    'wcf\\acp\\form\\CalendarImportSettingsForm',
    'wcf\\data\\user\\User'
];

echo '<div class="box">';
foreach ($classesToCheck as $class) {
    $exists = class_exists($class);
    if ($exists) {
        echo '<div class="success">✅ ' . $class . '</div>';
    } else {
        echo '<div class="error">❌ ' . $class . ' - NICHT GEFUNDEN</div>';
    }
}
echo '</div>';

// ============================================
// 7. DATEISYSTEM CHECK
// ============================================
echo '<h2>📁 7. Dateisystem</h2>';

$filesToCheck = [
    WCF_DIR . 'lib/system/cronjob/ICalImportCronjob.class.php',
    WCF_DIR . 'lib/acp/form/CalendarImportSettingsForm.class.php',
    WCF_DIR . 'acp/templates/calendarImportSettings.tpl'
];

echo '<div class="box">';
foreach ($filesToCheck as $file) {
    $exists = file_exists($file);
    $shortPath = str_replace(WCF_DIR, '/', $file);
    if ($exists) {
        $size = filesize($file);
        $modified = date('Y-m-d H:i:s', filemtime($file));
        echo '<div class="success">✅ ' . $shortPath . ' (' . $size . ' bytes, ' . $modified . ')</div>';
    } else {
        echo '<div class="error">❌ ' . $shortPath . ' - NICHT GEFUNDEN</div>';
    }
}
echo '</div>';

// ============================================
// 8. DATEN AUS calendar1_event_import (falls vorhanden)
// ============================================
echo '<h2>📥 8. Import-Konfigurationen</h2>';

try {
    $sql = "SHOW TABLES LIKE 'calendar1_event_import'";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    if ($statement->fetchColumn() !== false) {
        $sql = "SELECT * FROM calendar1_event_import LIMIT 10";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        
        echo '<div class="box">';
        $first = true;
        while ($row = $statement->fetchArray()) {
            if ($first) {
                echo '<table><tr>';
                foreach (array_keys($row) as $key) {
                    if (!is_numeric($key)) {
                        echo '<th>' . $key . '</th>';
                    }
                }
                echo '</tr>';
                $first = false;
            }
            echo '<tr>';
            foreach ($row as $key => $value) {
                if (!is_numeric($key)) {
                    echo '<td>' . htmlspecialchars(substr($value ?? '', 0, 50)) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="warning">⚠️ Tabelle calendar1_event_import existiert nicht</div>';
    }
    
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 9. EMPFEHLUNGEN
// ============================================
echo '<h2>💡 9. Empfehlungen</h2>';
echo '<div class="box">';
echo '<p>Basierend auf der Analyse:</p>';
echo '<ol>';
echo '<li>Prüfe ob das Kalender-Plugin (z.B. com.woltlab.calendar) installiert ist</li>';
echo '<li>Die Tabellen calendar1_* gehören zum Kalender-Plugin, nicht zum Import-Plugin</li>';
echo '<li>Stelle sicher, dass die Optionen in options.xml korrekt definiert sind</li>';
echo '<li>Nach Plugin-Installation: Cache leeren</li>';
echo '</ol>';
echo '</div>';

echo '<h2>🔧 10. Schnell-Aktionen</h2>';
echo '<div class="box">';
echo '<p><a href="?action=create_tables" style="color: #00d4ff;">► Fehlende Tabellen erstellen</a></p>';
echo '<p><a href="?action=test_save" style="color: #00d4ff;">► Test: Option speichern</a></p>';
echo '</div>';

// Aktionen
if (isset($_GET['action'])) {
    echo '<h2>🔄 Aktion ausgeführt</h2>';
    
    if ($_GET['action'] === 'create_tables') {
        echo '<div class="box">';
        
        // UID Map Tabelle
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
            echo '<div class="success">✅ calendar1_ical_uid_map erstellt/geprüft</div>';
        } catch (Exception $e) {
            echo '<div class="error">❌ calendar1_ical_uid_map: ' . $e->getMessage() . '</div>';
        }
        
        // Log Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS wcf1_calendar_import_log (
                logID INT(10) NOT NULL AUTO_INCREMENT,
                eventUID VARCHAR(255) NOT NULL,
                eventID INT(10) NOT NULL DEFAULT 0,
                action VARCHAR(50) NOT NULL,
                importTime INT(10) NOT NULL,
                message TEXT,
                logLevel VARCHAR(20) DEFAULT 'info',
                PRIMARY KEY (logID),
                KEY eventUID (eventUID),
                KEY importTime (importTime)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            WCF::getDB()->prepareStatement($sql)->execute();
            echo '<div class="success">✅ wcf1_calendar_import_log erstellt/geprüft</div>';
        } catch (Exception $e) {
            echo '<div class="error">❌ wcf1_calendar_import_log: ' . $e->getMessage() . '</div>';
        }
        
        echo '</div>';
    }
    
    if ($_GET['action'] === 'test_save') {
        echo '<div class="box">';
        
        try {
            // Test: Option lesen
            $sql = "SELECT optionValue FROM wcf1_option WHERE optionName = 'calendar_import_user_id'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $current = $statement->fetchColumn();
            echo '<div class="info">Aktueller Wert von calendar_import_user_id: ' . var_export($current, true) . '</div>';
            
            // Test: Option schreiben
            $testValue = time();
            $sql = "UPDATE wcf1_option SET optionValue = ? WHERE optionName = 'calendar_import_user_id'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $result = $statement->execute([$testValue]);
            
            // Verifizieren
            $sql = "SELECT optionValue FROM wcf1_option WHERE optionName = 'calendar_import_user_id'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $newValue = $statement->fetchColumn();
            
            if ($newValue == $testValue) {
                echo '<div class="success">✅ Schreiben funktioniert! Wert: ' . $newValue . '</div>';
                
                // Zurücksetzen
                $sql = "UPDATE wcf1_option SET optionValue = ? WHERE optionName = 'calendar_import_user_id'";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$current ?: 1]);
                echo '<div class="info">Wert zurückgesetzt auf: ' . ($current ?: 1) . '</div>';
            } else {
                echo '<div class="error">❌ Schreiben fehlgeschlagen!</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
        }
        
        echo '</div>';
    }
}

echo '</body></html>';
