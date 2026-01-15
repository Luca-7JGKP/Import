<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Markiert abgelaufene Events als gelesen für alle Benutzer.
 * 
 * Logik:
 * - Events deren startTime in der Vergangenheit liegt werden als gelesen markiert
 * - Zukünftige Events bleiben ungelesen (außer der Benutzer hat sie gelesen)
 * - Aktualisierte Events werden wieder ungelesen (in ICalImportExtensionEventListener)
 * 
 * Security:
 * - All database queries use parameterized statements for SQL injection protection
 * - Batch operations for performance optimization
 * - Error handling prevents cronjob failures
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.5.0
 */
class MarkPastEventsReadCronjob extends AbstractCronjob
{
    /**
     * Execute cronjob to mark past events as read.
     * Uses batch operations for performance with parameterized queries.
     * 
     * @param Cronjob $cronjob Cronjob instance
     */
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);
        
        // Prüfe ob die Option aktiviert ist
        if (!$this->shouldAutoMarkPastAsRead()) {
            $this->log('debug', 'Auto-mark past as read is disabled');
            return;
        }
        
        // Hole die Object-Type-ID für Kalender-Events
        $objectTypeID = $this->getCalendarEventObjectTypeID();
        if (!$objectTypeID) {
            $this->log('error', 'Calendar event object type not found');
            return;
        }
        
        // Hole alle abgelaufenen Events die noch nicht für alle als gelesen markiert sind
        // Uses parameterized query for SQL injection protection
        $sql = "SELECT DISTINCT e.eventID, ed.startTime
                FROM calendar1_event e
                JOIN calendar1_event_date ed ON e.eventID = ed.eventID
                WHERE ed.startTime < ?
                AND ed.startTime > ?
                ORDER BY ed.startTime DESC";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        // Nur Events der letzten 30 Tage prüfen (Performance)
        $statement->execute([TIME_NOW, TIME_NOW - (30 * 86400)]);
        
        $pastEventIDs = [];
        while ($row = $statement->fetchArray()) {
            $pastEventIDs[] = $row['eventID'];
        }
        
        if (empty($pastEventIDs)) {
            $this->log('debug', 'No past events to mark as read');
            return;
        }
        
        $this->log('info', 'Found ' . count($pastEventIDs) . ' past events to mark as read');
        
        // Hole alle aktiven Benutzer (parameterized query)
        $sql = "SELECT userID FROM wcf".WCF_N."_user WHERE banned = 0 AND activationCode = 0";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        
        $userIDs = [];
        while ($row = $statement->fetchArray()) {
            $userIDs[] = $row['userID'];
        }
        
        if (empty($userIDs)) {
            $this->log('warning', 'No active users found');
            return;
        }
        
        // Markiere alle abgelaufenen Events als gelesen für alle Benutzer
        // Verwende Batch-INSERT für bessere Performance
        $batchSize = 100;
        $values = [];
        $parameters = [];
        $totalInserted = 0;
        
        foreach ($pastEventIDs as $eventID) {
            foreach ($userIDs as $userID) {
                $values[] = "(?, ?, ?, ?)";
                $parameters[] = $objectTypeID;
                $parameters[] = $eventID;
                $parameters[] = $userID;
                $parameters[] = TIME_NOW;
                
                // Insert in Batches von 100
                if (count($values) >= $batchSize) {
                    $inserted = $this->executeBatchInsert($values, $parameters);
                    $totalInserted += $inserted;
                    $values = [];
                    $parameters = [];
                }
            }
        }
        
        // Verbleibende Werte einfügen
        if (!empty($values)) {
            $inserted = $this->executeBatchInsert($values, $parameters);
            $totalInserted += $inserted;
        }
        
        $this->log('info', "Marked {$totalInserted} event-user combinations as read");
    }
    
    /**
     * Execute batch insert with parameterized query for SQL injection protection.
     * Uses INSERT IGNORE to avoid errors on duplicate entries.
     * 
     * @param array $values Value placeholders
     * @param array $parameters Parameter values
     * @return int Number of rows inserted
     */
    protected function executeBatchInsert($values, $parameters) {
        try {
            // Parameterized batch insert - SQL injection safe
            $sql = "INSERT IGNORE INTO wcf".WCF_N."_tracked_visit 
                    (objectTypeID, objectID, userID, visitTime) 
                    VALUES " . implode(', ', $values);
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute($parameters);
            $affectedRows = $statement->getAffectedRows();
            
            $this->log('debug', "Batch insert completed: {$affectedRows} rows inserted");
            return $affectedRows;
        } catch (\Exception $e) {
            $this->log('error', 'Batch insert failed', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Check if auto-marking past events as read is enabled.
     * 
     * @return bool
     */
    protected function shouldAutoMarkPastAsRead()
    {
        return defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ') 
            ? (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ 
            : true;
    }
    
    /**
     * Get calendar event object type ID.
     * Uses parameterized query for SQL injection protection.
     * Caches result for performance.
     * 
     * @return int|null Object type ID or null if not found
     */
    protected function getCalendarEventObjectTypeID()
    {
        static $objectTypeID = null;
        if ($objectTypeID === null) {
            try {
                // Parameterized query - SQL injection safe
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute(['com.woltlab.calendar.event']);
                $row = $statement->fetchArray();
                $objectTypeID = $row ? $row['objectTypeID'] : 0;
                
                $this->log('debug', 'Calendar event object type resolved', [
                    'objectTypeID' => $objectTypeID
                ]);
            } catch (\Exception $e) {
                $this->log('error', 'Failed to get object type', [
                    'error' => $e->getMessage()
                ]);
                $objectTypeID = 0;
            }
        }
        return $objectTypeID ?: null;
    }
    
    /**
     * Log message with optional context.
     * 
     * @param string $level Log level (error, warning, info, debug)
     * @param string $message Log message
     * @param array $context Optional context data
     */
    protected function log($level, $message, array $context = [])
    {
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        $defaultLogLevel = 2; // info level
        
        // Validate log level
        if (!isset($levels[$level])) {
            return;
        }
        
        $currentLevel = defined('CALENDAR_IMPORT_LOG_LEVEL') ? CALENDAR_IMPORT_LOG_LEVEL : 'info';
        $currentLevelNum = $levels[$currentLevel] ?? $defaultLogLevel;
        
        if ($levels[$level] <= $currentLevelNum) {
            $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
            error_log("[MarkPastEventsRead v1.5] [{$level}] {$message}{$contextStr}");
        }
    }
}