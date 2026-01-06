# Kalender iCal Import Plugin für WoltLab Suite 6.1

**Version:** 4.0.0  
**Autor:** Luca Berwind  
**Paket:** `com.lucaberwind.wcf.calendar.import`

## 📋 Übersicht

Dieses Plugin importiert Kalender-Events aus ICS-Dateien (iCal-Format) in den WoltLab-Kalender **vollautomatisch ohne manuelle Konfiguration**. Alle Einstellungen werden automatisch aus der `calendar1_event_import` Tabelle gelesen.

## 🎯 Version 4.0 - Vollautomatisch!

### Was ist neu in v4.0?

✅ **Keine manuelle Konfiguration mehr nötig** - Alle Einstellungen aus `calendar1_event_import`  
✅ **Automatische Kategorie-Erkennung** - Mit intelligenten Fallbacks  
✅ **Perfekte UID-Mappings** - Keine Duplikate mehr (63 Events = 63 Mappings!)  
✅ **categoryID nie NULL** - Events werden immer korrekt angezeigt  
✅ **Alle Teilnahme-Einstellungen** - Automatisch bei jedem Event gesetzt

### Behobene Probleme aus v3.0:

❌ **categoryID war LEER** → ✅ Jetzt immer gesetzt mit Fallbacks  
❌ **Nur 1 UID-Mapping für 63 Events** → ✅ Jetzt für JEDES Event ein Mapping  
❌ **945 Events statt 63** → ✅ Keine Duplikate mehr durch korrektes Mapping  
❌ **Manuelle Konfiguration nötig** → ✅ Vollautomatisch aus Datenbank

## ✨ Hauptfunktionen

### 🚀 Vollautomatische Konfiguration (v4.0)

Das Plugin liest **ALLE** Konfiguration automatisch aus der `calendar1_event_import` Tabelle:

```sql
SELECT importID, url, categoryID, userID, isDisabled, lastRun 
FROM calendar1_event_import 
WHERE isDisabled = 0;
```

**Was wird automatisch geladen:**
- ✅ **url** - ICS-URL zum Importieren
- ✅ **categoryID** - Ziel-Kategorie für Events (mit Fallback!)
- ✅ **userID** - Event-Ersteller (Fallback: User ID 1)
- ✅ **importID** - Wird für UID-Mappings verwendet

**Fallback-Logik für categoryID:**
1. `categoryID` aus `calendar1_event_import` (wenn gesetzt)
2. Erste verfügbare Kalender-Kategorie aus `wcf1_category`
3. Absoluter Fallback: `1`

**Fallback-Logik für userID:**
1. `userID` aus `calendar1_event_import` (wenn gesetzt)
2. Fallback: User ID `1`

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

### 🔄 Intelligente Duplikat-Erkennung (v4.0 verbessert!)

**Jedes Event** bekommt ein UID-Mapping - keine Duplikate mehr!

```php
// Für jedes Event aus der ICS:
$uid = $event['uid'];

// Prüfe ob UID schon existiert
$existingEventID = SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = $uid;

if ($existingEventID) {
    // UPDATE - Event aktualisieren
    UPDATE calendar1_event SET subject=?, message=?, time=TIME_NOW, ...;
    UPDATE calendar1_event_date SET startTime=?, endTime=?, ...;
    UPDATE calendar1_ical_uid_map SET lastUpdated = TIME_NOW;
} else {
    // INSERT - Neues Event erstellen
    INSERT INTO calendar1_event (...) VALUES (...);
    INSERT INTO calendar1_event_date (...) VALUES (...);
    INSERT INTO calendar1_ical_uid_map (eventID, icalUID, importID, lastUpdated) VALUES (...);
}
```

**Ergebnis:** 63 Events in ICS → 63 UID-Mappings → Keine Duplikate!

### 📥 Import-Funktionen
- **Vollautomatischer ICS-Import** von externen URLs
- **Keine wcf1_option Konfiguration nötig!** (v4.0)
- **Automatischer Event-Ersteller** aus calendar1_event_import.userID
- **Automatische Kategorie** aus calendar1_event_import.categoryID
- **Automatischer Import** via Cronjob (alle 30 Minuten)
- **Manueller Import** möglich (falls gewünscht)
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

### Schnellstart (v4.0)

**Das Plugin ist jetzt vollautomatisch!** Keine ACP-Optionen mehr nötig.

#### 1. Import in Datenbank anlegen

Füge einen Eintrag in die `calendar1_event_import` Tabelle ein:

```sql
INSERT INTO calendar1_event_import (url, categoryID, userID, isDisabled, lastRun)
VALUES (
    'http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics',
    1,    -- Deine Kalender-Kategorie-ID (oder NULL für automatisch)
    1,    -- Deine User-ID (oder NULL für User ID 1)
    0,    -- 0 = aktiv, 1 = deaktiviert
    0     -- Wird beim ersten Import gesetzt
);
```

#### 2. Fertig!

Der Cronjob läuft automatisch alle 30 Minuten und:
- ✅ Holt die URL aus `calendar1_event_import`
- ✅ Nutzt `categoryID` (oder findet automatisch eine)
- ✅ Nutzt `userID` (oder User ID 1)
- ✅ Importiert alle Events mit korrekten UID-Mappings
- ✅ Aktualisiert bestehende Events ohne Duplikate

### Manuelle Ausführung (optional)

Falls du den Import sofort ausführen möchtest:

```php
require_once('lib/system/cronjob/ICalImportCronjob.class.php');
$cronjob = new \wcf\system\cronjob\ICalImportCronjob();
$cronjob->runManually();
```

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

### Test-URL (Mainz 05 Spielplan - 63 Events)
```
http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics
```

### Test-Ablauf (v4.0)

1. **Import in Datenbank anlegen:**
```sql
INSERT INTO calendar1_event_import (url, categoryID, userID, isDisabled)
VALUES (
    'http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics',
    1, 1, 0
);
```

2. **Cronjob läuft automatisch** (oder manuell ausführen)

3. **Ergebnis prüfen:**
   - ✅ **63 Events** sollten im Kalender erscheinen
   - ✅ **63 UID-Mappings** in `calendar1_ical_uid_map` Tabelle
   - ✅ Teilnahme-Button bei jedem Event sichtbar
   - ✅ Alle Events haben `categoryID` gesetzt (nicht NULL!)
   - ✅ Neue Events sind ungelesen (time = TIME_NOW)

4. **Duplikat-Test:**
   - Import erneut ausführen (manuell oder warten auf Cronjob)
   - ✅ **Keine Duplikate!** Events werden aktualisiert, nicht neu erstellt
   - ✅ Events werden **ungelesen** (time = TIME_NOW bei Update)
   - ✅ Immer noch nur **63 Events** und **63 UID-Mappings**

### Prüfung der UID-Mappings

```sql
-- Sollte 63 Zeilen zurückgeben (für Mainz 05 Spielplan)
SELECT COUNT(*) FROM calendar1_ical_uid_map;

-- Zeige alle Mappings
SELECT m.mapID, m.eventID, m.icalUID, e.subject 
FROM calendar1_ical_uid_map m
JOIN calendar1_event e ON m.eventID = e.eventID
ORDER BY m.mapID;
```

## 🐛 Troubleshooting

### Problem: "Keine Import-Konfiguration gefunden"

**Ursache:** Keine aktive Konfiguration in `calendar1_event_import` Tabelle

**Lösung:**
```sql
-- Prüfe vorhandene Imports
SELECT * FROM calendar1_event_import;

-- Erstelle einen neuen Import (falls keiner existiert)
INSERT INTO calendar1_event_import (url, categoryID, userID, isDisabled)
VALUES ('https://deine-ics-url.ics', 1, 1, 0);

-- Oder aktiviere einen deaktivierten Import
UPDATE calendar1_event_import SET isDisabled = 0 WHERE importID = 1;
```

### Problem: "Keine gültige Kategorie gefunden"

**Ursache:** categoryID ist NULL und keine Kalender-Kategorie gefunden

**Lösung:**
```sql
-- Finde verfügbare Kalender-Kategorien
SELECT c.categoryID, c.title 
FROM wcf1_category c
JOIN wcf1_object_type ot ON c.objectTypeID = ot.objectTypeID
WHERE ot.objectType = 'com.woltlab.calendar.category';

-- Setze categoryID in Import-Konfiguration
UPDATE calendar1_event_import SET categoryID = 1 WHERE importID = 1;
```

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

### Problem: categoryID ist NULL in Events

**Ursache (v3.0 Problem, in v4.0 behoben):** Alte Version hat categoryID nicht korrekt gesetzt

**Lösung in v4.0:**
- ✅ Automatisch behoben! v4.0 setzt categoryID IMMER
- ✅ Fallback-Logik verhindert NULL-Werte
- Bei Updates werden Events automatisch korrigiert

```sql
-- Prüfe Events ohne categoryID (sollte in v4.0 nicht passieren)
SELECT COUNT(*) FROM calendar1_event WHERE categoryID IS NULL;

-- Falls doch vorhanden (von alter Version), manuell fixen:
UPDATE calendar1_event 
SET categoryID = 1 
WHERE categoryID IS NULL OR categoryID = 0;
```

## 📝 Changelog

### Version 4.0.0 (2026-01-06) 🎯 FINALE AUTOMATISCHE VERSION

**🚀 Komplett überarbeitet - Vollautomatisch!**

#### ✅ Behobene Probleme aus v3.0:
- ❌ **categoryID war LEER** → ✅ **Jetzt immer gesetzt** mit intelligenten Fallbacks
- ❌ **Nur 1 UID-Mapping für 63 Events** → ✅ **Jetzt für JEDES Event** ein Mapping
- ❌ **945 Events statt 63** → ✅ **Keine Duplikate mehr** durch korrektes Mapping
- ❌ **Manuelle Konfiguration nötig** → ✅ **Vollautomatisch** aus Datenbank

#### 🎯 Neue Features:
- ✅ **Vollautomatische Konfiguration** aus `calendar1_event_import` Tabelle
- ✅ **Keine wcf1_option mehr nötig** - Alles aus Datenbank
- ✅ **Intelligente categoryID-Fallbacks:**
  1. Aus `calendar1_event_import.categoryID`
  2. Erste Kalender-Kategorie
  3. Absoluter Fallback: 1
- ✅ **UID-Mapping für ALLE Events** - Keine Duplikate!
- ✅ **categoryID NIEMALS NULL** - Events immer sichtbar
- ✅ **time = TIME_NOW bei Updates** - Events werden ungelesen
- ✅ **Alle Teilnahme-Einstellungen** automatisch gesetzt

#### 🔧 Technische Verbesserungen:
- Entfernt: Abhängigkeit von `wcf1_option` Konstanten
- Entfernt: `getOption()` Methode
- Entfernt: `loadEventUser()` Methode (ersetzt durch `loadEventUserById()`)
- Hinzugefügt: `getDefaultCategoryID()` mit Fallback-Logik
- Verbessert: `createEvent()` und `updateEvent()` mit categoryID-Prüfung
- Verbessert: Logging mit "v4.0" Prefix

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
