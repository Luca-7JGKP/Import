-- Deinstallations-Script für Luca Woltlab Import Plugin
-- Vorsicht: Löscht alle Plugin-Daten!

-- Lösche Read-Status-Tabelle
DROP TABLE IF EXISTS wcf1_calendar_event_read_status;

-- Lösche Import-Log-Tabelle
DROP TABLE IF EXISTS wcf1_calendar_import_log;

-- Hinweis: wcf1_cronjob wird NICHT gelöscht, da sie von anderen Plugins verwendet werden könnte
