<?php
/**
 * Kalender Import Plugin - Debug-Script v5
 * Vollständige Debug-Seite mit Datenbank-Tabellen-Scan
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.7.1
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
            CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.7.1'
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

// Kalender aus einer spezifischen Tabelle laden
function loadCalendarsFromTable($tableName) {
    $calendars = [];
    try {
        $sql = "SELECT * FROM " . $tableName . " LIMIT 50";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $calendars[] = $row;
        }
        return ['calendars' => $calendars, 'error' => null];
    } catch (\Exception $e) {
        return ['calendars' => [], 'error' => $e->getMessage()];
    }
}

// Kalender über WoltLab API laden
function loadCalendarsFromAPI() {
    $calendars = [];
    $error = null;
    
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
        } else {
            $error = 'CalendarList Klasse nicht gefunden';
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
    
    return ['calendars' => $calendars, 'error' => $error];
}

header('Content-Type: text/html; charset=utf-8');

// Daten sammeln
$icsUrl = getOptionValue('calendar_import_ics_url', '');
$calendarID = (int)getOptionValue('calendar_import_calendar_id', 0);
$icsTestResult = testIcsUrl($icsUrl);

// Alle Calendar-Tabellen finden
$allCalendarTables = findAllCalendarTables();

// Versuche Kalender zu laden
$calendars = [];
$calendarSource = '';
$calendarError = '';

// Zuerst aus gefundenen Tabellen (die auf _calendar enden)
foreach ($allCalendarTables as $table) {
    if (is_string($table) && preg_match('/_calendar$/', $table)) {
        $result = loadCalendarsFromTable($table);
        if (!empty($result['calendars'])) {
            $calendars = $result['calendars'];
            $calendarSource = $table;
            break;
        }
    }
}

// Fallback: API
if (empty($calendars)) {
    $apiResult = loadCalendarsFromAPI();
    if (!empty($apiResult['calendars'])) {
        $calendars = $apiResult['calendars'];
        $calendarSource = 'WoltLab Calendar API';
    } else {
        $calendarError = $apiResult['error'] ?? 'Keine Kalender gefunden';
    }
}

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
    <title>Kalender Import - Debug v5</title>
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
        code { background: #0f3460; padding: 2px 8px; border-radius: 4px; font-family: Consolas, monospace; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85em; margin: 2px; }
        .badge-ok { background: #143d1e; color: #00ff88; }
        .badge-error { background: #3d1414; color: #ff6b6b; }
        .sample-event { background: #0f3460; padding: 8px 12px; border-radius: 4px; margin: 5px 0; font-size: 0.9em; }
        .calendar-badge { display: inline-block; background: #0f3460; padding: 8px 15px; border-radius: 6px; margin: 5px; }
        .calendar-badge strong { color: #00d4ff; }
        .table-list { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
        .table-item { background: #0f3460; padding: 6px 12px; border-radius: 4px; font-family: monospace; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <h1>Kalender Import - Debug v5</h1>
    <p>Zeitstempel: <strong><?= date('Y-m-d H:i:s') ?></strong> | WCF_N: <strong><?= WCF_N ?></strong></p>
    
    <h2>1. Plugin-Installation</h2>
    <?php if ($package): ?>
        <div class="success-box">Plugin gefunden: <strong><?= htmlspecialchars($package['package']) ?></strong> v<?= htmlspecialchars($package['packageVersion']) ?></div>
    <?php else: ?>
        <div class="error-box">Plugin nicht gefunden!</div>
    <?php endif; ?>
    
    <h2>2. ICS-URL Test</h2>
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
    
    <h2>3. Datenbank-Tabellen (mit "calendar")</h2>
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
    
    <h2>4. Verfügbare Kalender</h2>
    <?php if (!empty($calendars)): ?>
        <div class="success-box"><?= count($calendars) ?> Kalender gefunden (Quelle: <?= htmlspecialchars($calendarSource) ?>)</div>
        <div style="margin-top: 15px;">
            <?php foreach ($calendars as $cal): ?>
                <div class="calendar-badge">
                    ID: <strong><?= $cal['calendarID'] ?? 'N/A' ?></strong>
                    <?php if (!empty($cal['title'])): ?> - <?= htmlspecialchars($cal['title']) ?><?php endif; ?>
                    <?php if (isset($cal['calendarID']) && $cal['calendarID'] == $calendarID): ?>
                        <span class="badge badge-ok">Aktiv</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="error-box">Keine Kalender gefunden<?php if ($calendarError): ?> - <?= htmlspecialchars($calendarError) ?><?php endif; ?></div>
    <?php endif; ?>
    
    <h2>5. Cronjobs</h2>
    <?php if (!empty($cronjobs)): ?>
        <table>
            <tr><th>Klasse</th><th>Status</th><th>Letzter Lauf</th><th>Nächster Lauf</th></tr>
            <?php foreach ($cronjobs as $cron): ?>
            <tr>
                <td><code><?= htmlspecialchars($cron['className']) ?></code></td>
                <td><?php if ($cron['isDisabled']): ?><span class="badge badge-error">Deaktiviert</span><?php else: ?><span class="badge badge-ok">Aktiv</span><?php endif; ?></td>
                <td><?= $cron['lastExec'] > 0 ? date('d.m.Y H:i', $cron['lastExec']) : 'Nie' ?></td>
                <td><?= $cron['nextExec'] > 0 ? date('d.m.Y H:i', $cron['nextExec']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="warning-box">Keine Import-Cronjobs gefunden</div>
    <?php endif; ?>
    
    <h2>6. PHP-Klassen</h2>
    <table>
        <tr><th>Klasse</th><th>Status</th></tr>
        <?php foreach ($cronjobClasses as $class): ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td><?php if (class_exists($class)): ?><span class="ok">Vorhanden</span><?php else: ?><span class="error">Fehlt</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>7. Aktuelle Optionen</h2>
    <table>
        <tr><th>Option</th><th>Wert</th></tr>
        <?php foreach ($options as $name => $value): ?>
        <tr>
            <td><code><?= htmlspecialchars($name) ?></code></td>
            <td><?= htmlspecialchars(strlen($value) > 60 ? substr($value, 0, 60) . '...' : $value) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>8. Event-Listener</h2>
    <?php if (!empty($eventListeners)): ?>
        <div class="success-box"><?= count($eventListeners) ?> Event-Listener registriert</div>
    <?php else: ?>
        <div class="warning-box">Keine Event-Listener gefunden</div>
    <?php endif; ?>
    
    <h2>9. Installierte Kalender-Pakete</h2>
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
