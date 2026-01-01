<?php
namespace wcf\system\calendar;

use wcf\system\SingletonFactory;
use wcf\system\WCF;

/**
 * Zentrale Klasse für die Verwaltung des Gelesen/Ungelesen-Status von Kalender-Events.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.5.0
 */
class ReadStatusHandler extends SingletonFactory {
    
    protected $tableName = 'wcf1_calendar_event_read_status';
    
    /**
     * Markiert ein Event als gelesen für einen Benutzer.
     */
    public function markAsRead($eventID, $userID = null, $automatic = false) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        if (!$userID || !$eventID) return false;
        
        try {
            $sql = "INSERT INTO {$this->tableName} (eventID, userID, isRead, readTime, markedReadAutomatically)
                    VALUES (?, ?, 1, ?, ?)
                    ON DUPLICATE KEY UPDATE isRead = 1, readTime = VALUES(readTime), markedReadAutomatically = VALUES(markedReadAutomatically)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $userID, TIME_NOW, $automatic ? 1 : 0]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Markiert ein Event als ungelesen für einen Benutzer.
     */
    public function markAsUnread($eventID, $userID = null) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        if (!$userID || !$eventID) return false;
        
        try {
            $sql = "INSERT INTO {$this->tableName} (eventID, userID, isRead, readTime)
                    VALUES (?, ?, 0, NULL)
                    ON DUPLICATE KEY UPDATE isRead = 0, readTime = NULL, markedReadAutomatically = 0";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $userID]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Markiert ein Event als ungelesen für ALLE Benutzer (z.B. bei Update).
     */
    public function markAsUnreadForAll($eventID) {
        if (!$eventID) return false;
        
        try {
            $sql = "UPDATE {$this->tableName} SET isRead = 0, readTime = NULL, markedReadAutomatically = 0 WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Markiert ein Event als gelesen für ALLE aktiven Benutzer (z.B. vergangene Events).
     */
    public function markAsReadForAll($eventID) {
        if (!$eventID) return false;
        
        try {
            $sql = "INSERT INTO {$this->tableName} (eventID, userID, isRead, readTime, markedReadAutomatically)
                    SELECT ?, userID, 1, ?, 1
                    FROM wcf1_user
                    WHERE banned = 0 AND activationCode = 0
                    ON DUPLICATE KEY UPDATE isRead = 1, readTime = VALUES(readTime), markedReadAutomatically = 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, TIME_NOW]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Prüft ob ein Event für einen Benutzer gelesen ist.
     */
    public function isRead($eventID, $userID = null) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        if (!$userID || !$eventID) return false;
        
        try {
            $sql = "SELECT isRead FROM {$this->tableName} WHERE eventID = ? AND userID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID, $userID]);
            $row = $statement->fetchArray();
            return $row ? (bool)$row['isRead'] : false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Holt den Lese-Status für mehrere Events.
     */
    public function getReadStatusForEvents(array $eventIDs, $userID = null) {
        if ($userID === null) {
            $userID = WCF::getUser()->userID;
        }
        if (!$userID || empty($eventIDs)) return [];
        
        $result = array_fill_keys($eventIDs, false);
        
        try {
            $placeholders = implode(',', array_fill(0, count($eventIDs), '?'));
            $sql = "SELECT eventID, isRead FROM {$this->tableName} WHERE eventID IN ({$placeholders}) AND userID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute(array_merge($eventIDs, [$userID]));
            
            while ($row = $statement->fetchArray()) {
                $result[$row['eventID']] = (bool)$row['isRead'];
            }
        } catch (\Exception $e) {}
        
        return $result;
    }
    
    /**
     * Löscht alle Status-Einträge für ein Event (z.B. bei Löschung).
     */
    public function deleteForEvent($eventID) {
        if (!$eventID) return;
        
        try {
            $sql = "DELETE FROM {$this->tableName} WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
        } catch (\Exception $e) {}
    }
}
