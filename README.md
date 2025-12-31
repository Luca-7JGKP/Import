# Kalender iCal Import Plugin für WoltLab Suite

Dieses Plugin ermöglicht den Import von Kalender-Events aus iCal-Dateien mit erweiterter Gelesen/Ungelesen-Logik für WoltLab Suite.

## 🔨 Plugin-Paket erstellen

Um das installierbare Plugin-Paket zu erstellen, führen Sie das Build-Script aus:

```bash
bash build.sh
```

Das Script erstellt automatisch:
- `files.tar` - PHP-Dateien
- `acptemplates.tar` - ACP-Templates
- `com.lucaberwind.wcf.calendar.import.tar` - Das finale installierbare Plugin-Paket

Das generierte Plugin-Paket folgt der WoltLab-Standardstruktur:
```
com.lucaberwind.wcf.calendar.import.tar
├── package.xml
├── install.sql
├── uninstall.sql
├── files.tar
├── acptemplates.tar
└── xml/
    ├── language.xml
    ├── language_de_informal.xml
    ├── eventListener.xml
    ├── option.xml
    ├── acpMenu.xml
    └── page.xml
```

## 📦 Installation

1. Gehen Sie zum WoltLab ACP (Admin Control Panel)
2. Navigieren Sie zu: **Pakete → Paket installieren**
3. Wählen Sie die generierte Datei `com.lucaberwind.wcf.calendar.import.tar` aus
4. Klicken Sie auf **Installieren**

## 📋 Systemanforderungen

- WoltLab Suite Core (WCF) Version 5.4.22 oder höher

## 👤 Autor

Luca Berwind

## 📄 Version

Aktuelle Version: 1.2.1
