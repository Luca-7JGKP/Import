<?php
/**
 * Calendar Import Debug Tool
 * 
 * Improved calendar detection with dynamic table scanning
 * and WoltLab Calendar API as primary source
 * 
 * @author Luca-7JGKP
 * @version 2.0.0
 * @date 2026-01-01
 */

namespace CalendarImport;

use wcf\data\calendar\Calendar;
use wcf\data\calendar\CalendarList;
use wcf\data\calendar\event\CalendarEvent;
use wcf\data\calendar\event\CalendarEventList;
use wcf\system\database\DatabaseException;
use wcf\system\WCF;

class CalendarImportDebug {
    
    /**
     * @var array Detected calendar tables
     */
    private array $detectedTables = [];
    
    /**
     * @var array Import statistics
     */
    private array $stats = [
        'tables_scanned' => 0,
        'calendars_found' => 0,
        'events_found' => 0,
        'events_imported' => 0,
        'errors' => []
    ];
    
    /**
     * @var bool Use WoltLab API as primary source
     */
    private bool $useWoltLabAPI = true;
    
    /**
     * Constructor
     * 
     * @param bool $useWoltLabAPI Whether to use WoltLab Calendar API as primary source
     */
    public function __construct(bool $useWoltLabAPI = true) {
        $this->useWoltLabAPI = $useWoltLabAPI;
    }
    
    /**
     * Run the calendar detection and import process
     * 
     * @return array Import results and statistics
     */
    public function run(): array {
        $this->log("Starting calendar import debug process...");
        $this->log("Primary source: " . ($this->useWoltLabAPI ? "WoltLab Calendar API" : "Direct Database"));
        
        $calendars = [];
        $events = [];
        
        // Primary: Try WoltLab Calendar API first
        if ($this->useWoltLabAPI) {
            $apiResult = $this->fetchFromWoltLabAPI();
            if ($apiResult['success']) {
                $calendars = array_merge($calendars, $apiResult['calendars']);
                $events = array_merge($events, $apiResult['events']);
                $this->log("WoltLab API: Found {$apiResult['calendar_count']} calendars, {$apiResult['event_count']} events");
            } else {
                $this->log("WoltLab API unavailable, falling back to database scan");
                $this->stats['errors'][] = "WoltLab API: " . $apiResult['error'];
            }
        }
        
        // Secondary: Scan database tables dynamically
        $this->scanCalendarTables();
        
        if (!empty($this->detectedTables)) {
            $dbResult = $this->fetchFromDatabaseTables();
            $calendars = array_merge($calendars, $dbResult['calendars']);
            $events = array_merge($events, $dbResult['events']);
        }
        
        // Remove duplicates based on unique identifiers
        $calendars = $this->deduplicateCalendars($calendars);
        $events = $this->deduplicateEvents($events);
        
        $this->stats['calendars_found'] = count($calendars);
        $this->stats['events_found'] = count($events);
        
        return [
            'calendars' => $calendars,
            'events' => $events,
            'detected_tables' => $this->detectedTables,
            'stats' => $this->stats
        ];
    }
    
    /**
     * Fetch calendars and events using WoltLab Calendar API
     * 
     * @return array API fetch results
     */
    private function fetchFromWoltLabAPI(): array {
        $result = [
            'success' => false,
            'calendars' => [],
            'events' => [],
            'calendar_count' => 0,
            'event_count' => 0,
            'error' => null
        ];
        
        try {
            // Check if WoltLab Calendar classes exist
            if (!class_exists(CalendarList::class)) {
                throw new \Exception("WoltLab Calendar package not installed");
            }
            
            // Fetch all calendars
            $calendarList = new CalendarList();
            $calendarList->readObjects();
            
            foreach ($calendarList->getObjects() as $calendar) {
                $result['calendars'][] = [
                    'id' => $calendar->calendarID,
                    'title' => $calendar->title,
                    'description' => $calendar->description ?? '',
                    'is_public' => $calendar->isPublic ?? true,
                    'source' => 'woltlab_api',
                    'raw_object' => $calendar
                ];
                
                // Fetch events for this calendar
                $eventList = new CalendarEventList();
                $eventList->getConditionBuilder()->add('calendarID = ?', [$calendar->calendarID]);
                $eventList->readObjects();
                
                foreach ($eventList->getObjects() as $event) {
                    $result['events'][] = $this->normalizeEvent($event, 'woltlab_api');
                }
            }
            
            $result['success'] = true;
            $result['calendar_count'] = count($result['calendars']);
            $result['event_count'] = count($result['events']);
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $this->log("WoltLab API Error: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Dynamically scan database for calendar-related tables
     */
    private function scanCalendarTables(): void {
        $this->log("Scanning database for calendar tables...");
        
        $patterns = [
            '%calendar%',
            '%event%',
            '%schedule%',
            '%appointment%'
        ];
        
        try {
            $sql = "SHOW TABLES";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            
            $allTables = $statement->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($allTables as $table) {
                foreach ($patterns as $pattern) {
                    $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], $pattern) . '$/i';
                    if (preg_match($regex, $table)) {
                        $tableInfo = $this->analyzeTable($table);
                        if ($tableInfo['is_calendar_related']) {
                            $this->detectedTables[$table] = $tableInfo;
                            $this->log("Detected calendar table: {$table} (type: {$tableInfo['type']})");
                        }
                        break;
                    }
                }
            }
            
            $this->stats['tables_scanned'] = count($allTables);
            $this->log("Scanned " . count($allTables) . " tables, found " . count($this->detectedTables) . " calendar-related tables");
            
        } catch (DatabaseException $e) {
            $this->stats['errors'][] = "Database scan error: " . $e->getMessage();
            $this->log("Database scan error: " . $e->getMessage());
        }
    }
    
    /**
     * Analyze a table to determine if it's calendar-related
     * 
     * @param string $tableName Table name to analyze
     * @return array Table analysis results
     */
    private function analyzeTable(string $tableName): array {
        $result = [
            'name' => $tableName,
            'is_calendar_related' => false,
            'type' => 'unknown',
            'columns' => [],
            'row_count' => 0
        ];
        
        try {
            // Get column information - Table name from dynamic scan, validate for safety
            if (preg_match('/^[a-z0-9_]+$/i', $tableName)) {
                $sql = "DESCRIBE `{$tableName}`";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $columns = $statement->fetchAll(\PDO::FETCH_ASSOC);
            
            $columnNames = array_column($columns, 'Field');
            $result['columns'] = $columnNames;
            
            // Determine table type based on columns
            $eventIndicators = ['start_time', 'end_time', 'startTime', 'endTime', 'event_date', 'eventDate'];
            $calendarIndicators = ['calendar_id', 'calendarID', 'calendar_name'];
            
            $hasEventColumns = !empty(array_intersect($columnNames, $eventIndicators));
            $hasCalendarColumns = !empty(array_intersect($columnNames, $calendarIndicators));
            
            if ($hasEventColumns) {
                $result['is_calendar_related'] = true;
                $result['type'] = 'events';
            } elseif ($hasCalendarColumns || stripos($tableName, 'calendar') !== false) {
                $result['is_calendar_related'] = true;
                $result['type'] = 'calendars';
            }
            
            // Get row count - Table name from dynamic scan, validate for safety
            if ($result['is_calendar_related'] && preg_match('/^[a-z0-9_]+$/i', $tableName)) {
                $sql = "SELECT COUNT(*) FROM `{$tableName}`";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $result['row_count'] = $statement->fetchColumn();
            }
            
        } catch (DatabaseException $e) {
            $this->log("Error analyzing table {$tableName}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Fetch data from detected database tables
     * 
     * @return array Database fetch results
     */
    private function fetchFromDatabaseTables(): array {
        $result = [
            'calendars' => [],
            'events' => []
        ];
        
        foreach ($this->detectedTables as $tableName => $tableInfo) {
            try {
                // Table name from dynamic scan, validate for safety
                if (preg_match('/^[a-z0-9_]+$/i', $tableName)) {
                    $sql = "SELECT * FROM `{$tableName}`";
                    $statement = WCF::getDB()->prepareStatement($sql);
                    $statement->execute();
                    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    if ($tableInfo['type'] === 'calendars') {
                        $result['calendars'][] = $this->normalizeCalendarFromDB($row, $tableName);
                    } elseif ($tableInfo['type'] === 'events') {
                        $result['events'][] = $this->normalizeEventFromDB($row, $tableName);
                    }
                }
                }
            } catch (DatabaseException $e) {
                $this->stats['errors'][] = "Error fetching from {$tableName}: " . $e->getMessage();
            }
        }
        
        return $result;
    }
    
    /**
     * Normalize an event object from WoltLab API
     * 
     * @param CalendarEvent $event Event object
     * @param string $source Source identifier
     * @return array Normalized event data
     */
    private function normalizeEvent($event, string $source): array {
        return [
            'id' => $event->eventID ?? $event->getObjectID(),
            'calendar_id' => $event->calendarID ?? null,
            'title' => $event->title ?? $event->getTitle(),
            'description' => $event->description ?? '',
            'location' => $event->location ?? '',
            'start_time' => $event->startTime ?? null,
            'end_time' => $event->endTime ?? null,
            'all_day' => $event->isFullDay ?? false,
            'recurring' => $event->isRecurring ?? false,
            'user_id' => $event->userID ?? null,
            'created_at' => $event->time ?? null,
            'source' => $source,
            'raw_data' => $event
        ];
    }
    
    /**
     * Normalize calendar data from database row
     * 
     * @param array $row Database row
     * @param string $tableName Source table name
     * @return array Normalized calendar data
     */
    private function normalizeCalendarFromDB(array $row, string $tableName): array {
        // Map common column names to standard fields
        $idField = $this->findColumn($row, ['calendarID', 'calendar_id', 'id']);
        $titleField = $this->findColumn($row, ['title', 'name', 'calendar_name', 'calendarName']);
        $descField = $this->findColumn($row, ['description', 'desc', 'calendar_description']);
        
        return [
            'id' => $row[$idField] ?? null,
            'title' => $row[$titleField] ?? 'Unknown Calendar',
            'description' => $row[$descField] ?? '',
            'is_public' => true,
            'source' => 'database:' . $tableName,
            'raw_data' => $row
        ];
    }
    
    /**
     * Normalize event data from database row
     * 
     * @param array $row Database row
     * @param string $tableName Source table name
     * @return array Normalized event data
     */
    private function normalizeEventFromDB(array $row, string $tableName): array {
        $idField = $this->findColumn($row, ['eventID', 'event_id', 'id']);
        $calIdField = $this->findColumn($row, ['calendarID', 'calendar_id']);
        $titleField = $this->findColumn($row, ['title', 'name', 'subject', 'event_title']);
        $descField = $this->findColumn($row, ['description', 'content', 'body', 'text']);
        $startField = $this->findColumn($row, ['startTime', 'start_time', 'start_date', 'event_start']);
        $endField = $this->findColumn($row, ['endTime', 'end_time', 'end_date', 'event_end']);
        
        return [
            'id' => $row[$idField] ?? null,
            'calendar_id' => $row[$calIdField] ?? null,
            'title' => $row[$titleField] ?? 'Untitled Event',
            'description' => $row[$descField] ?? '',
            'location' => $row['location'] ?? '',
            'start_time' => $row[$startField] ?? null,
            'end_time' => $row[$endField] ?? null,
            'all_day' => $row['isFullDay'] ?? $row['all_day'] ?? false,
            'recurring' => $row['isRecurring'] ?? $row['recurring'] ?? false,
            'user_id' => $row['userID'] ?? $row['user_id'] ?? null,
            'created_at' => $row['time'] ?? $row['created_at'] ?? null,
            'source' => 'database:' . $tableName,
            'raw_data' => $row
        ];
    }
    
    /**
     * Find the first matching column from a list of possible names
     * 
     * @param array $row Database row
     * @param array $possibleNames List of possible column names
     * @return string|null Matching column name or null
     */
    private function findColumn(array $row, array $possibleNames): ?string {
        foreach ($possibleNames as $name) {
            if (array_key_exists($name, $row)) {
                return $name;
            }
        }
        return null;
    }
    
    /**
     * Remove duplicate calendars based on ID
     * 
     * @param array $calendars Array of calendar data
     * @return array Deduplicated calendars
     */
    private function deduplicateCalendars(array $calendars): array {
        $unique = [];
        $seen = [];
        
        foreach ($calendars as $calendar) {
            $key = $calendar['id'] . ':' . ($calendar['title'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $calendar;
            }
        }
        
        return $unique;
    }
    
    /**
     * Remove duplicate events based on ID and start time
     * 
     * @param array $events Array of event data
     * @return array Deduplicated events
     */
    private function deduplicateEvents(array $events): array {
        $unique = [];
        $seen = [];
        
        foreach ($events as $event) {
            $key = $event['id'] . ':' . $event['start_time'] . ':' . ($event['title'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $event;
            }
        }
        
        return $unique;
    }
    
    /**
     * Log a message
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }
    
    /**
     * Get import statistics
     * 
     * @return array Statistics array
     */
    public function getStats(): array {
        return $this->stats;
    }
    
    /**
     * Get detected tables
     * 
     * @return array Detected tables information
     */
    public function getDetectedTables(): array {
        return $this->detectedTables;
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    require_once(__DIR__ . '/global.php');
    
    echo "=== Calendar Import Debug Tool v2.0.0 ===\n";
    echo "Using WoltLab Calendar API as primary source\n\n";
    
    $importer = new CalendarImportDebug(true);
    $result = $importer->run();
    
    echo "\n=== Results ===\n";
    echo "Calendars found: " . count($result['calendars']) . "\n";
    echo "Events found: " . count($result['events']) . "\n";
    echo "Tables detected: " . count($result['detected_tables']) . "\n";
    
    if (!empty($result['stats']['errors'])) {
        echo "\nErrors:\n";
        foreach ($result['stats']['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
    
    echo "\nDetected Calendar Tables:\n";
    foreach ($result['detected_tables'] as $table => $info) {
        echo "  - {$table} (type: {$info['type']}, rows: {$info['row_count']})\n";
    }
}
