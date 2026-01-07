# 📅 Kalender iCal Import Plugin v4.1

**Automatischer ICS-Import für WoltLab Suite 6.1**

| | |
|--|--|
| **Version** | 4.1.0 |
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
| 🔄 **Keine Duplikate** | UID-Mapping verhindert doppelte Events |
| 📝 **Event-Threads** | Automatisch Forum-Threads erstellen |
| 👥 **Teilnahme** | 99 Begleiter, öffentlich, änderbar |
| 🔔 **Gelesen/Ungelesen** | Neue Events = ungelesen |
| ⏰ **Cronjob** | Alle 30 Minuten automatischer Import |

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
│     Existiert UID schon? → Update               │
│     UID neu? → Neues Event erstellen            │
└─────────────────┬───────────────────────────────┘
                  ▼
┌─────────────────────────────────────────────────┐
│  4. WoltLab API wird genutzt                    │
│     → Event-Thread wird erstellt                │
│     → Suchindex wird aktualisiert               │
│     → Aktivitäten werden geloggt                │
└─────────────────────────────────────────────────┘
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

### Duplikate vorhanden

**Lösung:** Alte Events ohne UID-Mapping löschen:
```sql
-- Zeige Events ohne Mapping
SELECT e.eventID, e.subject FROM calendar1_event e
LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
WHERE m.mapID IS NULL;

-- Löschen (vorsichtig!)
DELETE e FROM calendar1_event e
LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
WHERE m.mapID IS NULL;
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
| `ICalImportCronjob` | 0, 30 | Importiert Events |
| `FixTimezoneCronjob` | 5, 35 | Korrigiert Zeitzonen |
| `MarkPastEventsReadCronjob` | 10, 40 | Vergangene als gelesen |

---

## 📝 Changelog

### v4.0.0 (2026-01-07)
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