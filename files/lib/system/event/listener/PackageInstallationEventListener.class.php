<?php
namespace wcf\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;

/**
 * Event listener for package installation/update to schedule cronjobs.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.7.0
 */
class PackageInstallationEventListener implements IParameterizedEventListener
{
    /**
     * @inheritDoc
     */
    public function execute($eventObj, $className, $eventName, array &$parameters)
    {
        // Schedule cronjobs immediately after package installation/update
        $this->scheduleCronjobs();
    }
    
    /**
     * Schedule all cronjobs to run at next execution time
     */
    protected function scheduleCronjobs()
    {
        try {
            $cronjobClasses = [
                'wcf\\system\\cronjob\\ICalImportCronjob',
                'wcf\\system\\cronjob\\FixTimezoneCronjob',
                'wcf\\system\\cronjob\\MarkPastEventsReadCronjob'
            ];
            
            foreach ($cronjobClasses as $className) {
                $sql = "SELECT cronjobID, startminute, starthour, startdom, startmonth, startdow 
                        FROM wcf".WCF_N."_cronjob 
                        WHERE className = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$className]);
                $cronjob = $statement->fetchArray();
                
                if ($cronjob) {
                    // Calculate next execution time based on cronjob schedule
                    $nextExec = $this->calculateNextExec($cronjob);
                    
                    // Update cronjob to schedule next execution
                    $sql = "UPDATE wcf".WCF_N."_cronjob 
                            SET nextExec = ?, afterNextExec = 0 
                            WHERE cronjobID = ?";
                    $statement = WCF::getDB()->prepareStatement($sql);
                    $statement->execute([$nextExec, $cronjob['cronjobID']]);
                }
            }
        } catch (\Exception $e) {
            // Silently fail - cronjobs will be scheduled on next cronjob check
        }
    }
    
    /**
     * Calculate next execution time based on cronjob schedule
     * 
     * @param array $cronjob
     * @return int
     */
    protected function calculateNextExec($cronjob)
    {
        // Simple calculation: schedule for next matching minute
        $now = TIME_NOW;
        $currentMinute = (int)date('i', $now);
        $currentHour = (int)date('H', $now);
        
        // Parse startminute (e.g., "0,30" or "*")
        $minutes = $this->parseScheduleValue($cronjob['startminute'], 0, 59);
        $hours = $this->parseScheduleValue($cronjob['starthour'], 0, 23);
        
        // Find next matching time
        foreach ($hours as $hour) {
            foreach ($minutes as $minute) {
                $time = mktime($hour, $minute, 0, date('n', $now), date('j', $now), date('Y', $now));
                if ($time > $now) {
                    return $time;
                }
            }
        }
        
        // If no time found today, schedule for tomorrow
        foreach ($hours as $hour) {
            foreach ($minutes as $minute) {
                $time = mktime($hour, $minute, 0, date('n', $now), date('j', $now) + 1, date('Y', $now));
                if ($time > $now) {
                    return $time;
                }
            }
        }
        
        // Fallback: schedule for 1 hour from now
        return $now + 3600;
    }
    
    /**
     * Parse schedule value (e.g., "0,30" or "*")
     * 
     * @param string $value
     * @param int $min
     * @param int $max
     * @return array
     */
    protected function parseScheduleValue($value, $min, $max)
    {
        if ($value === '*') {
            return [$min];
        }
        
        $parts = explode(',', $value);
        $result = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (is_numeric($part)) {
                $num = (int)$part;
                if ($num >= $min && $num <= $max) {
                    $result[] = $num;
                }
            }
        }
        
        return !empty($result) ? $result : [$min];
    }
}
