<?php
/**
 * Kalender Import Plugin - Debug-Script v6
 * Vollständige Debug-Seite mit Datenbank-Tabellen-Scan und manuellem Import-Trigger
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.7.2
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

// Berechtigungsprüfung: Nur Administratoren erlauben
if (!WCF::getUser()->userID || !WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Zugriff verweigert</title></head><body><h1>Zugriff verweigert</h1></body></html>';
    exit;
}

// Manueller Import-Trigger
$manualImportResult = null;
if (isset($_POST['runImport']) && $_POST['runImport'] === '1') {
    $manualImportResult = runManualImport();
}

function runManualImport() {
    $result = ['success' => false, 'message' => '', 'details' => []];
    
    try {
        if (!class_exists('wcf\\system\\cronjob\\ICalImportCronjob')) {
            $result['message'] = 'ICalImportCronjob Klasse nicht gefunden';
            return $result;
        }
        
        // Cronjob-Objekt holen
        $sql = "SELECT * FROM wcf".WCF_N."_cronjob WHERE className LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['%ICalImportCronjob%']);
        $cronjobData = $statement->fetchArray();
        
        if (!$cronjobData) {
            $result['message'] = 'Cronjob nicht in Datenbank gefunden';
            return $result;
        }
        
        $cronjob = new \wcf\data\cronjob\Cronjob(null, $cronjobData);
        $cronjobInstance = new \wcf\system\cronjob\ICalImportCronjob();
        $cronjobInstance->execute($cronjob);
        
        $result['success'] = true;
        $result['message'] = 'Import wurde ausgeführt! Prüfen Sie die Error-Logs für Details.';
        
        // LastExec aktualisieren
        $sql = "UPDATE wcf".WCF_N."_cronjob SET lastExec = ? WHERE cronjobID = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([TIME_NOW, $cronjobData['cronjobID']]);
        
    } catch (\Exception $e) {
        $result['message'] = 'Fehler: ' . $e->getMessage();
        $result['details'][] = $e->getTraceAsString();
    }
    
    return $result;
}

// Helper-Funktion zum Abrufen von Optionen
function getOptionValue($optionName, $default = null) {
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

// ICS-Test Funktion
function testIcsUrl($url) {
    $result = ['url' => $url, 'reachable' => false, 'statusCode' => null, 'eventCount' => 0, 'sampleEvents' => [], 'error' => null];
    
    if (empty($url)) {
        $result['error'] = 'Keine ICS-URL konfiguriert';
        return $result;
    }
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.7.2'
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $result['statusCode'] = $httpCode;
        
        if ($curlError) {
            $result['error'] = 'cURL Fehler: ' . $curlError;
            return $result;
        }
        
        if ($httpCode == 200 && $content) {
            $result['reachable'] = true;
            preg_match_all('/BEGIN:VEVENT/', $content, $matches);
            $result['eventCount'] = count($matches[0]);
            
            if (preg_match_all('/SUMMARY[^:]*:([^\r\n]+)/i', $content, $summaries)) {
                $result['sampleEvents'] = array_slice($summaries[1], 0, 5);
            }
        } else {
            $result['error'] = 'HTTP Fehler: ' . $httpCode;
        }
    } catch (\Exception $e) {
        $result['error'] = 'Exception: ' . $e->getMessage();
    }
    
    return $result;
}

// ALLE Tabellen in der Datenbank finden, die "calendar" enthalten
function findAllCalendarTables() {
    $tables = [];
    try {
        $sql = "SHOW TABLES";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray(\PDO::FETCH_NUM)) {
            $tableName = $row[0];
            if (stripos($tableName, 'calendar') !== false) {
                $tables[] = $tableName;
            }
        }
    } catch (\Exception $e) {
        $tables['error'] = $e->getMessage();
    }
    return $tables;
}

// Kalender aus Datenbank laden (direkte Abfrage)
function loadCalendarsDirectly() {
    $calendars = [];
    $error = null;
    
    // Versuche verschiedene Tabellennamen
    $tableNames = ['calendar1_calendar', 'calendar' . WCF_N . '_calendar'];
    
    foreach ($tableNames as $tableName) {
        try {
            $sql = "SELECT calendarID, title FROM " . $tableName . " ORDER BY calendarID ASC";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            while ($row = $statement->fetchArray()) {
                $calendars[] = $row;
            }
            if (!empty($calendars)) {
                return ['calendars' => $calendars, 'source' => $tableName, 'error' => null];
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    return ['calendars' => [], 'source' => null, 'error' => $error];
}

// Kalender-ID Validierung
function validateCalendarID($calendarID) {
    if ($calendarID <= 0) {
        return ['valid' => false, 'message' => 'Keine Kalender-ID konfiguriert (Wert: ' . $calendarID . ')'];
    }
    
    try {
        $sql = "SELECT calendarID, title FROM calendar1_calendar WHERE calendarID = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$calendarID]);
        $calendar = $statement->fetchArray();
        
        if ($calendar) {
            return ['valid' => true, 'message' => 'Kalender gefunden: ' . $calendar['title'], 'calendar' => $calendar];
        }
    } catch (\Exception $e) {}
    
    return ['valid' => false, 'message' => 'Kalender mit ID ' . $calendarID . ' existiert NICHT in der Datenbank!'];
}

// Import-Log abrufen
function getImportLog() {
    $logs = [];
    try {
        $sql = "SELECT * FROM calendar1_event_import_log ORDER BY importTime DESC LIMIT 10";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $logs[] = $row;
        }
    } catch (\Exception $e) {
        // Tabelle existiert möglicherweise nicht
    }
    return $logs;
}

header('Content-Type: text/html; charset=utf-8');

// Daten sammeln
$icsUrl = getOptionValue('calendar_import_ics_url', '');
$calendarID = (int)getOptionValue('calendar_import_calendar_id', 0);
$icsTestResult = testIcsUrl($icsUrl);

// Alle Calendar-Tabellen finden
$allCalendarTables = findAllCalendarTables();

// Kalender direkt aus DB laden
$calendarResult = loadCalendarsDirectly();
$calendars = $calendarResult['calendars'];
$calendarSource = $calendarResult['source'];
$calendarError = $calendarResult['error'];

// Kalender-ID Validierung
$calendarValidation = validateCalendarID($calendarID);

// Import-Logs
$importLogs = getImportLog();

// Package Info
$package = null;
try {
    $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package = ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['com.lucaberwind.wcf.calendar.import']);
    $package = $statement->fetchArray();
} catch (\Exception $e) {}

// Cronjobs
$cronjobs = [];
try {
    $sql = "SELECT cronjobID, className, isDisabled, nextExec, lastExec FROM wcf".WCF_N."_cronjob WHERE className LIKE ? OR className LIKE ? OR className LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%ICalImport%', '%FixTimezone%', '%MarkPastEvents%']);
    while ($row = $statement->fetchArray()) {
        $cronjobs[] = $row;
    }
} catch (\Exception $e) {}

// PHP-Klassen prüfen
$cronjobClasses = [
    'wcf\\system\\cronjob\\ICalImportCronjob',
    'wcf\\system\\cronjob\\FixTimezoneCronjob',
    'wcf\\system\\cronjob\\MarkPastEventsReadCronjob'
];

// Optionen
$options = [];
try {
    $sql = "SELECT optionName, optionValue FROM wcf".WCF_N."_option WHERE optionName LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['calendar_import%']);
    while ($row = $statement->fetchArray()) {
        $options[$row['optionName']] = $row['optionValue'];
    }
} catch (\Exception $e) {}

// Calendar Packages
$calendarPackages = [];
try {
    $sql = "SELECT package, packageVersion FROM wcf".WCF_N."_package WHERE package LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%']);
    while ($row = $statement->fetchArray()) {
        $calendarPackages[] = $row;
    }
} catch (\Exception $e) {}

// Event Listener
$eventListeners = [];
if ($package) {
    try {
        $sql = "SELECT * FROM wcf".WCF_N."_event_listener WHERE packageID = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$package['packageID']]);
        while ($row = $statement->fetchArray()) {
            $eventListeners[] = $row;
        }
    } catch (\Exception $e) {}
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalender Import - Debug v6</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00d4ff; border-bottom: 2px solid #00d4ff; padding-bottom: 15px; }
        h2 { color: #ff6b6b; margin-top: 30px; border-bottom: 1px solid #2d3a5c; padding-bottom: 10px; }
        h3 { color: #feca57; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #2d3a5c; }
        th { background: #0f3460; color: #00d4ff; font-weight: 600; }
        tr:hover { background: #1e3a5f; }
        .ok { color: #00ff88; font-weight: bold; }
        .error { color: #ff6b6b; font-weight: bold; }
        .warning { color: #feca57; font-weight: bold; }
        .success-box { background: #143d1e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00ff88; }
        .error-box { background: #3d1414; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ff6b6b; }
        .warning-box { background: #3d2914; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #feca57; }
        .info-box { background: #142d3d; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00d4ff; }
        code { background: #0f3460; padding: 2px 8px; border-radius: 4px; font-family: Consolas, monospace; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85em; margin: 2px; }
        .badge-ok { background: #143d1e; color: #00ff88; }
        .badge-error { background: #3d1414; color: #ff6b6b; }
        .sample-event { background: #0f3460; padding: 8px 12px; border-radius: 4px; margin: 5px 0; font-size: 0.9em; }
        .calendar-badge { display: inline-block; background: #0f3460; padding: 8px 15px; border-radius: 6px; margin: 5px; }
        .calendar-badge strong { color: #00d4ff; }
        .table-list { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
        .table-item { background: #0f3460; padding: 6px 12px; border-radius: 4px; font-family: monospace; font-size: 0.9em; }
        .btn { background: #00d4ff; color: #1a1a2e; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 1em; }
        .btn:hover { background: #00a8cc; }
        .btn-danger { background: #ff6b6b; }
        .btn-danger:hover { background: #ee5a5a; }
        .action-box { background: #0f3460; padding: 20px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Kalender Import - Debug v6</h1>
    <p>Zeitstempel: <strong><?= date('Y-m-d H:i:s') ?></strong> | WCF_N: <strong><?= WCF_N ?></strong></p>
    
    <?php if ($manualImportResult): ?>
        <?php if ($manualImportResult['success']): ?>
            <div class="success-box"><strong>✅ <?= htmlspecialchars($manualImportResult['message']) ?></strong></div>
        <?php else: ?>
            <div class="error-box"><strong>❌ <?= htmlspecialchars($manualImportResult['message']) ?></strong></div>
        <?php endif; ?>
    <?php endif; ?>
    
    <div class="action-box">
        <h3 style="margin-top: 0; color: #00d4ff;">🚀 Manueller Import</h3>
        <p>Klicke den Button um den Import sofort auszuführen (ohne auf den Cronjob zu warten):</p>
        <form method="post">
            <input type="hidden" name="runImport" value="1">
            <button type="submit" class="btn">Import jetzt ausführen</button>
        </form>
    </div>
    
    <h2>1. Plugin-Installation</h2>
    <?php if ($package): ?>
        <div class="success-box">Plugin gefunden: <strong><?= htmlspecialchars($package['package']) ?></strong> v<?= htmlspecialchars($package['packageVersion']) ?></div>
    <?php else: ?>
        <div class="error-box">Plugin nicht gefunden!</div>
    <?php endif; ?>
    
    <h2>2. Kalender-ID Validierung</h2>
    <?php if ($calendarValidation['valid']): ?>
        <div class="success-box">✅ <?= htmlspecialchars($calendarValidation['message']) ?></div>
    <?php else: ?>
        <div class="error-box">❌ <?= htmlspecialchars($calendarValidation['message']) ?></div>
        <?php if (!empty($calendars)): ?>
            <div class="info-box">
                <strong>Verfügbare Kalender-IDs:</strong><br>
                <?php foreach ($calendars as $cal): ?>
                    <span class="calendar-badge">ID: <strong><?= $cal['calendarID'] ?></strong> - <?= htmlspecialchars($cal['title']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <h2>3. ICS-URL Test</h2>
    <?php if ($icsTestResult['reachable']): ?>
        <div class="success-box">Erreichbar - <?= $icsTestResult['eventCount'] ?> Events gefunden</div>
        <?php if (!empty($icsTestResult['sampleEvents'])): ?>
            <h3>Beispiel-Events:</h3>
            <?php foreach ($icsTestResult['sampleEvents'] as $event): ?>
                <div class="sample-event"><?= htmlspecialchars($event) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php elseif ($icsTestResult['error']): ?>
        <div class="error-box"><?= htmlspecialchars($icsTestResult['error']) ?></div>
    <?php else: ?>
        <div class="warning-box">Keine ICS-URL konfiguriert</div>
    <?php endif; ?>
    
    <h2>4. Datenbank-Tabellen (mit "calendar")</h2>
    <?php if (!empty($allCalendarTables) && !isset($allCalendarTables['error'])): ?>
        <div class="success-box"><?= count($allCalendarTables) ?> Tabellen gefunden</div>
        <div class="table-list">
            <?php foreach ($allCalendarTables as $table): ?>
                <span class="table-item"><?= htmlspecialchars($table) ?></span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="error-box">Keine Calendar-Tabellen gefunden</div>
    <?php endif; ?>
    
    <h2>5. Verfügbare Kalender</h2>
    <?php if (!empty($calendars)): ?>
        <div class="success-box"><?= count($calendars) ?> Kalender gefunden (Quelle: <?= htmlspecialchars($calendarSource) ?>)</div>
        <div style="margin-top: 15px;">
            <?php foreach ($calendars as $cal): ?>
                <div class="calendar-badge">
                    ID: <strong><?= $cal['calendarID'] ?? 'N/A' ?></strong>
                    <?php if (!empty($cal['title'])): ?> - <?= htmlspecialchars($cal['title']) ?><?php endif; ?>
                    <?php if (isset($cal['calendarID']) && $cal['calendarID'] == $calendarID): ?>
                        <span class="badge badge-ok">Konfiguriert</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="error-box">Keine Kalender gefunden<?php if ($calendarError): ?> - <?= htmlspecialchars($calendarError) ?><?php endif; ?></div>
    <?php endif; ?>
    
    <h2>6. Cronjobs</h2>
    <?php if (!empty($cronjobs)): ?>
        <table>
            <tr><th>Klasse</th><th>Status</th><th>Letzter Lauf</th><th>Nächster Lauf</th></tr>
            <?php foreach ($cronjobs as $cron): ?>
            <tr>
                <td><code><?= htmlspecialchars($cron['className']) ?></code></td>
                <td><?php if ($cron['isDisabled']): ?><span class="badge badge-error">Deaktiviert</span><?php else: ?><span class="badge badge-ok">Aktiv</span><?php endif; ?></td>
                <td><?= $cron['lastExec'] > 0 ? date('d.m.Y H:i', $cron['lastExec']) : '<span class="warning">Nie</span>' ?></td>
                <td><?= $cron['nextExec'] > 0 ? date('d.m.Y H:i', $cron['nextExec']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="warning-box">Keine Import-Cronjobs gefunden</div>
    <?php endif; ?>
    
    <h2>7. PHP-Klassen</h2>
    <table>
        <tr><th>Klasse</th><th>Status</th></tr>
        <?php foreach ($cronjobClasses as $class): ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td><?php if (class_exists($class)): ?><span class="ok">Vorhanden</span><?php else: ?><span class="error">Fehlt</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>8. Aktuelle Optionen</h2>
    <table>
        <tr><th>Option</th><th>Wert</th></tr>
        <?php foreach ($options as $name => $value): ?>
        <tr>
            <td><code><?= htmlspecialchars($name) ?></code></td>
            <td><?= htmlspecialchars(strlen($value) > 60 ? substr($value, 0, 60) . '...' : $value) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <?php if (!empty($importLogs)): ?>
    <h2>9. Import-Log (letzte 10)</h2>
    <table>
        <tr><th>Zeit</th><th>Kalender</th><th>Gefunden</th><th>Importiert</th><th>Aktualisiert</th><th>Übersprungen</th></tr>
        <?php foreach ($importLogs as $log): ?>
        <tr>
            <td><?= date('d.m.Y H:i', $log['importTime']) ?></td>
            <td><?= $log['calendarID'] ?></td>
            <td><?= $log['eventsFound'] ?></td>
            <td class="ok"><?= $log['eventsImported'] ?></td>
            <td><?= $log['eventsUpdated'] ?></td>
            <td><?= $log['eventsSkipped'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
    
    <h2>10. Event-Listener</h2>
    <?php if (!empty($eventListeners)): ?>
        <div class="success-box"><?= count($eventListeners) ?> Event-Listener registriert</div>
    <?php else: ?>
        <div class="warning-box">Keine Event-Listener gefunden</div>
    <?php endif; ?>
    
    <h2>11. Installierte Kalender-Pakete</h2>
    <table>
        <tr><th>Paket</th><th>Version</th></tr>
        <?php foreach ($calendarPackages as $pkg): ?>
        <tr>
            <td><code><?= htmlspecialchars($pkg['package']) ?></code></td>
            <td><?= htmlspecialchars($pkg['packageVersion']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
</div>
</body>
</html>
