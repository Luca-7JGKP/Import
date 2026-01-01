# Kalender iCal Import Plugin für WoltLab Suite 6.1

**Version:** 3.0.0  
**Autor:** Luca Berwind  
**Paket:** `com.lucaberwind.wcf.calendar.import`

## 📋 Übersicht

Dieses Plugin importiert Kalender-Events aus ICS-Dateien (iCal-Format) in den WoltLab-Kalender und bietet erweiterte Funktionen für Gelesen/Ungelesen-Status, automatische Teilnahme-Funktionen und intelligente Duplikat-Erkennung.

## ✨ Hauptfunktionen

### 🎯 Gelesen/Ungelesen Logik
- **Neue Events:** Automatisch als **ungelesen** für alle Benutzer markiert
- **Aktualisierte Events:** Werden wieder **ungelesen** für alle Benutzer (durch Aktualisierung des `time`-Feldes)
- **Vergangene Events:** Werden automatisch als **gelesen** markiert (via `wcf1_tracked_visit` Tabelle)

### 👥 Teilnahme-Funktionen (Automatisch aktiviert)
Alle importierten Events haben folgende Teilnahme-Einstellungen:
- ✅ **Teilnahme aktiviert** (`enableParticipation = 1`)
- ✅ **Öffentlich sichtbar** (`participationIsPublic = 1`)
- ✅ **99 Begleitpersonen** möglich (`maxCompanions = 99`)
- ✅ **Änderbar** (`participationIsChangeable = 1`)
- ✅ **Unbegrenzte Teilnehmer** (`maxParticipants = 0`)
- ✅ **Anmeldeschluss bei Event-Start** (`participationEndTime = Event-Startzeit`)
- ✅ **Jeder kann teilnehmen** (`inviteOnly = 0`)

### 🔄 Intelligente Duplikat-Erkennung
- Verwendet die iCal **UID** für eindeutige Identifikation
- Speichert Mapping in Tabelle `calendar1_ical_uid_map`
- Bei Änderungen wird der **bestehende Termin aktualisiert** (nicht neu erstellt)
- Termine "wachsen mit" bei Datum/Zeit/Ort-Änderungen

### 📥 Import-Funktionen
- **ICS-Import** von externen URLs
- **Konfigurierbarer Event-Ersteller** (User-ID)
- **Konfigurierbare Kategorie** (Category-ID)
- **Automatischer Import** via Cronjob (alle 30 Minuten)
- **Manueller Import** via Button im ACP
- **Import-Log** in Datenbank (`wcf1_calendar_import_log`)

## 🚀 Installation

### Voraussetzungen
- WoltLab Suite **6.1.0** oder höher
- WoltLab Calendar **6.1.0** oder höher
- PHP 7.4 oder höher
- MySQL/MariaDB Datenbank

### Installationsschritte

1. **Plugin-Paket herunterladen**
   ```bash
   # Paket erstellen (falls noch nicht vorhanden)
   tar -czf com.lucaberwind.wcf.calendar.import.tar.gz *
   ```

2. **Installation über ACP**
   - Im ACP zu **Konfiguration → Pakete → Paket installieren** navigieren
   - Paket hochladen und installieren
   - Installationsvorgang abwarten

3. **Datenbank-Tabellen werden automatisch erstellt:**
   - `calendar1_ical_uid_map` - UID-Mapping für Duplikat-Erkennung
   - `wcf1_calendar_import_log` - Import-Protokoll
   - `wcf1_calendar_event_read_status` - Gelesen/Ungelesen-Status (optional)

## ⚙️ Konfiguration

### ACP-Einstellungen

Nach der Installation findest du die Einstellungen unter:  
**ACP → Optionen → Kalender-Import**

#### 📡 ICS-Import Einstellungen

| Option | Beschreibung | Beispiel |
|--------|--------------|----------|
| **ICS-URL** | URL zur ICS-Datei | `http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics` |
| **Ziel-Import-ID** | ID aus `calendar1_event_import` Tabelle | `1` (oder leer lassen) |
| **Kategorie-ID** | Überschreibt categoryID aus Import | `0` = aus Import verwenden |
| **Event-Ersteller (User-ID)** | Benutzer-ID für importierte Events | `1` (Standard: Admin) |

#### 📊 Tracking-Einstellungen

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| **Vergangene Events als gelesen markieren** | ✅ Aktiv | Markiert automatisch Events in der Vergangenheit als gelesen |
| **Aktualisierte Events als ungelesen markieren** | ✅ Aktiv | Setzt `time` auf NOW bei Updates → wird ungelesen |

#### 🔧 Erweiterte Einstellungen

| Option | Standard | Beschreibung |
|--------|----------|--------------|
| **Maximale Events** | 100 | Max. Events pro Import (1-10000) |
| **Log-Level** | Info | `error`, `warning`, `info`, `debug` |
| **Forum-ID für Threads** | 0 | Forum für Event-Threads (0 = deaktiviert) |
| **Threads erstellen** | ✅ | Thread für jedes Event erstellen |
| **Zeitzone konvertieren** | ✅ | ICS-Zeiten zu Server-Zeitzone konvertieren |

### Manueller Import

**Button im ACP:** "Import jetzt ausführen"  
Führt sofort einen Import aus, ohne auf den Cronjob zu warten.

## 🔄 Cronjobs

Das Plugin installiert automatisch 3 Cronjobs:

### 1. ICS-Import Cronjob
- **Klasse:** `wcf\system\cronjob\ICalImportCronjob`
- **Intervall:** Alle 30 Minuten (0,30)
- **Funktion:** Importiert Events aus der konfigurierten ICS-URL
- **Kann bearbeitet/deaktiviert werden:** ✅

### 2. Timezone-Fix Cronjob
- **Klasse:** `wcf\system\cronjob\FixTimezoneCronjob`
- **Intervall:** Alle 30 Minuten (5,35)
- **Funktion:** Korrigiert Timezone-Offsets nach Import
- **Kann bearbeitet/deaktiviert werden:** ✅

### 3. Vergangene Events als gelesen markieren
- **Klasse:** `wcf\system\cronjob\MarkPastEventsReadCronjob`
- **Intervall:** Alle 30 Minuten (10,40)
- **Funktion:** Markiert abgelaufene Events automatisch als gelesen
- **Kann bearbeitet/deaktiviert werden:** ✅

## 📊 Datenbank-Struktur

### calendar1_ical_uid_map
Mapping von iCal-UID zu WoltLab-Event-ID (existiert bereits in WoltLab Calendar):

```sql
CREATE TABLE calendar1_ical_uid_map (
    mapID INT(10) NOT NULL AUTO_INCREMENT,
    eventID INT(10) NOT NULL,
    icalUID VARCHAR(255) NOT NULL,
    importID INT(10) DEFAULT NULL,
    lastUpdated INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (mapID),
    UNIQUE KEY icalUID (icalUID),
    KEY eventID (eventID)
);
```

### wcf1_calendar_import_log
Import-Protokoll für Debugging:

```sql
CREATE TABLE wcf1_calendar_import_log (
    logID INT(10) NOT NULL AUTO_INCREMENT,
    eventUID VARCHAR(255) NOT NULL DEFAULT '',
    eventID INT(10) DEFAULT NULL,
    action VARCHAR(50) NOT NULL DEFAULT 'import',
    importTime INT(10) NOT NULL DEFAULT 0,
    message TEXT,
    logLevel VARCHAR(20) NOT NULL DEFAULT 'info',
    PRIMARY KEY (logID),
    KEY eventUID (eventUID)
);
```

## 🧪 Test-Szenario

### Test-URL (Mainz 05 Spielplan)
```
http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics
```

### Test-Ablauf

1. **ICS-URL in den ACP-Einstellungen eingeben**
2. **"Import jetzt ausführen" klicken**
3. **Ergebnis prüfen:**
   - Events sollten im Kalender erscheinen
   - Teilnahme-Button sollte bei jedem Event sichtbar sein
   - Neue Events sind ungelesen (rot markiert)
   - Vergangene Events sind gelesen

4. **Duplikat-Test:**
   - Import erneut ausführen
   - Events sollten **nicht doppelt** erstellt werden
   - Bestehende Events sollten aktualisiert werden

## 🐛 Troubleshooting

### Problem: Events werden doppelt importiert

**Lösung:**
- Prüfe, ob `calendar1_ical_uid_map` Tabelle existiert:
  ```sql
  SHOW TABLES LIKE 'calendar1_ical_uid_map';
  ```
- Falls nicht vorhanden, wird sie beim nächsten Import automatisch erstellt
- Überprüfe, ob UIDs in der ICS-Datei vorhanden sind

### Problem: Teilnahme-Button wird nicht angezeigt

**Lösung:**
- Prüfe, ob die Spalten in `calendar1_event` existieren:
  ```sql
  SHOW COLUMNS FROM calendar1_event LIKE 'enableParticipation';
  ```
- Falls WoltLab Calendar älter als 6.1 ist, müssen Spalten manuell hinzugefügt werden
- Führe Import erneut aus, um Einstellungen zu setzen

### Problem: Events bleiben ungelesen

**Lösung:**
- Prüfe Cronjob "MarkPastEventsReadCronjob" im ACP
- Stelle sicher, dass er aktiviert ist
- Option "Vergangene Events als gelesen markieren" aktivieren
- Manuell Cronjob ausführen: **ACP → System → Cronjobs**

### Problem: ICS-URL nicht erreichbar

**Lösung:**
- Prüfe URL im Browser
- Stelle sicher, dass der Server die URL erreichen kann (Firewall)
- Bei HTTPS-Problemen: SSL-Zertifikate prüfen
- Log-Level auf "debug" setzen für detaillierte Fehler

### Problem: Events haben falsche Zeitzone

**Lösung:**
- Option "Zeitzone konvertieren" aktivieren
- Cronjob "FixTimezoneCronjob" aktivieren
- Server-Zeitzone in PHP prüfen: `php -i | grep timezone`

### Problem: ACP-Einstellungen werden nicht gespeichert

**Lösung:**
- Prüfe, ob Optionen in `wcf1_option` Tabelle existieren:
  ```sql
  SELECT optionName, optionValue FROM wcf1_option 
  WHERE optionName LIKE 'calendar_import%';
  ```
- Cache leeren: **ACP → Wartung → Cache leeren**
- Browser-Cache leeren (Strg+F5)

## 📝 Changelog

### Version 3.0.0 (2026-01-01)
- ✅ **Komplett neue Implementierung** für WoltLab Suite 6.1
- ✅ **Automatische Teilnahme-Funktionen** bei allen Events
- ✅ **Verbesserte Duplikat-Erkennung** via UID-Mapping
- ✅ **Zuverlässiges Speichern** der ACP-Einstellungen
- ✅ **Vollständige Gelesen/Ungelesen-Logik**
- ✅ **Konfigurierbarer Event-Ersteller** (User-ID)
- ✅ **Umfassende Dokumentation**

### Version 2.0.0
- Import-Funktionen
- Basis Gelesen/Ungelesen-Logik
- Cronjobs

## 📄 Lizenz

Dieses Plugin ist proprietäre Software von Luca Berwind.

## 🆘 Support

Bei Fragen oder Problemen:
- GitHub Issues: [Repository-URL]
- E-Mail: [Support-E-Mail]

## 🙏 Credits

- **Entwickler:** Luca Berwind
- **Für:** WoltLab Suite 6.1 & WoltLab Calendar 6.1
- **Test-URL:** Mainz 05 Spielplan (i.cal.to)

---

**Viel Erfolg mit dem Plugin! 🚀**
