<?php
namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\cronjob\AbstractCronjob;
use wcf\system\WCF;

/**
 * Importiert Events aus einer ICS-URL in den WoltLab-Kalender.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.5.0
 */
class ICalImportCronjob extends AbstractCronjob
{
    protected $importedCount = 0;
    protected $updatedCount = 0;
    protected $skippedCount = 0;
    protected $errors = [];
    
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);
        
        $icsUrl = $this->getOption('CALENDAR_IMPORT_ICS_URL');
        $calendarID = (int)$this->getOption('CALENDAR_IMPORT_CALENDAR_ID');
        
        if (empty($icsUrl)) {
            $this->log('error', 'Keine ICS-URL konfiguriert');
            return;
        }
        
        if ($calendarID <= 0) {
            $this->log('error', 'Keine Kalender-ID konfiguriert');
            return;
        }
        
        // Validate calendar exists
        if (!$this->validateCalendarExists($calendarID)) {
            $this->log('error', "Kalender mit ID {$calendarID} existiert nicht. Bitte überprüfen Sie die Kalender-ID in den Einstellungen.");
            return;
        }
        
        $this->log('info', "Starte ICS-Import von: {$icsUrl}");
        
        try {
            $icsContent = $this->fetchIcsContent($icsUrl);
            if (!$icsContent) {
                $this->log('error', 'ICS-Inhalt konnte nicht abgerufen werden');
                return;
            }
            
            $events = $this->parseIcsContent($icsContent);
            $this->log('info', count($events) . ' Events in ICS gefunden');
            
            $maxEvents = (int)$this->getOption('CALENDAR_IMPORT_MAX_EVENTS', 100);
            $events = array_slice($events, 0, $maxEvents);
            
            foreach ($events as $event) {
                $this->importEvent($event, $calendarID);
            }
            
            $this->log('info', "Import abgeschlossen: {$this->importedCount} neu, {$this->updatedCount} aktualisiert, {$this->skippedCount} übersprungen");
            
        } catch (\Exception $e) {
            $this->log('error', 'Import-Fehler: ' . $e->getMessage());
        }
    }
    
    protected function fetchIcsContent($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'WoltLab Calendar Import/1.5.0'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'WoltLab Calendar Import/1.5.0'
            ]);
            $content = curl_exec($ch);
            curl_close($ch);
        }
        
        return $content;
    }
    
    protected function parseIcsContent($content)
    {
        $events = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        $currentEvent = null;
        $inEvent = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $currentEvent = [
                    'uid' => '',
                    'summary' => '',
                    'description' => '',
                    'location' => '',
                    'dtstart' => null,
                    'dtend' => null,
                    'allday' => false
                ];
                continue;
            }
            
            if ($line === 'END:VEVENT') {
                if ($currentEvent && !empty($currentEvent['uid']) && $currentEvent['dtstart']) {
                    $events[] = $currentEvent;
                }
                $inEvent = false;
                $currentEvent = null;
                continue;
            }
            
            if (!$inEvent || !$currentEvent) {
                continue;
            }
            
            if (strpos($line, ':') === false) {
                continue;
            }
            
            list($key, $value) = explode(':', $line, 2);
            $keyParts = explode(';', $key);
            $keyName = strtoupper($keyParts[0]);
            
            switch ($keyName) {
                case 'UID':
                    $currentEvent['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $currentEvent['summary'] = $this->unescapeIcsValue($value);
                    break;
                case 'DESCRIPTION':
                    $currentEvent['description'] = $this->unescapeIcsValue($value);
                    break;
                case 'LOCATION':
                    $currentEvent['location'] = $this->unescapeIcsValue($value);
                    break;
                case 'DTSTART':
                    $currentEvent['dtstart'] = $this->parseIcsDate($value, $key);
                    if (strpos($key, 'VALUE=DATE') !== false && strpos($key, 'VALUE=DATE-TIME') === false) {
                        $currentEvent['allday'] = true;
                    }
                    break;
                case 'DTEND':
                    $currentEvent['dtend'] = $this->parseIcsDate($value, $key);
                    break;
            }
        }
        
        return $events;
    }
    
    protected function parseIcsDate($value, $key)
    {
        $value = preg_replace('/[^0-9TZ]/', '', $value);
        
        if (strlen($value) === 8) {
            $dt = \DateTime::createFromFormat('Ymd', $value);
            if ($dt) {
                $dt->setTime(0, 0, 0);
                return $dt->getTimestamp();
            }
        }
        
        if (preg_match('/(\d{8})T(\d{6})Z?/', $value, $matches)) {
            $dateStr = $matches[1] . 'T' . $matches[2];
            if (substr($value, -1) === 'Z') {
                $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, new \DateTimeZone('UTC'));
            } else {
                $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr);
            }
            if ($dt) {
                return $dt->getTimestamp();
            }
        }
        
        return null;
    }
    
    protected function unescapeIcsValue($value)
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\;', ';', $value);
        $value = str_replace('\\\\', '\\', $value);
        return trim($value);
    }
    
    protected function importEvent($event, $calendarID)
    {
        $existingEventID = $this->findExistingEvent($event['uid']);
        
        if ($existingEventID) {
            $this->updateEvent($existingEventID, $event, $calendarID);
            $this->updatedCount++;
        } else {
            $this->createEvent($event, $calendarID);
            $this->importedCount++;
        }
    }
    
    protected function findExistingEvent($uid)
    {
        try {
            $sql = "SELECT eventID FROM calendar1_event WHERE externalSource = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchArray();
            return $row ? $row['eventID'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function createEvent($event, $calendarID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            $sql = "INSERT INTO calendar1_event 
                    (calendarID, userID, username, subject, message, time, enableHtml, externalSource, eventDate)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $calendarID,
                WCF::getUser()->userID ?: 1,
                WCF::getUser()->username ?: 'System',
                $event['summary'],
                $event['description'] ?: $event['summary'],
                TIME_NOW,
                0,
                $event['uid'],
                $eventDateData
            ]);
            
            $eventID = WCF::getDB()->getInsertID('calendar1_event', 'eventID');
            
            $sql = "INSERT INTO calendar1_event_date (eventID, startTime, endTime, isFullDay)
                    VALUES (?, ?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $eventID,
                $event['dtstart'],
                $endTime,
                $event['allday'] ? 1 : 0
            ]);
            
            $this->log('debug', "Event erstellt: {$event['summary']} (ID: {$eventID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Erstellen: {$event['summary']} - " . $e->getMessage());
            $this->skippedCount++;
        }
    }
    
    protected function updateEvent($eventID, $event, $calendarID)
    {
        try {
            $endTime = $event['dtend'] ?: ($event['dtstart'] + 3600);
            
            $eventDateData = serialize([
                'startTime' => $event['dtstart'],
                'endTime' => $endTime,
                'isFullDay' => $event['allday'] ? 1 : 0,
                'timezone' => 'Europe/Berlin',
                'repeatType' => ''
            ]);
            
            $sql = "UPDATE calendar1_event 
                    SET subject = ?, message = ?, eventDate = ?, time = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['summary'],
                $event['description'] ?: $event['summary'],
                $eventDateData,
                TIME_NOW,
                $eventID
            ]);
            
            $sql = "UPDATE calendar1_event_date 
                    SET startTime = ?, endTime = ?, isFullDay = ?
                    WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([
                $event['dtstart'],
                $endTime,
                $event['allday'] ? 1 : 0,
                $eventID
            ]);
            
            if ($this->getOption('CALENDAR_IMPORT_MARK_UPDATED_UNREAD', true)) {
                $this->markEventAsUnread($eventID);
            }
            
            $this->log('debug', "Event aktualisiert: {$event['summary']} (ID: {$eventID})");
            
        } catch (\Exception $e) {
            $this->log('error', "Fehler beim Aktualisieren: {$event['summary']} - " . $e->getMessage());
        }
    }
    
    protected function markEventAsUnread($eventID)
    {
        try {
            $objectTypeID = $this->getCalendarEventObjectTypeID();
            if ($objectTypeID) {
                $sql = "DELETE FROM wcf".WCF_N."_tracked_visit WHERE objectTypeID = ? AND objectID = ?";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute([$objectTypeID, $eventID]);
            }
        } catch (\Exception $e) {}
    }
    
    protected function getCalendarEventObjectTypeID()
    {
        static $objectTypeID = null;
        if ($objectTypeID === null) {
            try {
                $sql = "SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType = 'com.woltlab.calendar.event'";
                $statement = WCF::getDB()->prepareStatement($sql);
                $statement->execute();
                $row = $statement->fetchArray();
                $objectTypeID = $row ? $row['objectTypeID'] : 0;
            } catch (\Exception $e) {
                $objectTypeID = 0;
            }
        }
        return $objectTypeID ?: null;
    }
    
    protected function validateCalendarExists($calendarID)
    {
        try {
            // Try calendar1_calendar table first (standard WoltLab Calendar)
            $sql = "SELECT calendarID FROM calendar1_calendar WHERE calendarID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$calendarID]);
            $calendar = $statement->fetchArray();
            
            if ($calendar) {
                return true;
            }
            
            // Fallback: try with dynamic table prefix
            $sql = "SELECT calendarID FROM calendar".WCF_N."_calendar WHERE calendarID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$calendarID]);
            $calendar = $statement->fetchArray();
            
            return (bool)$calendar;
        } catch (\Exception $e) {
            $this->log('error', 'Fehler bei Kalender-Validierung: ' . $e->getMessage());
            return false;
        }
    }
    
    protected function getOption($name, $default = null)
    {
        if (defined($name)) {
            return constant($name);
        }
        return $default;
    }
    
    protected function log($level, $message)
    {
        $configuredLevel = $this->getOption('CALENDAR_IMPORT_LOG_LEVEL', 'info');
        $levels = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];
        
        if (!isset($levels[$level]) || !isset($levels[$configuredLevel])) {
            return;
        }
        
        if ($levels[$level] <= $levels[$configuredLevel]) {
            error_log("[Calendar Import] [{$level}] {$message}");
        }
    }
}
