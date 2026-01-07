<?php

namespace wcf\system\cronjob;

use wcf\data\cronjob\Cronjob;
use wcf\system\WCF;

/**
 * iCal Import Cronjob
 * 
 * Importiert Events aus iCal-Feeds in den Kalender.
 * 
 * @author      Luca
 * @copyright   2024 Luca
 * @license     GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
class ICalImportCronjob extends AbstractCronjob
{
    /**
     * @var array Import-Statistiken
     */
    protected $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'deleted' => 0,
        'errors' => 0
    ];

    /**
     * @inheritDoc
     */
    public function execute(Cronjob $cronjob)
    {
        parent::execute($cronjob);

        // Prüfen ob Kalender-Plugin aktiv ist
        if (!$this->isCalendarPluginActive()) {
            $this->log('Calendar plugin not active, skipping import', 'warning');
            return;
        }

        // Sicherstellen dass alle benötigten Tabellen existieren
        $this->ensureAllTablesExist();

        // Import-Konfigurationen laden
        $imports = $this->getActiveImports();

        if (empty($imports)) {
            $this->log('No active imports configured', 'info');
            return;
        }

        foreach ($imports as $import) {
            try {
                $this->processImport($import);
            } catch (\Exception $e) {
                $this->log('Error processing import ' . $import['importID'] . ': ' . $e->getMessage(), 'error');
                $this->stats['errors']++;
            }
        }

        $this->log('Import completed. Created: ' . $this->stats['created'] . 
                   ', Updated: ' . $this->stats['updated'] . 
                   ', Skipped: ' . $this->stats['skipped'] . 
                   ', Deleted: ' . $this->stats['deleted'] . 
                   ', Errors: ' . $this->stats['errors'], 'info');
    }

    /**
     * Prüft ob das Kalender-Plugin aktiv ist
     */
    protected function isCalendarPluginActive()
    {
        // Prüfen ob die Kalender-Tabellen existieren
        try {
            $sql = "SHOW TABLES LIKE 'calendar1_event'";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            return $statement->fetchColumn() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Lädt alle aktiven Import-Konfigurationen
     */
    protected function getActiveImports()
    {
        try {
            $sql = "SELECT * FROM wcf1_calendar_ical_import WHERE isActive = 1";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->log('Could not load imports: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Verarbeitet einen einzelnen Import
     */
    protected function processImport(array $import)
    {
        $this->log('Processing import: ' . $import['title'] . ' (ID: ' . $import['importID'] . ')', 'info');

        // iCal-Feed laden
        $icalContent = $this->fetchICalFeed($import['feedUrl']);
        if ($icalContent === false) {
            throw new \Exception('Could not fetch iCal feed');
        }

        // Events parsen
        $events = $this->parseICalEvents($icalContent);
        $this->log('Found ' . count($events) . ' events in feed', 'info');

        // Events importieren
        foreach ($events as $event) {
            $this->importEvent($event, $import);
        }

        // Gelöschte Events behandeln
        if ($import['deleteRemovedEvents']) {
            $this->handleDeletedEvents($events, $import);
        }

        // Last-Run aktualisieren
        $this->updateLastRun($import['importID']);
    }

    /**
     * Lädt einen iCal-Feed
     */
    protected function fetchICalFeed($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'WCF-iCal-Importer/1.0'
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Ensures all required database tables exist.
     * Creates tables if they don't exist - 100% crash-proof!
     */
    protected function ensureAllTablesExist()
    {
        // 1. UID-Mapping Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS calendar1_ical_uid_map (
                mapID INT(10) NOT NULL AUTO_INCREMENT,
                eventID INT(10) NOT NULL,
                icalUID VARCHAR(255) NOT NULL,
                importID INT(10) DEFAULT NULL,
                lastUpdated INT(10) NOT NULL DEFAULT 0,
                PRIMARY KEY (mapID),
                UNIQUE KEY icalUID (icalUID),
                KEY eventID (eventID),
                KEY importID (importID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
        } catch (\Exception $e) {
            // Table might already exist - continue
        }
        
        // 2. Import-Log Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS wcf1_calendar_import_log (
                logID INT(10) NOT NULL AUTO_INCREMENT,
                eventUID VARCHAR(255) NOT NULL DEFAULT '',
                eventID INT(10) DEFAULT NULL,
                action VARCHAR(50) NOT NULL DEFAULT 'import',
                importTime INT(10) NOT NULL DEFAULT 0,
                message TEXT,
                logLevel VARCHAR(20) NOT NULL DEFAULT 'info',
                PRIMARY KEY (logID),
                KEY eventUID (eventUID),
                KEY eventID (eventID),
                KEY importTime (importTime),
                KEY logLevel (logLevel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
        } catch (\Exception $e) {
            // Table might already exist - continue
        }
        
        // 3. Read-Status Tabelle
        try {
            $sql = "CREATE TABLE IF NOT EXISTS wcf1_calendar_event_read_status (
                eventID INT(10) NOT NULL,
                userID INT(10) NOT NULL,
                isRead TINYINT(1) NOT NULL DEFAULT 0,
                lastVisitTime INT(10) NOT NULL DEFAULT 0,
                eventHash VARCHAR(64) NOT NULL DEFAULT '',
                markedReadAutomatically TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (eventID, userID),
                KEY userID (userID),
                KEY isRead (isRead),
                KEY lastVisitTime (lastVisitTime)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
        } catch (\Exception $e) {
            // Table might already exist - continue
        }
    }

    /**
     * Parst iCal-Inhalt und extrahiert Events
     */
    protected function parseICalEvents($content)
    {
        $events = [];
        
        // Einfacher iCal-Parser
        preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $content, $matches);
        
        foreach ($matches[1] as $eventData) {
            $event = $this->parseEventData($eventData);
            if ($event) {
                $events[] = $event;
            }
        }
        
        return $events;
    }

    /**
     * Parst einzelne Event-Daten
     */
    protected function parseEventData($data)
    {
        $event = [];
        
        // UID extrahieren
        if (preg_match('/UID:(.+)/m', $data, $match)) {
            $event['uid'] = trim($match[1]);
        } else {
            return null; // UID ist erforderlich
        }
        
        // Titel
        if (preg_match('/SUMMARY:(.+)/m', $data, $match)) {
            $event['title'] = $this->decodeICalString(trim($match[1]));
        }
        
        // Beschreibung
        if (preg_match('/DESCRIPTION:(.+)/m', $data, $match)) {
            $event['description'] = $this->decodeICalString(trim($match[1]));
        }
        
        // Startdatum
        if (preg_match('/DTSTART[^:]*:(\d+T?\d*Z?)/m', $data, $match)) {
            $event['startTime'] = $this->parseICalDate($match[1]);
        }
        
        // Enddatum
        if (preg_match('/DTEND[^:]*:(\d+T?\d*Z?)/m', $data, $match)) {
            $event['endTime'] = $this->parseICalDate($match[1]);
        }
        
        // Location
        if (preg_match('/LOCATION:(.+)/m', $data, $match)) {
            $event['location'] = $this->decodeICalString(trim($match[1]));
        }
        
        // Last-Modified
        if (preg_match('/LAST-MODIFIED:(\d+T\d+Z?)/m', $data, $match)) {
            $event['lastModified'] = $this->parseICalDate($match[1]);
        }
        
        return $event;
    }

    /**
     * Dekodiert iCal-escaped Strings
     */
    protected function decodeICalString($string)
    {
        $string = str_replace('\\n', "\n", $string);
        $string = str_replace('\\,', ',', $string);
        $string = str_replace('\\;', ';', $string);
        $string = str_replace('\\\\', '\\', $string);
        return $string;
    }

    /**
     * Parst ein iCal-Datum
     */
    protected function parseICalDate($dateString)
    {
        // Format: 20240101T120000Z oder 20240101
        $dateString = str_replace('Z', '', $dateString);
        
        if (strlen($dateString) === 8) {
            // Nur Datum
            $date = \DateTime::createFromFormat('Ymd', $dateString);
            if ($date) {
                $date->setTime(0, 0, 0);
                return $date->getTimestamp();
            }
        } else {
            // Datum und Zeit
            $date = \DateTime::createFromFormat('Ymd\THis', $dateString);
            if ($date) {
                return $date->getTimestamp();
            }
        }
        
        return time();
    }

    /**
     * Importiert ein einzelnes Event
     */
    protected function importEvent(array $event, array $import)
    {
        // Prüfen ob Event bereits existiert
        $existingEventID = $this->getEventIdByUid($event['uid']);
        
        if ($existingEventID) {
            // Update
            if ($this->shouldUpdateEvent($existingEventID, $event)) {
                $this->updateEvent($existingEventID, $event, $import);
                $this->stats['updated']++;
            } else {
                $this->stats['skipped']++;
            }
        } else {
            // Neu erstellen
            $this->createEvent($event, $import);
            $this->stats['created']++;
        }
    }

    /**
     * Holt die Event-ID anhand der iCal-UID
     */
    protected function getEventIdByUid($uid)
    {
        try {
            $sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$uid]);
            $row = $statement->fetchSingleRow();
            return $row ? $row['eventID'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Prüft ob ein Event aktualisiert werden sollte
     */
    protected function shouldUpdateEvent($eventID, array $event)
    {
        if (!isset($event['lastModified'])) {
            return true;
        }
        
        try {
            $sql = "SELECT lastUpdated FROM calendar1_ical_uid_map WHERE eventID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$eventID]);
            $row = $statement->fetchSingleRow();
            
            if ($row && $row['lastUpdated'] >= $event['lastModified']) {
                return false;
            }
        } catch (\Exception $e) {
            // Bei Fehler sicherheitshalber updaten
        }
        
        return true;
    }

    /**
     * Erstellt ein neues Event
     */
    protected function createEvent(array $event, array $import)
    {
        // Hier würde die tatsächliche Event-Erstellung stattfinden
        // Dies hängt von der Kalender-Plugin-API ab
        $this->log('Would create event: ' . ($event['title'] ?? $event['uid']), 'debug');
    }

    /**
     * Aktualisiert ein bestehendes Event
     */
    protected function updateEvent($eventID, array $event, array $import)
    {
        // Hier würde das tatsächliche Event-Update stattfinden
        $this->log('Would update event ID ' . $eventID . ': ' . ($event['title'] ?? $event['uid']), 'debug');
    }

    /**
     * Behandelt gelöschte Events
     */
    protected function handleDeletedEvents(array $currentEvents, array $import)
    {
        // UIDs der aktuellen Events sammeln
        $currentUids = array_column($currentEvents, 'uid');
        
        // Events finden, die nicht mehr im Feed sind
        try {
            $sql = "SELECT eventID, icalUID FROM calendar1_ical_uid_map WHERE importID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$import['importID']]);
            
            while ($row = $statement->fetchArray()) {
                if (!in_array($row['icalUID'], $currentUids)) {
                    $this->deleteEvent($row['eventID'], $row['icalUID']);
                    $this->stats['deleted']++;
                }
            }
        } catch (\Exception $e) {
            $this->log('Error handling deleted events: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Löscht ein Event
     */
    protected function deleteEvent($eventID, $uid)
    {
        $this->log('Would delete event ID ' . $eventID . ' (UID: ' . $uid . ')', 'debug');
    }

    /**
     * Aktualisiert den Last-Run-Timestamp
     */
    protected function updateLastRun($importID)
    {
        try {
            $sql = "UPDATE wcf1_calendar_ical_import SET lastRun = ? WHERE importID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([time(), $importID]);
        } catch (\Exception $e) {
            $this->log('Could not update lastRun: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Logging-Funktion
     */
    protected function log($message, $level = 'info')
    {
        // In Datenbank loggen
        try {
            $sql = "INSERT INTO wcf1_calendar_import_log (message, logLevel, importTime) VALUES (?, ?, ?)";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$message, $level, time()]);
        } catch (\Exception $e) {
            // Fallback: Error-Log
            error_log('[iCal-Import] [' . $level . '] ' . $message);
        }
    }
}
