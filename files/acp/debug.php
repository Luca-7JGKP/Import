<?php
/**
 * Debug-Script für Kalender Import Plugin
 * 
 * Aufruf: php debug.php
 * Oder über ACP wenn als Page eingebunden
 * 
 * @author Luca Berwind
 * @version 1.2.1
 */

// WCF Bootstrap laden
require_once(__DIR__ . '/global.php');

use wcf\system\WCF;
use wcf\system\database\util\PreparedStatementConditionBuilder;

echo "=== Kalender Import Plugin Debug ===\n";
echo "Datum: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo str_repeat("=", 50) . "\n\n";

// 1. Datenbank-Tabellen prüfen
echo "## 1. Datenbank-Tabellen prüfen\n\n";

$tables = [
    'wcf1_calendar_event_read_status' => 'Gelesen/Ungelesen Status',
    'wcf1_calendar_event_visit' => 'Event-Besuche (Legacy)',
    'wcf1_calendar_import_log' => 'Import-Log',
    'calendar1_event' => 'Kalender Events (WoltLab)',
    'calendar1_event_import' => 'Kalender Import (WoltLab)'
];

foreach ($tables as $table => $description) {
    $exists = false;
    try {
        $sql = "SHOW TABLES LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$table]);
        $exists = $statement->fetchColumn() !== false;
    } catch (\Exception $e) {
        $exists = false;
    }
    
    $status = $exists ? "✅ EXISTIERT" : "❌ FEHLT";
    echo "- {$table}: {$status} ({$description})\n";
    
    if ($exists) {
        try {
            $sql = "SELECT COUNT(*) FROM {$table}";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $count = $statement->fetchColumn();
            echo "  → Einträge: {$count}\n";
        } catch (\Exception $e) {
            echo "  → Fehler beim Zählen: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n";

// 2. Event Listener prüfen
echo "## 2. Registrierte Event Listener\n\n";

try {
    $sql = "SELECT * FROM wcf1_event_listener WHERE listenerClassName LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%']);
    
    $listeners = [];
    while ($row = $statement->fetchArray()) {
        $listeners[] = $row;
    }
    
    if (empty($listeners)) {
        echo "❌ Keine Kalender-Event-Listener gefunden!\n";
    } else {
        foreach ($listeners as $listener) {
            echo "- {$listener['listenerName']}\n";
            echo "  → Klasse: {$listener['listenerClassName']}\n";
            echo "  → Event: {$listener['eventClassName']}::{$listener['eventName']}\n";
            echo "  → Umgebung: {$listener['environment']}\n";
            echo "\n";
        }
    }
} catch (\Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n";

// 3. Plugin-Optionen prüfen
echo "## 3. Plugin-Optionen\n\n";

$options = [
    'CALENDAR_IMPORT_TARGET_IMPORT_ID' => 'Ziel-Import-ID',
    'CALENDAR_IMPORT_DEFAULT_BOARD_ID' => 'Standard Board-ID',
    'CALENDAR_IMPORT_CREATE_THREADS' => 'Threads erstellen',
    'CALENDAR_IMPORT_CONVERT_TIMEZONE' => 'Zeitzone konvertieren',
    'CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ' => 'Vergangene als gelesen',
    'CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD' => 'Aktualisierte als ungelesen',
    'CALENDAR_IMPORT_MAX_EVENTS' => 'Max Events',
    'CALENDAR_IMPORT_LOG_LEVEL' => 'Log Level'
];

foreach ($options as $constant => $description) {
    $value = defined($constant) ? constant($constant) : 'NICHT DEFINIERT';
    echo "- {$constant}: {$value} ({$description})\n";
}

echo "\n";

// 4. Letzte Kalender-Events
echo "## 4. Letzte 5 Kalender-Events\n\n";

try {
    $sql = "SELECT eventID, subject, startTime, endTime, isDisabled 
            FROM calendar1_event 
            ORDER BY eventID DESC 
            LIMIT 5";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    while ($row = $statement->fetchArray()) {
        $startDate = date('Y-m-d H:i', $row['startTime']);
        $endDate = date('Y-m-d H:i', $row['endTime']);
        $status = $row['isDisabled'] ? '🔴 Deaktiviert' : '🟢 Aktiv';
        echo "- [{$row['eventID']}] {$row['subject']}\n";
        echo "  → {$startDate} - {$endDate} | {$status}\n";
    }
} catch (\Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n";

// 5. Letzte Import-Logs
echo "## 5. Letzte 10 Import-Logs\n\n";

try {
    $sql = "SELECT * FROM wcf1_calendar_import_log ORDER BY logID DESC LIMIT 10";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $hasLogs = false;
    while ($row = $statement->fetchArray()) {
        $hasLogs = true;
        $time = isset($row['importTime']) ? date('Y-m-d H:i:s', $row['importTime']) : 'N/A';
        $action = $row['action'] ?? 'N/A';
        $eventUID = $row['eventUID'] ?? 'N/A';
        echo "- [{$row['logID']}] {$time} | {$action} | UID: {$eventUID}\n";
    }
    
    if (!$hasLogs) {
        echo "ℹ️ Keine Import-Logs vorhanden\n";
    }
} catch (\Exception $e) {
    echo "❌ Tabelle nicht vorhanden oder Fehler: " . $e->getMessage() . "\n";
}

echo "\n";

// 6. Gelesen/Ungelesen Status
echo "## 6. Gelesen/Ungelesen Status (letzte 10)\n\n";

try {
    $sql = "SELECT rs.*, e.subject 
            FROM wcf1_calendar_event_read_status rs
            LEFT JOIN calendar1_event e ON rs.eventID = e.eventID
            ORDER BY rs.lastVisitTime DESC 
            LIMIT 10";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $hasStatus = false;
    while ($row = $statement->fetchArray()) {
        $hasStatus = true;
        $readStatus = $row['isRead'] ? '✅ Gelesen' : '❌ Ungelesen';
        $auto = $row['markedReadAutomatically'] ? ' (automatisch)' : '';
        $time = date('Y-m-d H:i:s', $row['lastVisitTime']);
        echo "- Event {$row['eventID']}: {$row['subject']}\n";
        echo "  → User {$row['userID']} | {$readStatus}{$auto} | {$time}\n";
    }
    
    if (!$hasStatus) {
        echo "ℹ️ Keine Status-Einträge vorhanden\n";
    }
} catch (\Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n";

// 7. Cronjobs prüfen
echo "## 7. Kalender-Cronjobs\n\n";

try {
    $sql = "SELECT * FROM wcf1_cronjob WHERE cronjobClassName LIKE ?";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute(['%calendar%']);
    
    $hasCronjobs = false;
    while ($row = $statement->fetchArray()) {
        $hasCronjobs = true;
        $status = $row['isDisabled'] ? '🔴 Deaktiviert' : '🟢 Aktiv';
        $lastExec = $row['lastExec'] > 0 ? date('Y-m-d H:i:s', $row['lastExec']) : 'Nie';
        $nextExec = $row['nextExec'] > 0 ? date('Y-m-d H:i:s', $row['nextExec']) : 'N/A';
        echo "- {$row['cronjobClassName']}\n";
        echo "  → Status: {$status}\n";
        echo "  → Letzter Lauf: {$lastExec}\n";
        echo "  → Nächster Lauf: {$nextExec}\n";
        echo "\n";
    }
    
    if (!$hasCronjobs) {
        echo "ℹ️ Keine Kalender-Cronjobs gefunden\n";
    }
} catch (\Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n";

// 8. Listener-Klassen prüfen
echo "## 8. Listener-Klassen Existenz\n\n";

$listenerClasses = [
    'wcf\\system\\event\\listener\\ICalImportExtensionEventListener',
    'wcf\\system\\event\\listener\\CalendarEventViewListener'
];

foreach ($listenerClasses as $class) {
    $exists = class_exists($class);
    $status = $exists ? "✅ EXISTIERT" : "❌ FEHLT";
    echo "- {$class}: {$status}\n";
    
    if ($exists) {
        $reflection = new \ReflectionClass($class);
        echo "  → Pfad: " . $reflection->getFileName() . "\n";
        echo "  → Methoden: " . implode(', ', array_map(function($m) { return $m->getName(); }, $reflection->getMethods())) . "\n";
    }
}

echo "\n";
echo str_repeat("=", 50) . "\n";
echo "Debug abgeschlossen.\n";
