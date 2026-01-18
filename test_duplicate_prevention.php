<?php
/**
 * Test script for duplicate event prevention
 * Tests the enhanced validation and logging mechanisms
 * 
 * SECURITY NOTE: This is a diagnostic script. Remove from production after testing.
 * 
 * Usage: Place in WCF root directory and run via CLI (recommended) or browser
 * CLI: php test_duplicate_prevention.php
 * Web: Access via browser (ensure proper access controls)
 */

// Security: Verify script is in expected location
$expectedFile = 'global.php';
if (!file_exists(__DIR__ . '/' . $expectedFile)) {
    die("Error: This script must be placed in the WCF root directory.\n");
}

// Bootstrap WCF
require_once(__DIR__ . '/' . $expectedFile);

use wcf\system\WCF;
use wcf\system\cronjob\ICalImportCronjob;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// HTML output header
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Duplicate Prevention Test</title>';
    echo '<style>
    body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 20px; }
    h1 { color: #00d4ff; }
    h2 { color: #00d4ff; margin-top: 30px; border-bottom: 1px solid #333; padding-bottom: 10px; }
    .success { background: #143d1e; padding: 10px; border-radius: 4px; margin: 5px 0; }
    .error { background: #3d1414; padding: 10px; border-radius: 4px; margin: 5px 0; }
    .warning { background: #3d3414; padding: 10px; border-radius: 4px; margin: 5px 0; }
    .info { background: #0f3460; padding: 10px; border-radius: 4px; margin: 5px 0; }
    pre { background: #0a0a1a; padding: 15px; border-radius: 4px; overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #333; padding: 8px; text-align: left; }
    th { background: #0f3460; }
    tr:nth-child(even) { background: #1f1f3d; }
    </style></head><body>';
}

echo "<h1>🧪 Duplicate Event Prevention Test</h1>\n";
echo "<p>Testing enhanced validation mechanisms in ICalImportCronjob v4.3.2</p>\n";

/**
 * Test 1: Check UID mapping table structure
 */
echo "<h2>Test 1: UID Mapping Table Structure</h2>\n";
try {
    $sql = "SHOW CREATE TABLE calendar1_ical_uid_map";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $row = $statement->fetchArray();
    
    if ($row) {
        echo "<div class='success'>✅ Table exists</div>\n";
        
        // Check for UNIQUE constraint on icalUID
        $createTable = $row['Create Table'];
        if (strpos($createTable, 'UNIQUE KEY') !== false && strpos($createTable, 'icalUID') !== false) {
            echo "<div class='success'>✅ UNIQUE constraint on icalUID exists (prevents duplicate UIDs)</div>\n";
        } else {
            echo "<div class='error'>❌ UNIQUE constraint missing - duplicates may occur!</div>\n";
        }
        
        echo "<pre>" . htmlspecialchars($createTable) . "</pre>\n";
    } else {
        echo "<div class='error'>❌ Table does not exist</div>\n";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>\n";
}

/**
 * Test 2: Check for duplicate UID mappings
 */
echo "<h2>Test 2: Check for Duplicate UID Mappings</h2>\n";
try {
    $sql = "SELECT icalUID, COUNT(*) as cnt 
            FROM calendar1_ical_uid_map 
            GROUP BY icalUID 
            HAVING cnt > 1";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $duplicates = [];
    while ($row = $statement->fetchArray()) {
        $duplicates[] = $row;
    }
    
    if (empty($duplicates)) {
        echo "<div class='success'>✅ No duplicate UID mappings found</div>\n";
    } else {
        echo "<div class='error'>❌ Found " . count($duplicates) . " duplicate UID mappings!</div>\n";
        echo "<table><tr><th>UID</th><th>Count</th></tr>";
        foreach ($duplicates as $dup) {
            echo "<tr><td>" . htmlspecialchars($dup['icalUID']) . "</td><td>{$dup['cnt']}</td></tr>";
        }
        echo "</table>\n";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>\n";
}

/**
 * Test 3: Check for events with multiple UID mappings
 */
echo "<h2>Test 3: Check for Events with Multiple UIDs</h2>\n";
try {
    $sql = "SELECT eventID, COUNT(*) as cnt 
            FROM calendar1_ical_uid_map 
            GROUP BY eventID 
            HAVING cnt > 1";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $multipleUIDs = [];
    while ($row = $statement->fetchArray()) {
        $multipleUIDs[] = $row;
    }
    
    if (empty($multipleUIDs)) {
        echo "<div class='success'>✅ No events with multiple UIDs found</div>\n";
    } else {
        echo "<div class='error'>❌ Found " . count($multipleUIDs) . " events with multiple UIDs!</div>\n";
        echo "<table><tr><th>Event ID</th><th>UID Count</th><th>UIDs</th></tr>";
        foreach ($multipleUIDs as $evt) {
            // Get all UIDs for this event
            $sql2 = "SELECT icalUID FROM calendar1_ical_uid_map WHERE eventID = ?";
            $statement2 = WCF::getDB()->prepareStatement($sql2);
            $statement2->execute([$evt['eventID']]);
            $uids = [];
            while ($row2 = $statement2->fetchArray()) {
                $uids[] = $row2['icalUID'];
            }
            echo "<tr><td>{$evt['eventID']}</td><td>{$evt['cnt']}</td><td>" . 
                 htmlspecialchars(implode(', ', $uids)) . "</td></tr>";
        }
        echo "</table>\n";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>\n";
}

/**
 * Test 4: Check for orphaned UID mappings (event doesn't exist)
 */
echo "<h2>Test 4: Check for Orphaned UID Mappings</h2>\n";
try {
    $sql = "SELECT m.mapID, m.eventID, m.icalUID 
            FROM calendar1_ical_uid_map m
            LEFT JOIN calendar1_event e ON m.eventID = e.eventID
            WHERE e.eventID IS NULL
            LIMIT 10";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $orphaned = [];
    while ($row = $statement->fetchArray()) {
        $orphaned[] = $row;
    }
    
    if (empty($orphaned)) {
        echo "<div class='success'>✅ No orphaned UID mappings found</div>\n";
    } else {
        echo "<div class='warning'>⚠️ Found " . count($orphaned) . " orphaned UID mappings (event deleted but mapping remains)</div>\n";
        echo "<table><tr><th>Map ID</th><th>Event ID</th><th>UID</th></tr>";
        foreach ($orphaned as $orph) {
            echo "<tr><td>{$orph['mapID']}</td><td>{$orph['eventID']}</td><td>" . 
                 htmlspecialchars(substr($orph['icalUID'], 0, 50)) . "...</td></tr>";
        }
        echo "</table>\n";
        echo "<div class='info'>ℹ️ These will be automatically cleaned up on next import run</div>\n";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>\n";
}

/**
 * Test 5: Check recent import log for duplicate-related issues
 */
echo "<h2>Test 5: Recent Import Log Analysis</h2>\n";
try {
    $sql = "SELECT logID, eventUID, action, importTime, message, logLevel 
            FROM wcf1_calendar_import_log 
            WHERE message LIKE '%duplicate%' OR message LIKE '%Duplicate%' 
               OR message LIKE '%already%' OR message LIKE '%conflict%'
               OR logLevel IN ('error', 'warning')
            ORDER BY importTime DESC 
            LIMIT 20";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    
    $logs = [];
    while ($row = $statement->fetchArray()) {
        $logs[] = $row;
    }
    
    if (empty($logs)) {
        echo "<div class='success'>✅ No duplicate-related errors or warnings in recent logs</div>\n";
    } else {
        echo "<div class='warning'>⚠️ Found " . count($logs) . " relevant log entries</div>\n";
        echo "<table><tr><th>Time</th><th>Level</th><th>UID</th><th>Message</th></tr>";
        foreach ($logs as $log) {
            $levelClass = $log['logLevel'] === 'error' ? 'error' : 'warning';
            echo "<tr class='{$levelClass}'>";
            echo "<td>" . date('Y-m-d H:i:s', $log['importTime']) . "</td>";
            echo "<td>{$log['logLevel']}</td>";
            echo "<td>" . htmlspecialchars(substr($log['eventUID'], 0, 30)) . "</td>";
            echo "<td>" . htmlspecialchars(substr($log['message'], 0, 100)) . "</td>";
            echo "</tr>";
        }
        echo "</table>\n";
    }
} catch (Exception $e) {
    echo "<div class='info'>ℹ️ Log table not available or empty</div>\n";
}

/**
 * Test 6: Statistics
 */
echo "<h2>Test 6: Import Statistics</h2>\n";
try {
    // Count total events
    $sql = "SELECT COUNT(*) FROM calendar1_event";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $totalEvents = $statement->fetchColumn();
    
    // Count events with UID mappings
    $sql = "SELECT COUNT(DISTINCT e.eventID) 
            FROM calendar1_event e
            JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $eventsWithUID = $statement->fetchColumn();
    
    // Count events without UID mappings
    $eventsWithoutUID = $totalEvents - $eventsWithUID;
    
    echo "<div class='info'>";
    echo "📊 Total events: {$totalEvents}<br>\n";
    echo "📊 Events with UID mapping: {$eventsWithUID}<br>\n";
    echo "📊 Events without UID mapping: {$eventsWithoutUID}<br>\n";
    
    if ($eventsWithoutUID > 0) {
        echo "<br>⚠️ Events without UID mapping will be matched by properties on next import";
    }
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>\n";
}

/**
 * Test 7: Run a test import (optional - commented out by default)
 * 
 * SECURITY WARNING: This will enable debug logging which may expose sensitive information.
 * Only enable this in non-production environments.
 */
echo "<h2>Test 7: Test Import (Manual Trigger)</h2>\n";
echo "<div class='warning'>";
echo "⚠️ <strong>PRODUCTION WARNING</strong>: Debug logging can expose sensitive information.<br>\n";
echo "Only use this feature in development/staging environments.<br><br>\n";
echo "To run a test import:<br>\n";
echo "1. Ensure you are NOT in production<br>\n";
echo "2. Uncomment the code in this section<br>\n";
echo "3. Reload this page<br>\n";
echo "</div>\n";

// Uncomment to run test import (NON-PRODUCTION ONLY):
/*
// Production environment check
$isProduction = (defined('ENABLE_DEBUG_MODE') && ENABLE_DEBUG_MODE === false) || 
                (defined('ENABLE_PRODUCTION_MODE') && ENABLE_PRODUCTION_MODE === true);

if ($isProduction) {
    echo "<div class='error'>❌ Test import disabled in production environment</div>\n";
} else {
    try {
        define('CALENDAR_IMPORT_LOG_LEVEL', 'debug'); // Enable debug logging
        
        echo "<div class='info'>🔄 Running test import...</div>\n";
        
        $cronjob = new ICalImportCronjob();
        $cronjob->runManually();
        
        echo "<div class='success'>✅ Test import completed</div>\n";
        echo "<div class='info'>Check the logs above and database for results</div>\n";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Test import failed: " . htmlspecialchars($e->getMessage()) . "</div>\n";
    }
}
*/

echo "<h2>✅ Test Complete</h2>\n";
echo "<div class='info'>Review the results above to verify duplicate prevention is working correctly.</div>\n";

if (php_sapi_name() !== 'cli') {
    echo '</body></html>';
}
