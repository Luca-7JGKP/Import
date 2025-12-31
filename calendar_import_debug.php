<?php
/**
 * Kalender Import Plugin - Erweitertes Debug-Script v2
 * 
 * Upload ins WoltLab-Hauptverzeichnis: https://domain.de/calendar_import_debug.php
 * WICHTIG: Nach dem Debugging wieder löschen!
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;
use wcf\system\language\LanguageFactory;

if (!WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    die('Zugriff verweigert. Bitte als Administrator einloggen.');
}

$debugInfo = [];

// 1. Aktuelle Spracheinstellungen
$debugInfo['current_language'] = [
    'id' => WCF::getLanguage()->languageID,
    'name' => WCF::getLanguage()->languageName,
    'code' => WCF::getLanguage()->languageCode
];

// 2. Sprachvariablen-Test
$testKeys = [
    'wcf.acp.menu.link.calendar.import',
    'wcf.acp.calendar.import.settings',
    'wcf.acp.calendar.import.targetImportID',
    'wcf.acp.calendar.import.boardID',
    'wcf.acp.calendar.import.createThreads',
    'wcf.acp.calendar.import.autoMarkPastEventsRead'
];

$debugInfo['language_keys'] = [];
foreach ($testKeys as $key) {
    $value = WCF::getLanguage()->get($key);
    $isDefined = ($value !== $key && !empty($value));
    $debugInfo['language_keys'][$key] = [
        'value' => $value ?: '(LEER)',
        'status' => $isDefined ? 'OK' : 'MISSING'
    ];
}

// 3. Event-Listener aus Datenbank
$debugInfo['event_listeners'] = [];
try {
    $sql = "SELECT * FROM wcf".WCF_N."_event_listener 
            WHERE listenerClassName LIKE ? OR eventClassName LIKE ?
            ORDER BY listenerName";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%', '%calendar%']);
    while ($row = $statement->fetchArray()) {
        $debugInfo['event_listeners'][] = $row;
    }
} catch (\Exception $e) {
    $debugInfo['event_listeners_error'] = $e->getMessage();
}

// 4. Alle Event-Listener des Plugins
try {
    $sql = "SELECT el.*, p.package 
            FROM wcf".WCF_N."_event_listener el
            LEFT JOIN wcf".WCF_N."_package p ON p.packageID = el.packageID
            WHERE p.package LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%import%']);
    $debugInfo['plugin_listeners'] = [];
    while ($row = $statement->fetchArray()) {
        $debugInfo['plugin_listeners'][] = $row;
    }
} catch (\Exception $e) {
    $debugInfo['plugin_listeners_error'] = $e->getMessage();
}

// 5. Optionen prüfen
$optionChecks = [
    'CALENDAR_IMPORT_TARGET_IMPORT_ID' => 'calendar_import_target_import_id',
    'CALENDAR_IMPORT_BOARD_ID' => 'calendar_import_board_id',
    'CALENDAR_IMPORT_DEFAULT_BOARD_ID' => 'calendar_import_default_board_id',
    'CALENDAR_IMPORT_CREATE_THREADS' => 'calendar_import_create_threads',
    'CALENDAR_IMPORT_CONVERT_TIMEZONE' => 'calendar_import_convert_timezone',
    'CALENDAR_IMPORT_AUTO_MARK_PAST_READ' => 'calendar_import_auto_mark_past_read',
    'CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ' => 'calendar_import_auto_mark_past_events_read',
    'CALENDAR_IMPORT_MARK_UPDATED_UNREAD' => 'calendar_import_mark_updated_unread',
    'CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD' => 'calendar_import_mark_updated_as_unread',
    'CALENDAR_IMPORT_MAX_EVENTS' => 'calendar_import_max_events',
    'CALENDAR_IMPORT_LOG_LEVEL' => 'calendar_import_log_level'
];

$debugInfo['options'] = [];
foreach ($optionChecks as $constant => $optionName) {
    $debugInfo['options'][$constant] = [
        'defined' => defined($constant),
        'value' => defined($constant) ? constant($constant) : 'NOT DEFINED',
        'option_name' => $optionName
    ];
}

// 6. Optionen aus Datenbank
$debugInfo['db_options'] = [];
try {
    $sql = "SELECT * FROM wcf".WCF_N."_option WHERE optionName LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['calendar_import%']);
    while ($row = $statement->fetchArray()) {
        $debugInfo['db_options'][] = $row;
    }
} catch (\Exception $e) {
    $debugInfo['db_options_error'] = $e->getMessage();
}

// 7. Listener-Klassen prüfen
$listenerClasses = [
    'wcf\\system\\event\\listener\\ICalImportExtensionEventListener',
    'wcf\\system\\event\\listener\\CalendarEventViewListener'
];

$debugInfo['listener_classes'] = [];
foreach ($listenerClasses as $class) {
    $exists = class_exists($class);
    $debugInfo['listener_classes'][$class] = [
        'exists' => $exists,
        'file' => $exists ? (new \ReflectionClass($class))->getFileName() : null
    ];
}

// 8. Event-Klassen prüfen (die im eventListener.xml referenziert werden)
$eventClasses = [
    'calendar\\page\\EventPage',
    'calendar\\system\\cronjob\\FeedImportCronjob',
    'wcf\\action\\ICalImportAction',
    'wcf\\action\\CalendarEventAction',
    'wcf\\page\\CalendarEventPage'
];

$debugInfo['event_classes'] = [];
foreach ($eventClasses as $class) {
    $debugInfo['event_classes'][$class] = class_exists($class);
}

// 9. Package-Info
try {
    $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%']);
    $debugInfo['packages'] = [];
    while ($row = $statement->fetchArray()) {
        $debugInfo['packages'][] = $row;
    }
} catch (\Exception $e) {
    $debugInfo['packages_error'] = $e->getMessage();
}

// 10. Tabellen prüfen
$tables = [
    'wcf'.WCF_N.'_calendar_event_visit',
    'wcf'.WCF_N.'_calendar_event_read_status',
    'calendar'.WCF_N.'_event',
    'calendar'.WCF_N.'_event_import'
];

$debugInfo['tables'] = [];
foreach ($tables as $table) {
    try {
        $sql = "SELECT COUNT(*) as cnt FROM " . $table;
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();
        $debugInfo['tables'][$table] = ['exists' => true, 'count' => $row['cnt']];
    } catch (\Exception $e) {
        $debugInfo['tables'][$table] = ['exists' => false, 'error' => $e->getMessage()];
    }
}

// HTML Output
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalender Import - Debug v2</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00d4ff; }
        h2 { color: #ff6b6b; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #2d3a5c; }
        th { background: #0f3460; color: #00d4ff; }
        .ok { color: #00ff88; font-weight: bold; }
        .missing, .error { color: #ff6b6b; font-weight: bold; }
        .warning { color: #feca57; font-weight: bold; }
        .info-box { background: #16213e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00d4ff; }
        .warning-box { background: #3d2914; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #feca57; }
        .error-box { background: #3d1414; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ff6b6b; }
        .success-box { background: #143d1e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00ff88; }
        code { background: #0f3460; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
        .truncate { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Kalender Import - Debug v2</h1>
    <p>Zeitstempel: <?= date('Y-m-d H:i:s') ?></p>
    
    <!-- Sprachvariablen -->
    <h2>1. Sprachvariablen</h2>
    <table>
        <tr><th>Schlüssel</th><th>Wert</th><th>Status</th></tr>
        <?php foreach ($debugInfo['language_keys'] as $key => $data): ?>
        <tr>
            <td><code><?= htmlspecialchars($key) ?></code></td>
            <td class="truncate"><?= htmlspecialchars($data['value']) ?></td>
            <td class="<?= $data['status'] === 'OK' ? 'ok' : 'missing' ?>"><?= $data['status'] === 'OK' ? '✅ OK' : '❌ MISSING' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- Event-Listener -->
    <h2>2. Event-Listener (aus Datenbank)</h2>
    <?php if (!empty($debugInfo['plugin_listeners'])): ?>
        <div class="success-box">✅ <?= count($debugInfo['plugin_listeners']) ?> Event-Listener gefunden</div>
        <table>
            <tr><th>Name</th><th>Event-Klasse</th><th>Event-Name</th><th>Listener-Klasse</th><th>Umgebung</th></tr>
            <?php foreach ($debugInfo['plugin_listeners'] as $listener): ?>
            <tr>
                <td><?= htmlspecialchars($listener['listenerName']) ?></td>
                <td class="truncate"><code><?= htmlspecialchars($listener['eventClassName']) ?></code></td>
                <td><?= htmlspecialchars($listener['eventName']) ?></td>
                <td class="truncate"><code><?= htmlspecialchars($listener['listenerClassName']) ?></code></td>
                <td><?= htmlspecialchars($listener['environment'] ?? 'all') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php elseif (!empty($debugInfo['event_listeners'])): ?>
        <div class="warning-box">⚠️ Listener gefunden, aber nicht vom Plugin</div>
        <table>
            <tr><th>Name</th><th>Event-Klasse</th><th>Listener-Klasse</th></tr>
            <?php foreach ($debugInfo['event_listeners'] as $listener): ?>
            <tr>
                <td><?= htmlspecialchars($listener['listenerName']) ?></td>
                <td class="truncate"><code><?= htmlspecialchars($listener['eventClassName']) ?></code></td>
                <td class="truncate"><code><?= htmlspecialchars($listener['listenerClassName']) ?></code></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="error-box">❌ Keine Event-Listener in der Datenbank gefunden!</div>
    <?php endif; ?>
    
    <!-- Listener-Klassen -->
    <h2>3. Listener-Klassen (PHP-Dateien)</h2>
    <table>
        <tr><th>Klasse</th><th>Existiert</th><th>Datei</th></tr>
        <?php foreach ($debugInfo['listener_classes'] as $class => $data): ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td class="<?= $data['exists'] ? 'ok' : 'error' ?>"><?= $data['exists'] ? '✅ Ja' : '❌ Nein' ?></td>
            <td class="truncate"><?= $data['file'] ? htmlspecialchars($data['file']) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- Event-Klassen (Target) -->
    <h2>4. Event-Klassen (Zielklassen im eventListener.xml)</h2>
    <table>
        <tr><th>Klasse</th><th>Existiert</th></tr>
        <?php foreach ($debugInfo['event_classes'] as $class => $exists): ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td class="<?= $exists ? 'ok' : 'warning' ?>"><?= $exists ? '✅ Ja' : '⚠️ Nein' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php 
    $missingClasses = array_filter($debugInfo['event_classes'], fn($exists) => !$exists);
    if (!empty($missingClasses)): 
    ?>
    <div class="warning-box">
        ⚠️ <strong>Einige Zielklassen existieren nicht!</strong><br>
        Die Event-Listener für diese Klassen werden nie ausgeführt.<br>
        Das ist normal, wenn das Kalender-Plugin andere Klassennamen verwendet.
    </div>
    <?php endif; ?>
    
    <!-- Optionen -->
    <h2>5. Plugin-Optionen (Konstanten)</h2>
    <table>
        <tr><th>Konstante</th><th>Definiert</th><th>Wert</th></tr>
        <?php foreach ($debugInfo['options'] as $constant => $data): ?>
        <tr>
            <td><code><?= htmlspecialchars($constant) ?></code></td>
            <td class="<?= $data['defined'] ? 'ok' : 'error' ?>"><?= $data['defined'] ? '✅ Ja' : '❌ Nein' ?></td>
            <td><?= htmlspecialchars($data['value']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- DB Optionen -->
    <h2>6. Optionen in Datenbank</h2>
    <?php if (!empty($debugInfo['db_options'])): ?>
        <table>
            <tr><th>Option Name</th><th>Wert</th><th>Kategorie</th></tr>
            <?php foreach ($debugInfo['db_options'] as $opt): ?>
            <tr>
                <td><code><?= htmlspecialchars($opt['optionName']) ?></code></td>
                <td><?= htmlspecialchars($opt['optionValue']) ?></td>
                <td><?= htmlspecialchars($opt['categoryName'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="error-box">❌ Keine Optionen in der Datenbank gefunden!</div>
    <?php endif; ?>
    
    <!-- Tabellen -->
    <h2>7. Datenbank-Tabellen</h2>
    <table>
        <tr><th>Tabelle</th><th>Existiert</th><th>Einträge</th></tr>
        <?php foreach ($debugInfo['tables'] as $table => $data): ?>
        <tr>
            <td><code><?= htmlspecialchars($table) ?></code></td>
            <td class="<?= $data['exists'] ? 'ok' : 'warning' ?>"><?= $data['exists'] ? '✅ Ja' : '⚠️ Nein' ?></td>
            <td><?= $data['exists'] ? $data['count'] : htmlspecialchars($data['error'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <!-- Packages -->
    <h2>8. Kalender-Plugins</h2>
    <?php if (!empty($debugInfo['packages'])): ?>
        <table>
            <tr><th>Package</th><th>Version</th><th>ID</th></tr>
            <?php foreach ($debugInfo['packages'] as $pkg): ?>
            <tr>
                <td><code><?= htmlspecialchars($pkg['package']) ?></code></td>
                <td><?= htmlspecialchars($pkg['packageVersion']) ?></td>
                <td><?= $pkg['packageID'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    
    <hr style="margin: 40px 0; border-color: #2d3a5c;">
    <p style="color: #ff6b6b;"><strong>⚠️ Diese Datei nach dem Debugging löschen!</strong></p>
</div>
</body>
</html>
