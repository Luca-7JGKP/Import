-- Deinstallations-Script für Luca Woltlab Import Plugin
-- Vorsicht: Löscht alle Plugin-Daten!

-- Lösche Event-Visit-Tracking-Tabelle
DROP TABLE IF EXISTS wcf1_calendar_event_visit;

-- Lösche Import-Log-Tabelle
DROP TABLE IF EXISTS wcf1_calendar_import_log;

-- Entferne threadID-Spalte von calendar_event (optional, nur wenn gewünscht)
-- ALTER TABLE wcf1_calendar_event DROP COLUMN IF EXISTS threadID;

-- Hinweis: wcf1_cronjob wird NICHT gelöscht, da sie von anderen Plugins verwendet werden könnte
