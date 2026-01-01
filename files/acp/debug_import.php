<?php
/**
 * Debug Script für Calendar Import Plugin
 * Zeigt alle relevanten Informationen und testet die Import-Logik
 * 
 * Aufruf: /acp/debug_import.php
 */

// WoltLab Bootstrap
require_once(__DIR__ . '/../../lib/core.functions.php');
require_once(WCF_DIR . 'lib/system/WCF.class.php');

use wcf\system\WCF;

// Nur für Admins
if (!WCF::getUser()->userID || !WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    die('Zugriff verweigert - Admin-Login erforderlich');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Calendar Import Debug</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; line-height: 1.6; }
        h1 { color: #00d4ff; border-bottom: 2px solid #00d4ff; padding-bottom: 10px; }
        h2 { color: #00d4ff; margin-top: 30px; }
        h3 { color: #4dd0e1; }
        .success { background: #143d1e; padding: 10px; border-radius: 4px; margin: 5px 0; }
        .error { background: #3d1414; padding: 10px; border-radius: 4px; margin: 5px 0; }
        .warning { background: #3d3414; padding: 10px; border-radius: 4px; margin: 5px 0; }
        .info { background: #0f3460; padding: 10px; border-radius: 4px; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #2d3a5c; }
        th { background: #0f3460; }
        tr:nth-child(even) { background: #16213e; }
        code { background: #0f3460; padding: 2px 6px; border-radius: 3px; font-family: 'Consolas', monospace; }
        pre { background: #0f3460; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 5px; }
        .badge-ok { background: #00ff88; color: #000; }
        .badge-fail { background: #ff6b6b; color: #000; }
    </style>
</head>
<body>
<h1>🔍 Calendar Import Debug v2.0</h1>
<p>Zeitstempel: <?= date('Y-m-d H:i:s') ?></p>

<?php

// ============================================
// 1. PLUGIN STATUS
// ============================================
echo '<h2>1. Plugin Status</h2>';

try {
    $sql = "SELECT * FROM wcf1_package WHERE package LIKE '%calendar.import%'";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $package = $statement->fetchArray();
    
    if ($package) {
        echo '<div class="success">✅ Plugin gefunden: <strong>' . $package['package'] . '</strong> v' . $package['packageVersion'] . '</div>';
    } else {
        echo '<div class="error">❌ Plugin nicht gefunden!</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">❌ Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 2. KONFIGURATION
// ============================================
echo '<h2>2. Konfiguration (Optionen)</h2>';

$options = [
    'CALENDAR_IMPORT_ICS_URL' => 'ICS URL',
    'CALENDAR_IMPORT_CALENDAR_ID' => 'Kalender ID',
    'CALENDAR_IMPORT_TARGET_IMPORT_ID' => 'Ziel Import ID',
    'CALENDAR_IMPORT_CATEGORY_ID' => 'Kategorie ID',
    'CALENDAR_IMPORT_BOARD_ID' => 'Forum ID für Threads',
    'CALENDAR_IMPORT_CREATE_THREADS' => 'Threads erstellen',
    'CALENDAR_IMPORT_MAX_EVENTS' => 'Max Events',
    'CALENDAR_IMPORT_LOG_LEVEL' => 'Log Level',
    'CALENDAR_IMPORT_AUTO_MARK_PAST_READ' => 'Vergangene als gelesen',
    'CALENDAR_IMPORT_MARK_UPDATED_UNREAD' => 'Aktualisierte als ungelesen',
    'CALENDAR_IMPORT_CONVERT_TIMEZONE' => 'Zeitzone konvertieren'
];

echo '<table>';
echo '<tr><th>Option</th><th>Konstante</th><th>Wert</th><th>Status</th></tr>';
foreach ($options as $constant => $label) {
    $exists = defined($constant);
    $value = $exists ? constant($constant) : 'N/A';
    $status = $exists ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-fail">FEHLT</span>';
    echo "<tr><td>{$label}</td><td><code>{$constant}</code></td><td>" . htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : $value) . "</td><td>{$status}</td></tr>";
}
echo '</table>';

// Optionen aus Datenbank
echo '<h3>Optionen in Datenbank:</h3>';
try {
    $sql = "SELECT optionName, optionValue FROM wcf1_option WHERE optionName LIKE 'calendar_import%'";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $dbOptions = $statement->fetchAll();
    
    if (empty($dbOptions)) {
        echo '<div class="warning">⚠️ Keine Optionen in wcf1_option gefunden!</div>';
    } else {
        echo '<table><tr><th>Option Name</th><th>Wert</th></tr>';
        foreach ($dbOptions as $opt) {
            echo '<tr><td><code>' . $opt['optionName'] . '</code></td><td>' . htmlspecialchars($opt['optionValue']) . '</td></tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 3. DATENBANK TABELLEN
// ============================================
echo '<h2>3. Datenbank Tabellen</h2>';

$tablesToCheck = [
    'calendar1_calendar' => 'WoltLab Kalender',
    'calendar1_event' => 'WoltLab Events',
    'calendar1_event_date' => 'Event Datum',
    'calendar1_event_import' => 'WoltLab Import Konfiguration',
    'calendar1_ical_uid_map' => 'UID Mapping (Custom)',
    'wcf1_calendar_event_read_status' => 'Read/Unread Status',
    'wcf1_calendar_import_log' => 'Import Log',
    'wbb1_board' => 'Forum Boards',
    'wbb1_thread' => 'Forum Threads'
];

echo '<table>';
echo '<tr><th>Tabelle</th><th>Beschreibung</th><th>Existiert</th><th>Zeilen</th></tr>';
foreach ($tablesToCheck as $table => $desc) {
    try {
        $sql = "SHOW TABLES LIKE ?";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute([$table]);
        $exists = $stmt->fetchColumn() !== false;
        
        $count = 'N/A';
        if ($exists) {
            $sql2 = "SELECT COUNT(*) FROM {$table}";
            $stmt2 = WCF::getDB()->prepareStatement($sql2);
            $stmt2->execute();
            $count = $stmt2->fetchColumn();
        }
        
        $status = $exists ? '<span class="badge badge-ok">✓</span>' : '<span class="badge badge-fail">✗</span>';
        echo "<tr><td><code>{$table}</code></td><td>{$desc}</td><td>{$status}</td><td>{$count}</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td><code>{$table}</code></td><td>{$desc}</td><td colspan='2'>Fehler: " . $e->getMessage() . "</td></tr>";
    }
}
echo '</table>';

// ============================================
// 4. CALENDAR1_EVENT_IMPORT INHALT
// ============================================
echo '<h2>4. calendar1_event_import Inhalt</h2>';

try {
    $sql = "SELECT * FROM calendar1_event_import LIMIT 10";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $imports = $statement->fetchAll();
    
    if (empty($imports)) {
        echo '<div class="warning">⚠️ Keine Imports in calendar1_event_import gefunden!</div>';
    } else {
        echo '<table><tr>';
        foreach (array_keys($imports[0]) as $col) {
            echo '<th>' . htmlspecialchars($col) . '</th>';
        }
        echo '</tr>';
        foreach ($imports as $row) {
            echo '<tr>';
            foreach ($row as $key => $val) {
                $display = $val;
                if (strlen($val) > 100) $display = substr($val, 0, 100) . '...';
                echo '<td>' . htmlspecialchars($display) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="error">❌ Tabelle nicht gefunden: ' . $e->getMessage() . '</div>';
}

// ============================================
// 5. UID MAPPING PRÜFUNG
// ============================================
echo '<h2>5. UID Mapping (calendar1_ical_uid_map)</h2>';

try {
    $sql = "SHOW TABLES LIKE 'calendar1_ical_uid_map'";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $exists = $statement->fetchColumn() !== false;
    
    if (!$exists) {
        echo '<div class="error">❌ Tabelle calendar1_ical_uid_map existiert NICHT!</div>';
        echo '<div class="warning">⚠️ Diese Tabelle wird benötigt um Duplikate zu vermeiden!</div>';
        echo '<pre>-- Führe dieses SQL aus:
CREATE TABLE IF NOT EXISTS calendar1_ical_uid_map (
    mapID INT(10) NOT NULL AUTO_INCREMENT,
    eventID INT(10) NOT NULL,
    icalUID VARCHAR(255) NOT NULL,
    importID INT(10) DEFAULT NULL,
    lastUpdated INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (mapID),
    UNIQUE KEY icalUID (icalUID),
    KEY eventID (eventID),
    KEY importID (importID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>';
    } else {
        echo '<div class="success">✅ Tabelle existiert</div>';
        
        // Zeige Inhalt
        $sql = "SELECT * FROM calendar1_ical_uid_map LIMIT 10";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute();
        $mappings = $stmt->fetchAll();
        
        if (empty($mappings)) {
            echo '<div class="warning">⚠️ Tabelle ist leer - keine UIDs gemappt</div>';
        } else {
            echo '<table><tr><th>mapID</th><th>eventID</th><th>icalUID</th><th>importID</th><th>lastUpdated</th></tr>';
            foreach ($mappings as $m) {
                $uid = strlen($m['icalUID']) > 50 ? substr($m['icalUID'], 0, 50) . '...' : $m['icalUID'];
                echo "<tr><td>{$m['mapID']}</td><td>{$m['eventID']}</td><td><code>{$uid}</code></td><td>{$m['importID']}</td><td>" . date('Y-m-d H:i', $m['lastUpdated']) . "</td></tr>";
            }
            echo '</table>';
        }
        
        // Duplikate prüfen
        $sql = "SELECT icalUID, COUNT(*) as cnt FROM calendar1_ical_uid_map GROUP BY icalUID HAVING cnt > 1";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute();
        $duplicates = $stmt->fetchAll();
        
        if (!empty($duplicates)) {
            echo '<div class="error">❌ DUPLIKATE GEFUNDEN:</div>';
            echo '<table><tr><th>icalUID</th><th>Anzahl</th></tr>';
            foreach ($duplicates as $d) {
                echo "<tr><td><code>{$d['icalUID']}</code></td><td>{$d['cnt']}</td></tr>";
            }
            echo '</table>';
        } else {
            echo '<div class="success">✅ Keine doppelten UIDs</div>';
        }
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 6. CRONJOB STATUS
// ============================================
echo '<h2>6. Cronjob Status</h2>';

try {
    $sql = "SELECT cronjobID, className, isDisabled, nextExec, lastExec, fails FROM wcf1_cronjob WHERE className LIKE '%ICalImport%' OR className LIKE '%calendar%import%'";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $cronjobs = $statement->fetchAll();
    
    if (empty($cronjobs)) {
        echo '<div class="warning">⚠️ Keine Import-Cronjobs gefunden!</div>';
    } else {
        echo '<table><tr><th>ID</th><th>Klasse</th><th>Status</th><th>Letzter Lauf</th><th>Nächster Lauf</th><th>Fehler</th></tr>';
        foreach ($cronjobs as $cron) {
            $status = $cron['isDisabled'] ? '<span class="badge badge-fail">Deaktiviert</span>' : '<span class="badge badge-ok">Aktiv</span>';
            $lastExec = $cron['lastExec'] > 0 ? date('Y-m-d H:i:s', $cron['lastExec']) : 'Nie';
            $nextExec = $cron['nextExec'] > 0 ? date('Y-m-d H:i:s', $cron['nextExec']) : 'N/A';
            echo "<tr><td>{$cron['cronjobID']}</td><td><code>{$cron['className']}</code></td><td>{$status}</td><td>{$lastExec}</td><td>{$nextExec}</td><td>{$cron['fails']}</td></tr>";
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 7. PHP KLASSEN
// ============================================
echo '<h2>7. PHP Klassen</h2>';

$classes = [
    'wcf\\system\\cronjob\\ICalImportCronjob' => 'Import Cronjob (Custom)',
    'calendar\\system\\cronjob\\EventImportCronjob' => 'WoltLab Event Import (Standard)',
    'wcf\\system\\cronjob\\MarkPastEventsReadCronjob' => 'Vergangene als gelesen markieren',
    'calendar\\data\\event\\EventAction' => 'WoltLab Event Action',
    'calendar\\data\\event\\Event' => 'WoltLab Event'
];

echo '<table><tr><th>Klasse</th><th>Beschreibung</th><th>Status</th></tr>';
foreach ($classes as $class => $desc) {
    $exists = class_exists($class);
    $status = $exists ? '<span class="badge badge-ok">Gefunden</span>' : '<span class="badge badge-fail">Nicht gefunden</span>';
    echo "<tr><td><code>{$class}</code></td><td>{$desc}</td><td>{$status}</td></tr>";
}
echo '</table>';

// ============================================
// 8. ICS URL TEST
// ============================================
echo '<h2>8. ICS URL Test</h2>';

$icsUrl = defined('CALENDAR_IMPORT_ICS_URL') ? CALENDAR_IMPORT_ICS_URL : '';

if (empty($icsUrl)) {
    // Versuche aus calendar1_event_import zu holen
    try {
        $sql = "SELECT url FROM calendar1_event_import WHERE isDisabled = 0 LIMIT 1";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute();
        $row = $stmt->fetchArray();
        if ($row) {
            $icsUrl = $row['url'];
            echo '<div class="info">ℹ️ URL aus calendar1_event_import verwendet</div>';
        }
    } catch (Exception $e) {}
}

if (empty($icsUrl)) {
    echo '<div class="warning">⚠️ Keine ICS-URL konfiguriert</div>';
} else {
    echo '<div class="info">URL: <code>' . htmlspecialchars($icsUrl) . '</code></div>';
    
    // Fetch test
    $context = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'WoltLab Debug/1.0'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    
    $content = @file_get_contents($icsUrl, false, $context);
    
    if ($content === false) {
        echo '<div class="error">❌ ICS-URL nicht erreichbar</div>';
        
        // cURL Fallback
        if (function_exists('curl_init')) {
            $ch = curl_init($icsUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($content !== false && $httpCode == 200) {
                echo '<div class="success">✅ cURL erfolgreich (HTTP ' . $httpCode . ')</div>';
            } else {
                echo '<div class="error">❌ cURL Fehler: ' . $error . ' (HTTP ' . $httpCode . ')</div>';
            }
        }
    } else {
        echo '<div class="success">✅ ICS-URL erreichbar</div>';
        echo '<div class="info">Größe: ' . number_format(strlen($content)) . ' Bytes</div>';
        
        // Events zählen
        $eventCount = substr_count($content, 'BEGIN:VEVENT');
        echo '<div class="info">Gefundene Events: <strong>' . $eventCount . '</strong></div>';
        
        // Erste 3 UIDs zeigen
        preg_match_all('/UID:(.+)/m', $content, $matches);
        if (!empty($matches[1])) {
            echo '<h3>Beispiel UIDs aus ICS:</h3><ul>';
            foreach (array_slice($matches[1], 0, 5) as $uid) {
                echo '<li><code>' . htmlspecialchars(trim($uid)) . '</code></li>';
            }
            echo '</ul>';
        }
    }
}

// ============================================
// 9. EVENT DUPLIKATE PRÜFUNG
// ============================================
echo '<h2>9. Event Duplikate Prüfung</h2>';

try {
    // Prüfe ob externalSource Spalte existiert
    $sql = "SHOW COLUMNS FROM calendar1_event LIKE 'externalSource'";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute();
    $hasExternalSource = $stmt->fetchColumn() !== false;
    
    if ($hasExternalSource) {
        echo '<div class="success">✅ calendar1_event hat externalSource Spalte</div>';
        
        // Duplikate über externalSource
        $sql = "SELECT externalSource, COUNT(*) as cnt FROM calendar1_event WHERE externalSource IS NOT NULL AND externalSource != '' GROUP BY externalSource HAVING cnt > 1";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute();
        $dupes = $stmt->fetchAll();
        
        if (!empty($dupes)) {
            echo '<div class="error">❌ ' . count($dupes) . ' doppelte Events gefunden!</div>';
            echo '<table><tr><th>externalSource (UID)</th><th>Anzahl</th></tr>';
            foreach (array_slice($dupes, 0, 10) as $d) {
                $uid = strlen($d['externalSource']) > 60 ? substr($d['externalSource'], 0, 60) . '...' : $d['externalSource'];
                echo "<tr><td><code>{$uid}</code></td><td>{$d['cnt']}</td></tr>";
            }
            echo '</table>';
            
            echo '<div class="warning">⚠️ Führe folgendes SQL aus um Duplikate zu löschen (behalte nur das neueste):</div>';
            echo '<pre>DELETE e1 FROM calendar1_event e1
INNER JOIN calendar1_event e2 
WHERE e1.externalSource = e2.externalSource 
AND e1.eventID < e2.eventID 
AND e1.externalSource IS NOT NULL 
AND e1.externalSource != "";</pre>';
        } else {
            echo '<div class="success">✅ Keine doppelten Events</div>';
        }
    } else {
        echo '<div class="warning">⚠️ calendar1_event hat KEINE externalSource Spalte</div>';
        echo '<div class="info">Das erklärt warum Events doppelt importiert werden - die UID kann nicht gespeichert werden!</div>';
        echo '<div class="warning">Nutze stattdessen die calendar1_ical_uid_map Tabelle.</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 10. READ/UNREAD STATUS
// ============================================
echo '<h2>10. Read/Unread Status</h2>';

try {
    $sql = "SELECT COUNT(*) FROM wcf1_calendar_event_read_status";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute();
    $count = $stmt->fetchColumn();
    echo '<div class="info">Einträge in wcf1_calendar_event_read_status: ' . $count . '</div>';
    
    if ($count > 0) {
        $sql = "SELECT * FROM wcf1_calendar_event_read_status LIMIT 5";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute();
        $entries = $stmt->fetchAll();
        
        echo '<table><tr><th>eventID</th><th>userID</th><th>isRead</th><th>lastVisitTime</th></tr>';
        foreach ($entries as $e) {
            $read = $e['isRead'] ? '<span class="badge badge-ok">Gelesen</span>' : '<span class="badge badge-fail">Ungelesen</span>';
            echo "<tr><td>{$e['eventID']}</td><td>{$e['userID']}</td><td>{$read}</td><td>" . date('Y-m-d H:i', $e['lastVisitTime']) . "</td></tr>";
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="warning">Tabelle existiert nicht oder Fehler: ' . $e->getMessage() . '</div>';
}

// WoltLab Standard Tracking
echo '<h3>WoltLab Standard Tracking (wcf1_tracked_visit)</h3>';
try {
    // ObjectType für Calendar Events finden
    $sql = "SELECT objectTypeID FROM wcf1_object_type WHERE objectType = 'com.woltlab.calendar.event'";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute();
    $objectTypeID = $stmt->fetchColumn();
    
    if ($objectTypeID) {
        echo '<div class="success">✅ ObjectType gefunden: ' . $objectTypeID . '</div>';
        
        $sql = "SELECT COUNT(*) FROM wcf1_tracked_visit WHERE objectTypeID = ?";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute([$objectTypeID]);
        $count = $stmt->fetchColumn();
        echo '<div class="info">Tracking-Einträge: ' . $count . '</div>';
    } else {
        echo '<div class="warning">⚠️ ObjectType com.woltlab.calendar.event nicht gefunden</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 11. FORUM/THREAD ERSTELLUNG
// ============================================
echo '<h2>11. Forum/Thread Erstellung</h2>';

$boardID = defined('CALENDAR_IMPORT_BOARD_ID') ? (int)CALENDAR_IMPORT_BOARD_ID : 0;
$createThreads = defined('CALENDAR_IMPORT_CREATE_THREADS') ? CALENDAR_IMPORT_CREATE_THREADS : false;

echo '<div class="info">Board ID: ' . $boardID . '</div>';
echo '<div class="info">Threads erstellen: ' . ($createThreads ? 'Ja' : 'Nein') . '</div>';

if ($boardID > 0) {
    try {
        $sql = "SELECT boardID, title FROM wbb1_board WHERE boardID = ?";
        $stmt = WCF::getDB()->prepareStatement($sql);
        $stmt->execute([$boardID]);
        $board = $stmt->fetchArray();
        
        if ($board) {
            echo '<div class="success">✅ Forum gefunden: ' . htmlspecialchars($board['title']) . ' (ID: ' . $board['boardID'] . ')</div>';
        } else {
            echo '<div class="error">❌ Forum mit ID ' . $boardID . ' existiert nicht!</div>';
        }
    } catch (Exception $e) {
        echo '<div class="warning">⚠️ wbb1_board Tabelle nicht gefunden - Forum Plugin nicht installiert?</div>';
    }
} else {
    echo '<div class="warning">⚠️ Keine Board ID konfiguriert - Thread-Erstellung deaktiviert</div>';
}

// ============================================
// 12. LETZTE EVENTS
// ============================================
echo '<h2>12. Letzte importierte Events</h2>';

try {
    $sql = "SELECT eventID, subject, time, userID FROM calendar1_event ORDER BY eventID DESC LIMIT 10";
    $stmt = WCF::getDB()->prepareStatement($sql);
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    if (!empty($events)) {
        echo '<table><tr><th>eventID</th><th>Subject</th><th>Zeit</th><th>User</th></tr>';
        foreach ($events as $e) {
            echo "<tr><td>{$e['eventID']}</td><td>" . htmlspecialchars($e['subject']) . "</td><td>" . date('Y-m-d H:i', $e['time']) . "</td><td>{$e['userID']}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<div class="warning">Keine Events gefunden</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
}

// ============================================
// 13. EMPFEHLUNGEN
// ============================================
echo '<h2>13. Empfehlungen</h2>';

echo '<div class="info">';
echo '<h3>Bekannte Probleme:</h3>';
echo '<ol>';
echo '<li><strong>3-fache Events:</strong> Die calendar1_event Tabelle hat keine externalSource Spalte, oder die UID-Mapping Tabelle fehlt.</li>';
echo '<li><strong>Threads werden nicht erstellt:</strong> Board ID ist 0 oder CREATE_THREADS ist false.</li>';
echo '<li><strong>Gelesen/Ungelesen funktioniert nicht:</strong> Die Read-Status Tabelle wird nicht befüllt oder WoltLab nutzt eigenes Tracking.</li>';
echo '</ol>';
echo '</div>';

?>

<h2>14. Aktionen</h2>
<div class="info">
    <a href="?action=test_import" style="background: #00d4ff; color: #000; padding: 10px 20px; border-radius: 4px; text-decoration: none; display: inline-block; margin: 5px;">🧪 Test-Import ausführen</a>
    <a href="?action=clear_duplicates" style="background: #ff6b6b; color: #000; padding: 10px 20px; border-radius: 4px; text-decoration: none; display: inline-block; margin: 5px;">🗑️ Duplikate entfernen</a>
</div>

<?php
// Aktionen verarbeiten
if (isset($_GET['action'])) {
    echo '<h2>Aktion: ' . htmlspecialchars($_GET['action']) . '</h2>';
    
    if ($_GET['action'] === 'test_import') {
        echo '<div class="warning">Test-Import noch nicht implementiert - nutze den ACP Button</div>';
    }
    
    if ($_GET['action'] === 'clear_duplicates') {
        try {
            // Duplikate löschen basierend auf externalSource
            $sql = "DELETE e1 FROM calendar1_event e1
                    INNER JOIN calendar1_event e2 
                    WHERE e1.externalSource = e2.externalSource 
                    AND e1.eventID < e2.eventID 
                    AND e1.externalSource IS NOT NULL 
                    AND e1.externalSource != ''";
            $stmt = WCF::getDB()->prepareStatement($sql);
            $stmt->execute();
            $deleted = $stmt->getAffectedRows();
            echo '<div class="success">✅ ' . $deleted . ' doppelte Events gelöscht!</div>';
        } catch (Exception $e) {
            echo '<div class="error">Fehler: ' . $e->getMessage() . '</div>';
        }
    }
}
?>

</body>
</html>
