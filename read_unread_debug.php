<?php
/**
 * Debug-Script für Gelesen/Ungelesen-Logik
 * 
 * Aufruf: https://forum.wildes-gebilde.de/read_unread_debug.php
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gelesen/Ungelesen Debug</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 20px; background: #1a1a2e; color: #eee; }
        h1, h2 { color: #00d4ff; }
        .ok { color: #00ff88; }
        .error { color: #ff6b6b; }
        .warning { color: #feca57; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #333; padding: 10px; text-align: left; }
        th { background: #0f3460; color: #00d4ff; }
        tr:nth-child(even) { background: #16213e; }
        code { background: #0f3460; padding: 2px 6px; border-radius: 3px; }
        .box { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success-box { background: #143d1e; border-left: 4px solid #00ff88; }
        .error-box { background: #3d1414; border-left: 4px solid #ff6b6b; }
        .info-box { background: #1a3a5c; border-left: 4px solid #00d4ff; }
    </style>
</head>
<body>
<h1>Gelesen/Ungelesen Debug</h1>
<p>Zeitstempel: <?= date('Y-m-d H:i:s') ?></p>

<?php
// 1. Event-Listener prüfen
echo "<h2>1. Registrierte Event-Listener für Kalender</h2>";

try {
    $sql = "SELECT * FROM wcf".WCF_N."_event_listener 
            WHERE listenerClassName LIKE '%Calendar%' 
               OR listenerClassName LIKE '%calendar%'
               OR eventClassName LIKE '%calendar%'
               OR eventClassName LIKE '%Calendar%'
            ORDER BY listenerName";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $listeners = [];
    while ($row = $statement->fetchArray()) {
        $listeners[] = $row;
    }
    
    if (empty($listeners)) {
        echo '<div class="error-box">Keine Kalender Event-Listener gefunden!</div>';
    } else {
        echo '<div class="success-box">' . count($listeners) . ' Event-Listener gefunden</div>';
        echo '<table>';
        echo '<tr><th>Name</th><th>Event-Klasse</th><th>Event</th><th>Listener-Klasse</th><th>Umgebung</th></tr>';
        foreach ($listeners as $l) {
            $listenerExists = class_exists($l['listenerClassName']);
            $eventExists = class_exists($l['eventClassName']);
            echo '<tr>';
            echo '<td>' . htmlspecialchars($l['listenerName']) . '</td>';
            echo '<td class="' . ($eventExists ? 'ok' : 'error') . '"><code>' . htmlspecialchars($l['eventClassName']) . '</code></td>';
            echo '<td>' . htmlspecialchars($l['eventName']) . '</td>';
            echo '<td class="' . ($listenerExists ? 'ok' : 'error') . '"><code>' . htmlspecialchars($l['listenerClassName']) . '</code></td>';
            echo '<td>' . htmlspecialchars($l['environment']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (\Exception $e) {
    echo '<div class="error-box">Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// 2. Object-Types für Kalender prüfen
echo "<h2>2. Object-Types für Tracking</h2>";

try {
    $sql = "SELECT ot.*, otd.definitionName 
            FROM wcf".WCF_N."_object_type ot
            LEFT JOIN wcf".WCF_N."_object_type_definition otd ON otd.definitionID = ot.definitionID
            WHERE ot.objectType LIKE '%calendar%' 
               OR ot.objectType LIKE '%event%'
            ORDER BY ot.objectType";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $objectTypes = [];
    while ($row = $statement->fetchArray()) {
        $objectTypes[] = $row;
    }
    
    if (empty($objectTypes)) {
        echo '<div class="warning-box">Keine Kalender Object-Types gefunden</div>';
    } else {
        echo '<table>';
        echo '<tr><th>Object-Type</th><th>ID</th><th>Definition</th></tr>';
        foreach ($objectTypes as $ot) {
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars($ot['objectType']) . '</code></td>';
            echo '<td>' . $ot['objectTypeID'] . '</td>';
            echo '<td>' . htmlspecialchars($ot['definitionName'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (\Exception $e) {
    echo '<div class="error-box">Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// 3. WoltLab Kalender Klassen prüfen
echo "<h2>3. WoltLab Kalender Klassen</h2>";

$classes = [
    'calendar\\page\\EventPage' => 'Event-Detailseite',
    'calendar\\page\\CalendarPage' => 'Kalender-Übersicht',
    'calendar\\data\\event\\EventAction' => 'Event-Aktionen',
    'wcf\\system\\visitTracker\\VisitTracker' => 'WCF Visit-Tracker'
];

echo '<table>';
echo '<tr><th>Klasse</th><th>Beschreibung</th><th>Status</th></tr>';
foreach ($classes as $class => $desc) {
    $exists = class_exists($class);
    echo '<tr>';
    echo '<td><code>' . htmlspecialchars($class) . '</code></td>';
    echo '<td>' . htmlspecialchars($desc) . '</td>';
    echo '<td class="' . ($exists ? 'ok' : 'error') . '">' . ($exists ? 'Vorhanden' : 'Fehlt') . '</td>';
    echo '</tr>';
}
echo '</table>';

// 4. Plugin-Konstanten
echo "<h2>4. Plugin-Konstanten</h2>";

$constants = [
    'CALENDAR_IMPORT_TARGET_IMPORT_ID',
    'CALENDAR_IMPORT_AUTO_MARK_PAST_READ',
    'CALENDAR_IMPORT_MARK_UPDATED_UNREAD'
];

echo '<table>';
echo '<tr><th>Konstante</th><th>Definiert</th><th>Wert</th></tr>';
foreach ($constants as $const) {
    $defined = defined($const);
    $value = $defined ? constant($const) : '-';
    echo '<tr>';
    echo '<td><code>' . $const . '</code></td>';
    echo '<td class="' . ($defined ? 'ok' : 'error') . '">' . ($defined ? 'Ja' : 'Nein') . '</td>';
    echo '<td>' . htmlspecialchars(var_export($value, true)) . '</td>';
    echo '</tr>';
}
echo '</table>';
?>

</body>
</html>