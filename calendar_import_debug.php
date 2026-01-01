<?php
/**
 * Calendar Import Debug Tool
 * Scans ALL database tables containing "calendar" in the name
 * 
 * Updated: 2026-01-01 17:39:36 UTC
 * Author: Luca-7JGKP
 */

// Database configuration
require_once 'config.php';

class CalendarTableScanner
{
    private $pdo;
    private $results = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find all tables containing "calendar" in the name
     */
    public function findCalendarTables(): array
    {
        $tables = [];
        
        try {
            // Query to find all tables with "calendar" in the name (case-insensitive)
            $stmt = $this->pdo->query("SHOW TABLES");
            $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($allTables as $table) {
                if (stripos($table, 'calendar') !== false) {
                    $tables[] = $table;
                }
            }
        } catch (PDOException $e) {
            $this->logError("Error finding calendar tables: " . $e->getMessage());
        }
        
        return $tables;
    }

    /**
     * Scan a specific table and gather debug information
     */
    public function scanTable(string $tableName): array
    {
        $tableInfo = [
            'name' => $tableName,
            'exists' => false,
            'columns' => [],
            'row_count' => 0,
            'sample_data' => [],
            'indexes' => [],
            'errors' => []
        ];

        try {
            // Check if table exists and get column info
            $stmt = $this->pdo->query("DESCRIBE `{$tableName}`");
            $tableInfo['exists'] = true;
            $tableInfo['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get row count
            $countStmt = $this->pdo->query("SELECT COUNT(*) as count FROM `{$tableName}`");
            $tableInfo['row_count'] = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get sample data (first 5 rows)
            $sampleStmt = $this->pdo->query("SELECT * FROM `{$tableName}` LIMIT 5");
            $tableInfo['sample_data'] = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get index information
            $indexStmt = $this->pdo->query("SHOW INDEX FROM `{$tableName}`");
            $tableInfo['indexes'] = $indexStmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $tableInfo['errors'][] = $e->getMessage();
        }

        return $tableInfo;
    }

    /**
     * Scan all calendar-related tables
     */
    public function scanAllCalendarTables(): array
    {
        $calendarTables = $this->findCalendarTables();
        
        $this->results = [
            'scan_timestamp' => date('Y-m-d H:i:s'),
            'total_tables_found' => count($calendarTables),
            'table_names' => $calendarTables,
            'tables' => []
        ];

        foreach ($calendarTables as $table) {
            $this->results['tables'][$table] = $this->scanTable($table);
        }

        return $this->results;
    }

    /**
     * Log error messages
     */
    private function logError(string $message): void
    {
        error_log("[CalendarDebug] " . $message);
    }

    /**
     * Generate HTML report
     */
    public function generateHtmlReport(): string
    {
        $results = $this->results ?: $this->scanAllCalendarTables();
        
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<title>Calendar Tables Debug Report</title>\n";
        $html .= "<style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; }
            h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
            h2 { color: #555; margin-top: 30px; }
            .summary { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .table-card { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            table { border-collapse: collapse; width: 100%; margin-top: 10px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #007bff; color: white; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .count { font-size: 24px; font-weight: bold; color: #007bff; }
            .error { color: #dc3545; }
            .success { color: #28a745; }
            .table-list { list-style: none; padding: 0; }
            .table-list li { padding: 5px 10px; background: #e9ecef; margin: 5px 0; border-radius: 4px; }
        </style>\n";
        $html .= "</head>\n<body>\n<div class='container'>\n";
        
        $html .= "<h1>📅 Calendar Tables Debug Report</h1>\n";
        
        // Summary section
        $html .= "<div class='summary'>\n";
        $html .= "<h2>Summary</h2>\n";
        $html .= "<p><strong>Scan Timestamp:</strong> {$results['scan_timestamp']}</p>\n";
        $html .= "<p><strong>Total Calendar Tables Found:</strong> <span class='count'>{$results['total_tables_found']}</span></p>\n";
        
        if (!empty($results['table_names'])) {
            $html .= "<p><strong>Tables:</strong></p>\n<ul class='table-list'>\n";
            foreach ($results['table_names'] as $name) {
                $html .= "<li>{$name}</li>\n";
            }
            $html .= "</ul>\n";
        } else {
            $html .= "<p class='error'>No tables containing 'calendar' were found in the database.</p>\n";
        }
        $html .= "</div>\n";

        // Detailed table info
        foreach ($results['tables'] as $tableName => $tableInfo) {
            $html .= "<div class='table-card'>\n";
            $html .= "<h2>Table: {$tableName}</h2>\n";
            
            $statusClass = $tableInfo['exists'] ? 'success' : 'error';
            $statusText = $tableInfo['exists'] ? 'Exists' : 'Not Found';
            $html .= "<p><strong>Status:</strong> <span class='{$statusClass}'>{$statusText}</span></p>\n";
            $html .= "<p><strong>Row Count:</strong> {$tableInfo['row_count']}</p>\n";

            // Columns
            if (!empty($tableInfo['columns'])) {
                $html .= "<h3>Columns</h3>\n<table>\n";
                $html .= "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
                foreach ($tableInfo['columns'] as $col) {
                    $html .= "<tr>";
                    $html .= "<td>{$col['Field']}</td>";
                    $html .= "<td>{$col['Type']}</td>";
                    $html .= "<td>{$col['Null']}</td>";
                    $html .= "<td>{$col['Key']}</td>";
                    $html .= "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                    $html .= "<td>{$col['Extra']}</td>";
                    $html .= "</tr>\n";
                }
                $html .= "</table>\n";
            }

            // Sample Data
            if (!empty($tableInfo['sample_data'])) {
                $html .= "<h3>Sample Data (First 5 Rows)</h3>\n<table>\n";
                $html .= "<tr>";
                foreach (array_keys($tableInfo['sample_data'][0]) as $colName) {
                    $html .= "<th>{$colName}</th>";
                }
                $html .= "</tr>\n";
                foreach ($tableInfo['sample_data'] as $row) {
                    $html .= "<tr>";
                    foreach ($row as $value) {
                        $displayValue = htmlspecialchars(substr($value ?? '', 0, 100));
                        $html .= "<td>{$displayValue}</td>";
                    }
                    $html .= "</tr>\n";
                }
                $html .= "</table>\n";
            }

            // Errors
            if (!empty($tableInfo['errors'])) {
                $html .= "<h3 class='error'>Errors</h3>\n<ul>\n";
                foreach ($tableInfo['errors'] as $error) {
                    $html .= "<li class='error'>{$error}</li>\n";
                }
                $html .= "</ul>\n";
            }

            $html .= "</div>\n";
        }

        $html .= "</div>\n</body>\n</html>";
        
        return $html;
    }

    /**
     * Generate JSON report
     */
    public function generateJsonReport(): string
    {
        $results = $this->results ?: $this->scanAllCalendarTables();
        return json_encode($results, JSON_PRETTY_PRINT);
    }
}

// Main execution
try {
    // Create database connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $scanner = new CalendarTableScanner($pdo);
    $scanner->scanAllCalendarTables();

    // Determine output format
    $format = $_GET['format'] ?? 'html';

    if ($format === 'json') {
        header('Content-Type: application/json');
        echo $scanner->generateJsonReport();
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo $scanner->generateHtmlReport();
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "Database connection failed: " . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    echo "An error occurred: " . $e->getMessage();
}
