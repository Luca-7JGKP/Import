<?php
/**
 * Calendar Import Debug Script
 * Improved calendar detection with comprehensive table checking
 * Updated: 2026-01-01
 */

// Database connection settings
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'calendar_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

echo "=== Calendar Import Debug Tool ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[OK] Database connection successful\n\n";
} catch (PDOException $e) {
    die("[ERROR] Database connection failed: " . $e->getMessage() . "\n");
}

// Define all possible calendar table names to check
$possibleCalendarTables = [
    // Common calendar table names
    'calendars',
    'calendar',
    'cal',
    'cals',
    'calendar_events',
    'events',
    'event',
    'calendar_entries',
    'entries',
    'appointments',
    'appointment',
    'schedules',
    'schedule',
    'bookings',
    'booking',
    // Prefixed variations
    'tbl_calendars',
    'tbl_calendar',
    'tbl_events',
    'tbl_calendar_events',
    'wp_calendars',
    'wp_calendar',
    'wp_events',
    'app_calendars',
    'app_calendar',
    'app_events',
    // Suffixed variations
    'calendars_data',
    'calendar_data',
    'events_data',
    'calendar_items',
    'event_items',
    // CamelCase variations
    'Calendars',
    'Calendar',
    'CalendarEvents',
    'Events',
];

echo "=== Checking All Possible Calendar Tables ===\n\n";

// Get all existing tables in the database
$stmt = $pdo->query("SHOW TABLES");
$existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Tables found in database '$dbname':\n";
echo str_repeat("-", 50) . "\n";

if (empty($existingTables)) {
    echo "[WARNING] No tables found in database!\n";
} else {
    foreach ($existingTables as $table) {
        echo "  - $table\n";
    }
}
echo "\n";

// Check which possible calendar tables exist
echo "=== Calendar Table Detection Results ===\n\n";

$foundCalendarTables = [];
$notFoundTables = [];

foreach ($possibleCalendarTables as $tableName) {
    if (in_array($tableName, $existingTables) || in_array(strtolower($tableName), array_map('strtolower', $existingTables))) {
        $foundCalendarTables[] = $tableName;
    } else {
        $notFoundTables[] = $tableName;
    }
}

if (!empty($foundCalendarTables)) {
    echo "[FOUND] The following calendar tables exist:\n";
    echo str_repeat("-", 50) . "\n";
    foreach ($foundCalendarTables as $table) {
        echo "  ✓ $table\n";
        
        // Get table structure
        try {
            $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
            echo "    Columns: ";
            $columnNames = array_column($columns, 'Field');
            echo implode(', ', $columnNames) . "\n";
            
            // Get row count
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "    Row count: $count\n";
        } catch (PDOException $e) {
            echo "    [ERROR] Could not inspect table: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
} else {
    echo "[WARNING] No known calendar tables found!\n\n";
}

echo "=== Tables Checked But Not Found ===\n";
echo "(This is normal - showing what was checked)\n";
echo str_repeat("-", 50) . "\n";
$chunks = array_chunk($notFoundTables, 5);
foreach ($chunks as $chunk) {
    echo "  ✗ " . implode(', ', $chunk) . "\n";
}
echo "\n";

// Additional detection: Look for tables containing 'calendar' or 'event' in their name
echo "=== Pattern-Based Table Detection ===\n\n";

$patternMatches = [];
foreach ($existingTables as $table) {
    $lowerTable = strtolower($table);
    if (strpos($lowerTable, 'calendar') !== false || 
        strpos($lowerTable, 'event') !== false ||
        strpos($lowerTable, 'schedule') !== false ||
        strpos($lowerTable, 'booking') !== false ||
        strpos($lowerTable, 'appointment') !== false) {
        $patternMatches[] = $table;
    }
}

if (!empty($patternMatches)) {
    echo "[FOUND] Tables matching calendar-related patterns:\n";
    foreach ($patternMatches as $table) {
        echo "  → $table\n";
    }
} else {
    echo "[INFO] No tables found matching calendar-related patterns\n";
}
echo "\n";

// Summary and recommendations
echo "=== Summary & Recommendations ===\n";
echo str_repeat("=", 50) . "\n\n";

$allDetectedTables = array_unique(array_merge($foundCalendarTables, $patternMatches));

if (!empty($allDetectedTables)) {
    echo "Detected calendar-related tables: " . count($allDetectedTables) . "\n";
    echo "Recommended table(s) for import:\n";
    foreach ($allDetectedTables as $table) {
        echo "  → $table\n";
    }
} else {
    echo "[ACTION REQUIRED] No calendar tables detected.\n";
    echo "Please verify:\n";
    echo "  1. The database name is correct: '$dbname'\n";
    echo "  2. Calendar tables have been created\n";
    echo "  3. The database user has SELECT permissions\n";
}

echo "\n=== Debug Complete ===\n";
echo "Finished: " . date('Y-m-d H:i:s') . "\n";
?>
