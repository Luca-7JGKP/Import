<?php
/**
 * Kalender Import Plugin - Debug-Script v4
 * Vollständige Debug-Seite mit integriertem ICS-Test
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.7.0
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

// Berechtigungsprüfung: Nur Administratoren erlauben
if (!WCF::getUser()->userID || !WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Zugriff verweigert</title>
        <style>
            body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 40px; text-align: center; }
            .error-container { max-width: 500px; margin: 100px auto; background: #16213e; padding: 40px; border-radius: 12px; border-left: 4px solid #ff6b6b; }
            h1 { color: #ff6b6b; margin-bottom: 20px; }
            p { color: #aaa; margin-bottom: 20px; }
            a { color: #00d4ff; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>🔒 Zugriff verweigert</h1>
            <p>Diese Seite ist nur für Administratoren zugänglich.</p>
            <p>Bitte melden Sie sich mit einem Administrator-Konto an.</p>
            <p><a href="index.php?login/">→ Zur Anmeldung</a></p>
        </div>
    </body>
    </html>
    <?php
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
    $result = [
        'url' => $url,
        'reachable' => false,
        'statusCode' => null,
        'eventCount' => 0,
        'sampleEvents' => [],
        'error' => null
    ];
    
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
            CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.7.0'
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
            
            // Extrahiere Sample-Events
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

// Kalender abrufen
function getCalendars() {
    $calendars = [];
    $calendarTableNames = [
        'calendar'.WCF_N.'_calendar',
        'wcf'.WCF_N.'_calendar'
    ];
    
    foreach ($calendarTableNames as $tableName) {
        try {
            $sql = "SHOW TABLES LIKE ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$tableName]);
            if ($statement->fetchColumn() !== false) {
                $sql = "SELECT calendarID, title FROM ".$tableName." ORDER BY calendarID";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                while ($row = $statement->fetchArray()) {
                    $calendars[] = $row;
                }
                break;
            }
        } catch (\Exception $e) {}
    }
    
    // Fallback: WoltLab Calendar API
    if (empty($calendars)) {
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
    }
    
    return $calendars;
}

header('Content-Type: text/html; charset=utf-8');

// Daten sammeln
$icsUrl = getOptionValue('calendar_import_ics_url', '');
$calendarID = (int)getOptionValue('calendar_import_calendar_id', 0);
$icsTestResult = testIcsUrl($icsUrl);
$calendars = getCalendars();

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
$optionNames = [
    'calendar_import_ics_url',
    'calendar_import_calendar_id',
    'calendar_import_auto_mark_past_read',
    'calendar_import_mark_updated_unread',
    'calendar_import_convert_timezone',
    'calendar_import_create_threads',
    'calendar_import_default_board_id',
    'calendar_import_max_events',
    'calendar_import_log_level'
];
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

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalender Import - Debug v4</title>
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
        .info-box { background: #14293d; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00d4ff; }
        code { background: #0f3460; padding: 2px 8px; border-radius: 4px; font-family: 'Consolas', monospace; }
        .truncate { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .card { background: #16213e; padding: 20px; border-radius: 8px; }
        .card-title { color: #00d4ff; margin-bottom: 15px; font-size: 1.1em; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85em; margin: 2px; }
        .badge-ok { background: #143d1e; color: #00ff88; }
        .badge-error { background: #3d1414; color: #ff6b6b; }
        .badge-warning { background: #3d2914; color: #feca57; }
        .sample-event { background: #0f3460; padding: 8px 12px; border-radius: 4px; margin: 5px 0; font-size: 0.9em; }
        .calendar-badge { display: inline-block; background: #0f3460; padding: 8px 15px; border-radius: 6px; margin: 5px; }
        .calendar-badge strong { color: #00d4ff; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Kalender Import - Debug v4</h1>
    <p>Zeitstempel: <strong><?= date('Y-m-d H:i:s') ?></strong></p>
    
    <!-- Plugin Info -->
    <h2>1. Plugin-Installation</h2>
    <?php if ($package): ?>
        <div class="success-box">
            ✅ <strong><?= htmlspecialchars($package['package']) ?></strong> v<?= htmlspecialchars($package['packageVersion']) ?>
        </div>
    <?php else: ?>
        <div class="error-box">❌ Plugin nicht gefunden!</div>
    <?php endif; ?>
    
    <!-- ICS-URL Test -->
    <h2>2. ICS-URL Test</h2>
    <?php if ($icsTestResult['reachable']): ?>
        <div class="success-box">
            ✅ <strong>Erreichbar</strong> - <?= $icsTestResult['eventCount'] ?> Events gefunden
        </div>
        
        <?php if (!empty($icsTestResult['sampleEvents'])): ?>
            <h3>Beispiel-Events:</h3>
            <?php foreach ($icsTestResult['sampleEvents'] as $event): ?>
                <div class="sample-event">📅 <?= htmlspecialchars($event) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php elseif ($icsTestResult['error']): ?>
        <div class="error-box">❌ <?= htmlspecialchars($icsTestResult['error']) ?></div>
    <?php else: ?>
        <div class="warning-box">⚠️ Keine ICS-URL konfiguriert</div>
    <?php endif; ?>
    
    <!-- Verfügbare Kalender -->
    <h2>3. Verfügbare Kalender</h2>
    <?php if (!empty($calendars)): ?>
        <div class="success-box">
            ✅ <?= count($calendars) ?> Kalender gefunden
        </div>
        <div style="margin-top: 15px;">
            <?php foreach ($calendars as $cal): ?>
                <div class="calendar-badge">
                    ID: <strong><?= $cal['calendarID'] ?></strong>
                    <?php if (!empty($cal['title'])): ?>
                        - <?= htmlspecialchars($cal['title']) ?>
                    <?php endif; ?>
                    <?php if ($cal['calendarID'] == $calendarID): ?>
                        <span class="badge badge-ok">✓ Aktiv</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="error-box">❌ Keine Kalender gefunden</div>
    <?php endif; ?>
    
    <!-- Cronjobs -->
    <h2>4. Cronjobs</h2>
    <?php if (!empty($cronjobs)): ?>
        <table>
            <tr>
                <th>Klasse</th>
                <th>Status</th>
                <th>Letzter Lauf</th>
                <th>Nächster Lauf</th>
            </tr>
            <?php foreach ($cronjobs as $cron): ?>
            <tr>
                <td><code><?= htmlspecialchars($cron['className']) ?></code></td>
                <td>
                    <?php if ($cron['isDisabled']): ?>
                        <span class="badge badge-error">🔴 Deaktiviert</span>
                    <?php else: ?>
                        <span class="badge badge-ok">🟢 Aktiv</span>
                    <?php endif; ?>
                </td>
                <td><?= $cron['lastExec'] > 0 ? date('d.m.Y H:i', $cron['lastExec']) : 'Nie' ?></td>
                <td><?= $cron['nextExec'] > 0 ? date('d.m.Y H:i', $cron['nextExec']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="warning-box">⚠️ Keine Import-Cronjobs gefunden</div>
    <?php endif; ?>
    
    <!-- PHP-Klassen -->
    <h2>5. PHP-Klassen</h2>
    <table>
        <tr>
            <th>Klasse</th>
            <th>Status</th>
        </tr>
        <?php foreach ($cronjobClasses as $class): ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td>
                <?php if (class_exists($class)): ?>
                    <span class="ok">✅ Vorhanden</span>
                <?php else: ?>
                    <span class="error">❌ Fehlt</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- Aktuelle Optionen -->
    <h2>6. Aktuelle Optionen</h2>
    <table>
        <tr>
            <th>Option</th>
            <th>Wert</th>
        </tr>
        <?php foreach ($options as $name => $value): ?>
        <tr>
            <td><code><?= htmlspecialchars($name) ?></code></td>
            <td>
                <?php if ($name === 'calendar_import_ics_url' && strlen($value) > 60): ?>
                    <span title="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars(substr($value, 0, 60)) ?>...</span>
                <?php else: ?>
                    <?= htmlspecialchars($value) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- Kalender-Pakete -->
    <h2>7. Installierte Kalender-Pakete</h2>
    <table>
        <tr>
            <th>Paket</th>
            <th>Version</th>
        </tr>
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