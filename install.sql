-- Datenbank-Schema für Luca Woltlab Import Plugin
-- Version: 1.1.0
-- Autor: Luca Berwind

-- Tabelle für Tracking von Gelesen/Ungelesen-Status bei Kalender-Events
-- Custom-Tracking mit Unterstützung für:
-- - Automatisches Markieren vergangener Events als gelesen
-- - Markieren aktualisierter Events als ungelesen
-- - Event-Hash zur Änderungserkennung
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
    KEY eventID (eventID),
    KEY eventHash (eventHash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alte Tabelle für Kompatibilität (falls vorhanden)
CREATE TABLE IF NOT EXISTS wcf1_calendar_event_visit (
    eventID INT(10) NOT NULL,
    userID INT(10) NOT NULL,
    lastVisitTime INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (eventID, userID),
    KEY userID (userID),
    KEY lastVisitTime (lastVisitTime),
    KEY eventID (eventID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stelle sicher, dass die wcf1_cronjob Tabelle existiert und alle Felder hat
CREATE TABLE IF NOT EXISTS wcf1_cronjob (
    cronjobID INT(10) NOT NULL AUTO_INCREMENT,
    cronjobClassName VARCHAR(255) NOT NULL,
    packageID INT(10) NOT NULL,
    cronjobName VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT,
    startMinute VARCHAR(255) NOT NULL DEFAULT '*',
    startHour VARCHAR(255) NOT NULL DEFAULT '*',
    startDom VARCHAR(255) NOT NULL DEFAULT '*',
    startMonth VARCHAR(255) NOT NULL DEFAULT '*',
    startDow VARCHAR(255) NOT NULL DEFAULT '*',
    lastExec INT(10) NOT NULL DEFAULT 0,
    nextExec INT(10) NOT NULL DEFAULT 0,
    afterNextExec INT(10) NOT NULL DEFAULT 0,
    isDisabled TINYINT(1) NOT NULL DEFAULT 0,
    canBeEdited TINYINT(1) NOT NULL DEFAULT 1,
    canBeDisabled TINYINT(1) NOT NULL DEFAULT 1,
    state TINYINT(1) NOT NULL DEFAULT 0,
    failCount TINYINT(3) NOT NULL DEFAULT 0,
    PRIMARY KEY (cronjobID),
    UNIQUE KEY cronjobClassName (cronjobClassName, packageID),
    KEY packageID (packageID),
    KEY nextExec (nextExec),
    KEY state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für Import-Log (optional, für Debugging)
CREATE TABLE IF NOT EXISTS wcf1_calendar_import_log (
    logID INT(10) NOT NULL AUTO_INCREMENT,
    eventUID VARCHAR(255) NOT NULL,
    eventID INT(10) DEFAULT NULL,
    importTime INT(10) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'success',
    message TEXT,
    userID INT(10) DEFAULT NULL,
    PRIMARY KEY (logID),
    KEY eventUID (eventUID),
    KEY importTime (importTime),
    KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
