# 📅 Kalender iCal Import Plugin v4.3.4

**Automatischer ICS-Import für WoltLab Suite 6.1**

| | |
|--|--|
| **Version** | 4.3.4 |
| **Autor** | Luca Berwind |
| **Paket** | `com.lucaberwind.wcf.calendar.import` |
| **Kompatibilität** | WoltLab Suite 6.1+ / Calendar 6.1+ |

---

## 🎯 Was macht dieses Plugin?

Importiert **automatisch** Kalender-Events aus ICS-Dateien (z.B. Mainz 05 Spielplan) in deinen WoltLab-Kalender.

**Keine manuelle Konfiguration nötig!**

---

## ✨ Features

| Feature | Beschreibung |
|---------|--------------|
| 🚀 **Vollautomatisch** | Keine ACP-Konfiguration nötig |
| 🔄 **Intelligente Deduplication** | Verhindert Duplikate durch UID-Mapping + Property-basierte Erkennung |
| 🔁 **Event Updates** | Aktualisiert existierende Events (auch abgelaufene) statt neue zu erstellen |
| 🎯 **Auto-Migration** | Findet und verknüpft Events ohne UID-Mapping automatisch |
| 📝 **Event-Threads** | Automatisch Forum-Threads via WoltLab API erstellen |
| 🏷️ **Titel-Fallback** | Events erhalten immer einen Titel (Summary → Location → Description → UID) |
| 👥 **Teilnahme** | 99 Begleiter, öffentlich, änderbar |
| 🔔 **Gelesen/Ungelesen** | Neue Events = ungelesen |
| ⏰ **Cronjob** | Alle 30 Minuten automatischer Import |
| 🌍 **Konfigurierbare Timezone** | Unterstützt alle PHP-Timezones (default: Europe/Berlin) |
| 🔒 **SQL Injection Schutz** | Alle Queries nutzen parameterized statements |
| 📊 **Enhanced Logging** | Strukturiertes Logging mit Context-Daten |
| 🛡️ **WoltLab API Integration** | Nutzt CalendarEventAction mit SQL-Fallback |

---

## 📦 Installation

### 1. Plugin bauen
```bash
git clone https://github.com/Luca-7JGKP/Import.git
cd Import
bash build.sh
```

**Erzeugt:** `com.lucaberwind.wcf.calendar.import.tar`

### 2. Plugin installieren
1. **ACP → Pakete → Paket installieren**
2. Datei `com.lucaberwind.wcf.calendar.import.tar` hochladen
3. **Installieren** klicken
4. **Cache leeren** (ACP → Übersicht → Cache)

---

## ⚙️ Konfiguration (optional)

### Timezone konfigurieren

Standardmäßig wird `Europe/Berlin` verwendet. Um eine andere Timezone zu nutzen:

**In `config.inc.php` einfügen:**
```php
// Timezone für Calendar Import
define('CALENDAR_IMPORT_TIMEZONE', 'America/New_York');
```

**Unterstützte Timezones:** Alle PHP-Timezones (siehe [PHP Timezones](https://www.php.net/manual/en/timezones.php))

### Log Level konfigurieren

Standard ist `info`. Für mehr Details:

**In `config.inc.php` einfügen:**
```php
// Log Level: error, warning, info, debug
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

**Log Levels:**
- `error`: Nur kritische Fehler
- `warning`: Fehler + Warnungen (z.B. API Fallback)
- `info`: Standard-Level mit Import-Statistiken
- `debug`: Detaillierte Debug-Ausgaben für jeden Event

### Anmeldeschluss konfigurieren (optional)

Standardmäßig schließt die Anmeldung genau zum Event-Start. Um die Anmeldung früher zu schließen:

**In `config.inc.php` einfügen:**
```php
// Anmeldeschluss X Stunden vor Event-Start
define('CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE', 24); // 24 Stunden vor Event
```

**Beispiele:**
- `24`: Anmeldung schließt 24 Stunden vor Event-Start
- `48`: Anmeldung schließt 48 Stunden vor Event-Start
- `0` oder nicht definiert: Anmeldung schließt zum Event-Start (Standard)

**Hinweise:**
- Wert muss zwischen 1 und 168 (1 Woche) liegen
- Wenn der berechnete Anmeldeschluss in der Vergangenheit liegt, wird automatisch der Event-Start verwendet
- Bei ungültigen Werten wird der Standard verwendet

---

## ⚙️ Einrichtung (einmalig)

### Schritt 1: Import erstellen

**ACP → Kalender → Import → Import hinzufügen**

| Feld | Wert |
|------|------|
| **Titel** | z.B. "Mainz 05 Spielplan" |
| **URL** | `http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics` |
| **Kategorie** | Deine Kalender-Kategorie wählen |

**Speichern** - Fertig! ✅

> **Hinweis:** WoltLab's Standard-Import-Cronjob wird automatisch deaktiviert. 
> Unser erweiterter Cronjob "ICS Import (erweitert)" übernimmt den Import.

### Schritt 2: Event-Threads aktivieren (optional)

**ACP → Kalender → Einstellungen → Event-Thread**

1. **Board** auswählen
2. **Kategorien** aktivieren
3. **Speichern**

---

## 🔄 So funktioniert es

```
┌─────────────────────────────────────────────────┐
│  1. Cronjob läuft alle 30 Minuten               │
└─────────────────┬───────────────────────────────┘
                  ▼
┌─────────────────────────────────────────────────┐
│  2. ICS-Datei von URL herunterladen             │
│     (z.B. 63 Events von Mainz 05)               │
└─────────────────┬───────────────────────────────┘
                  ▼
┌─────────────────────────────────────────────────┐
│  3. Für jedes Event prüfen:                     │
│     - Titel vorhanden? → Fallback anwenden      │
│     - UID existiert schon? → Update             │
│     - UID neu? → Neues Event erstellen          │
└─────────────────┬───────────────────────────────┘
                  ▼
┌─────────────────────────────────────────────────┐
│  4. WoltLab API wird genutzt                    │
│     → Event-Thread wird automatisch erstellt    │
│     → Suchindex wird aktualisiert               │
│     → Aktivitäten werden geloggt                │
│     → Zeitzonen korrekt behandelt               │
└─────────────────────────────────────────────────┘
```

### 🏷️ Event-Titel-Fallback

Das Plugin stellt sicher, dass **jedes Event einen Titel** hat:

1. **SUMMARY** vorhanden → Verwendet als Titel ✅
2. **SUMMARY leer** → Verwendet **LOCATION** als Titel
3. **LOCATION leer** → Verwendet ersten Teil der **DESCRIPTION**
4. **Alles leer** → Verwendet **UID** als Basis ("Event xyz...")

**Beispiel:**
```
ICS Event ohne SUMMARY:
  LOCATION: Mewa Arena
  → Titel: "Event: Mewa Arena" ✅
```

---

## 📋 Teilnahme-Einstellungen

Alle importierten Events haben automatisch:

| Einstellung | Wert |
|-------------|------|
| Teilnahme aktiviert | ✅ Ja |
| Öffentlich sichtbar | ✅ Ja |
| Begleitpersonen | 99 |
| Änderbar | ✅ Ja |
| Max. Teilnehmer | Unbegrenzt |
| Anmeldeschluss | Event-Start |

---

## 🔔 Gelesen/Ungelesen Logik

| Situation | Ergebnis |
|-----------|----------|
| **Neues Event importiert** | 🔴 Ungelesen für alle |
| **Event aktualisiert** | 🔴 Ungelesen für alle |
| **User öffnet Event** | ✅ Gelesen für diesen User |
| **Event in Vergangenheit** | ✅ Automatisch gelesen |

---

## 🐛 Troubleshooting

### Events werden nicht importiert

**Prüfen:**
```sql
SELECT * FROM calendar1_event_import WHERE isDisabled = 0;
```

**Lösung:** Import in ACP erstellen oder `isDisabled = 0` setzen.

### Keine Forum-Threads erstellt

**Prüfen:**
1. ACP → Kalender → Einstellungen → Event-Thread
2. Board-ID muss gesetzt sein (nicht 0)
3. Kategorie muss aktiviert sein

**Hinweis:** Das Plugin nutzt die offizielle WoltLab API (`CalendarEventAction`), 
die automatisch Event-Threads erstellt, wenn die Kalender-Einstellungen korrekt sind.

### Events haben keinen Titel

**Lösung:** Ab v4.1.1 ist der Titel-Fallback aktiv. Events erhalten automatisch:
- Den SUMMARY-Wert (Standard)
- Oder "Event: [LOCATION]" falls SUMMARY leer
- Oder die ersten 50 Zeichen der DESCRIPTION
- Oder "Event [UID]" als letzten Ausweg

### Duplikate vorhanden

**Lösung (ab v4.3.0):** Das System erkennt jetzt automatisch existierende Events auch ohne UID-Mapping.
Bei der nächsten Ausführung werden diese automatisch verknüpft.

**Tiefgehende Diagnose (ab v4.3.4):** 
Nutze die neuen Deep Debugging Features:
```php
// In config.inc.php
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

Siehe **[DEBUGGING_GUIDE_V434.md](DEBUGGING_GUIDE_V434.md)** für:
- Session-Tracking und Log-Analyse
- Strategie-Verfolgung (UID → Property → Fuzzy Matching)
- Diagnostische SQL-Queries
- Schritt-für-Schritt Troubleshooting-Workflow
- Häufige Duplikat-Szenarien und ihre Lösungen

**Manuell aufräumen (nur bei alten Duplikaten nötig):**
```sql
-- Zeige Events ohne Mapping (sollten automatisch verknüpft werden)
SELECT e.eventID, e.subject, ed.startTime 
FROM calendar1_event e
JOIN calendar1_event_date ed ON e.eventID = ed.eventID
LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
WHERE m.mapID IS NULL
ORDER BY ed.startTime DESC
LIMIT 10;

-- Falls wirklich Duplikate existieren (sehr selten):
-- Prüfe zuerst manuell, ob Events identisch sind!
```

**Wie das neue System Duplikate verhindert:**
1. **UID-Match**: Sucht zuerst nach Event mit bekanntem UID
2. **Property-Match**: Falls nicht gefunden, sucht nach Event mit gleicher Startzeit + Location
3. **Auto-Link**: Verknüpft gefundenes Event automatisch mit UID
4. **Update**: Aktualisiert existierendes Event statt neues zu erstellen

### Event wird nicht aktualisiert

**Symptom:** Event-Titel hat sich geändert, aber im Kalender bleibt der alte Titel.

**Lösung (ab v4.3.0):** 
- Das System findet Events jetzt auch wenn UID fehlt oder sich geändert hat
- Bei nächstem Cronjob-Lauf (alle 30 Min) wird Event automatisch aktualisiert
- Check Log für Details: `WHERE logLevel IN ('info', 'warning')`

**Debug:**
```sql
-- Zeige letzte Import-Aktivitäten
SELECT * FROM wcf1_calendar_import_log 
ORDER BY importTime DESC 
LIMIT 20;

-- Zeige UID-Mappings
SELECT m.*, e.subject, ed.startTime
FROM calendar1_ical_uid_map m
JOIN calendar1_event e ON m.eventID = e.eventID
JOIN calendar1_event_date ed ON e.eventID = ed.eventID
ORDER BY m.lastUpdated DESC
LIMIT 10;
```

---

## 📊 Datenbank-Tabellen

| Tabelle | Zweck |
|---------|-------|
| `calendar1_event_import` | Import-Konfiguration (URL, Kategorie) |
| `calendar1_ical_uid_map` | UID ↔ eventID Mapping |
| `calendar1_event` | Die Events selbst |
| `calendar1_event_date` | Start/End-Zeiten |

---

## 🔧 Cronjobs

| Cronjob | Intervall | Funktion |
|---------|-----------|----------|
| `ICalImportCronjob` | 0, 30 | Importiert Events mit API-Unterstützung |
| `MarkPastEventsReadCronjob` | 10, 40 | Vergangene als gelesen |

**Hinweis:** Der FixTimezoneCronjob wurde entfernt, da die Zeitzonen nun korrekt behandelt werden.

---

## 📝 Changelog

### v4.3.4 (2026-01-18) - Deep Debugging & Traceability Enhancements
- 🔍 **Import Session Tracking**
  - Each cronjob run now has a unique session ID (importSessionID)
  - All log messages include session context for correlation
  - Session lifecycle logging (start/end with statistics)
  - Can trace all operations within a single import run
- 📊 **Enhanced Per-Event Logging**
  - Detailed event information logged at processing start (UID, title, location, time, allDay)
  - Event decision logging (create vs update) with full context
  - Pre-create validation logging with event details
  - Method tracking (woltlab_api vs sql_fallback)
- 🎯 **Deduplication Strategy Visibility**
  - Log when starting event lookup with strategy list
  - Log each strategy attempt (UID mapping, time+location, time+title, fuzzy)
  - Log strategy success/failure with match details
  - Log similarity scores for fuzzy matching
  - Track which strategy found the match (primary vs secondary)
- ⏱️ **Intra-Run Duplicate Tracking Enhancements**
  - Log first processed timestamp when detecting duplicates
  - Better context for why event was skipped
  - Session-aware duplicate detection
- 📝 **Comprehensive Context in All Logs**
  - SessionID automatically added to context when available
  - Consistent context structure across all log messages
  - JSON-formatted context for easy parsing
  - Version bump to v4.3.4 in log messages
- 🧪 **Testing & Documentation**
  - Created comprehensive debugging guide (DEBUGGING_GUIDE_V434.md)
  - Added Mainz 05 feed simulation test script
  - Added test ICS file with intentional duplicates
  - All existing tests pass with new changes

### v4.3.3 (2026-01-18) - Critical Duplicate Prevention Enhancements
- 🐛 **CRITICAL: Fixed validateEventsForDuplicates to actually deduplicate**
  - Previously only logged duplicates but imported them anyway
  - Now actively removes duplicate UIDs from ICS file before import
  - Returns deduplicated list, preventing intra-file duplicates
- 🔒 **Added Intra-Run Duplicate Tracking**
  - New `processedUIDsInCurrentRun` tracking to prevent same UID being imported twice in one run
  - Import run timestamp tracking for better debugging
  - Prevents edge cases where ICS contains duplicates after initial dedup
- ⏰ **Widened Property Matching Time Window**
  - Increased from ±5 minutes to ±30 minutes (PROPERTY_MATCH_TIME_WINDOW = 1800)
  - Handles ICS feeds with time shifts better
  - Reduces false negatives in event matching
- 🎯 **Added Fuzzy Title Matching**
  - New Strategy 3: Fuzzy title matching with 70% similarity threshold
  - Uses similar_text() algorithm for better title comparison
  - Handles minor title variations (typos, formatting, etc.)
  - Fallback after exact location and LIKE pattern matching
- 📊 **Enhanced Logging for Deduplication**
  - Logs duplicate removal count from ICS file
  - Logs fuzzy matching similarity scores
  - Tracks processed UIDs in current run
  - Better visibility into why events are/aren't matched
- ✅ **Improved Event Count Tracking**
  - Now logs both pre- and post-deduplication counts
  - Clearer visibility into how many duplicates were removed
  - Better statistics for import operations

### v4.3.2 (2026-01-18) - Critical Duplicate Prevention Fixes
- 🐛 **Fixed Race Condition in UID Mapping** - Enhanced validation prevents duplicate event creation
  - Added bidirectional validation: one UID → one event, one event → one UID
  - Pre-create validation detects and prevents race conditions
  - saveUidMapping() now validates conflicts before inserting/updating
- 🔒 **Enhanced Property Matching Validation** - Prevents incorrect event reuse
  - Validates matched event doesn't already have different UID before reusing
  - Added detailed logging of match strategies (time_location_exact, time_title_like)
  - Improved error reporting for property matching failures
- 📊 **Comprehensive Decision Tracking** - Every decision is now logged
  - Added 'reason' field to all log entries for debugging
  - Track whether event was found by uid_mapping_match, property_match, or no_match_found
  - Log validation failures with detailed context
- ✅ **Event Existence Validation** - Verify events before update/create
  - updateEvent() now validates event exists before attempting update
  - createEvent() performs final UID check before insertion
  - Better error messages when validation fails
- 🧪 **Test Script Included** - test_duplicate_prevention.php for validation
  - Checks UID mapping table structure and constraints
  - Detects duplicate mappings and orphaned entries
  - Analyzes recent logs for duplicate-related issues
  - Provides statistics on events with/without UID mappings

### v4.3.1 (2026-01-18) - Timezone & Participation Fixes
- 🐛 **Fixed Timezone Offset Issue** - Local times now correctly use configured timezone (fixes 1-hour offset)
  - UTC times (with 'Z') continue to use UTC timezone
  - Local times (without 'Z') now explicitly use configured timezone instead of system default
  - All-day events now use configured timezone
  - Enhanced error handling and logging for date/time parsing
- ✅ **Configurable Registration Deadline** - New `CALENDAR_IMPORT_PARTICIPATION_HOURS_BEFORE` option
  - Default behavior unchanged (closes at event start)
  - Can be set to close registration 1-168 hours before event
  - Validates that deadline is not in the past
- 🐛 **Improved Title Fallback** - Enhanced UID trimming in getEventTitle()
  - Ensures UID is trimmed before use
  - Absolute fallback to "Unnamed Event" for null safety
- 📝 **Documentation Updates** - Added configuration examples for participation deadline

### v4.3.0 (2026-01-18) - Enhanced Event Deduplication
- ✅ **Property-Based Deduplication** - Findet Events auch ohne UID-Mapping
  - Primary: UID-basierte Suche (UNIQUE constraint)
  - Secondary: startTime + Location Match (präzise für Sportevents)
  - Tertiary: startTime + Titel-Ähnlichkeit (Fallback)
- ✅ **Auto-Migration** - Erstellt UID-Mappings für existierende Events automatisch
- ✅ **Expired Event Updates** - Aktualisiert abgelaufene Events statt neue zu erstellen
- ✅ **UID Change Handling** - Handhabt ICS-Feeds die UIDs bei Änderungen ändern
- ✅ **Duplicate Prevention** - Verhindert Duplikate auch bei fehlenden UID-Mappings
- ✅ **Time Window Matching** - ±5 Minuten Toleranz für Zeitunterschiede

### v4.2.0 (2026-01-15) - WoltLab Suite 6.1 Best Practices
- ✅ **Konfigurierbare Timezone** - Unterstützt alle PHP-Timezones
- ✅ **Enhanced Error Logging** - Strukturiertes Logging mit Context-Daten
- ✅ **SQL Injection Protection** - Dokumentiert und verifiziert alle parameterisierten Queries
- ✅ **Improved UID Validation** - Duplicate-Check vor Import mit Warnung
- ✅ **Better Debug Tools** - Log-Level konfigurierbar (error, warning, info, debug)
- ✅ **Comprehensive Documentation** - Alle Methoden dokumentiert mit Security-Hinweisen
- ✅ **API-First Approach** - WoltLab API primär, SQL als Fallback
- ✅ **Error Context** - Exceptions mit vollständigem Trace-Kontext

### v4.1.1 (2026-01-08)
- ✅ **Event-Titel-Fallback** - Kein Event ohne Titel mehr
- ✅ **API-basierte Thread-Erstellung** dokumentiert
- ✅ **FixTimezoneCronjob entfernt** - Workaround nicht mehr nötig
- ✅ **Package.xml aufgeräumt** - Keine veralteten Update-Instructions
- ✅ **Zeitzonen korrekt** - Keine doppelten Offsets mehr

### v4.1.0 (2026-01-07)
- ✅ **WoltLab API** statt direktem SQL
- ✅ **Event-Thread Support** automatisch
- ✅ **Vollautomatisch** - keine Konfiguration nötig
- ✅ **UID-Mapping** für alle Events
- ✅ **Keine Duplikate** mehr
- ✅ **categoryID** nie NULL

### v3.0.0 (2026-01-01)
- Neue Implementierung für WoltLab 6.1
- Teilnahme-Funktionen
- Gelesen/Ungelesen Logik

---

## 📄 Lizenz

Proprietäre Software von Luca Berwind.

---

**Viel Erfolg! 🚀**