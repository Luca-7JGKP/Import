-- Datenbank-Schema für Kalender Import Plugin
-- Version: 1.2.1
-- Autor: Luca Berwind
-- Kompatibel mit: com.woltlab.calendar 6.1.x

-- Tabelle für Tracking von Gelesen/Ungelesen-Status bei Kalender-Events
CREATE TABLE IF NOT EXISTS wcf1_calendar_event_read_status (
    eventID INT(10) NOT NULL,
    userID INT(10) NOT NULL,
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    lastVisitTime INT(10) NOT NULL DEFAULT 0,
    eventHash VARCHAR(64) NOT NULL DEFAULT '',
    markedReadAutomatically TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (eventID, userID),
    KEY userID (userID),
    KEY isRead (isRead),
    KEY lastVisitTime (lastVisitTime),
    KEY eventHash (eventHash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für Import-Log
CREATE TABLE IF NOT EXISTS wcf1_calendar_import_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für UID-Mapping zur Event-Deduplizierung
-- Verwendet calendar1_ Präfix um mit der existierenden WoltLab-Struktur konsistent zu sein
CREATE TABLE IF NOT EXISTS calendar1_ical_uid_map (
    mapID INT(10) NOT NULL AUTO_INCREMENT,
    eventID INT(10) NOT NULL,
    icalUID VARCHAR(255) NOT NULL,
    importID INT(10) DEFAULT NULL,
    lastUpdated INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (mapID),
    UNIQUE KEY icalUID (icalUID),
    KEY eventID (eventID),
    KEY importID (importID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;