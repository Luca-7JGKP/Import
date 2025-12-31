#!/bin/bash

# =============================================================================
# WoltLab Plugin Build Script
# Erstellt das installierbare Plugin-Paket für WoltLab Suite
# =============================================================================

set -e

PLUGIN_NAME="com.lucaberwind.wcf.calendar.import"
VERSION="1.2.1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "🔨 Building WoltLab Plugin: $PLUGIN_NAME v$VERSION"
echo "=================================================="

# Temporäres Build-Verzeichnis erstellen
BUILD_DIR="$SCRIPT_DIR/build_temp"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

cd "$SCRIPT_DIR"

echo "📁 Erstelle TAR-Archive für Unterverzeichnisse..."

# files.tar erstellen (PHP-Dateien)
if [ -d "files" ]; then
    tar -cf "$BUILD_DIR/files.tar" -C files .
    echo "✅ files.tar erstellt"
else
    echo "⚠️  Verzeichnis 'files' nicht gefunden"
fi

# acptemplates.tar erstellen (ACP-Templates)
if [ -d "acptemplates" ]; then
    tar -cf "$BUILD_DIR/acptemplates.tar" -C acptemplates .
    echo "✅ acptemplates.tar erstellt"
else
    echo "⚠️  Verzeichnis 'acptemplates' nicht gefunden"
fi

# templates.tar erstellen (Frontend-Templates, falls vorhanden)
if [ -d "templates" ]; then
    tar -cf "$BUILD_DIR/templates.tar" -C templates .
    echo "✅ templates.tar erstellt"
fi

echo ""
echo "📦 Sammle Plugin-Dateien..."

# Kopiere Hauptdateien ins Build-Verzeichnis
cp package.xml "$BUILD_DIR/" || { echo "❌ FEHLER: package.xml nicht gefunden!"; exit 1; }
[ -f "install.sql" ] && cp install.sql "$BUILD_DIR/"
[ -f "uninstall.sql" ] && cp uninstall.sql "$BUILD_DIR/"

# XML-Konfigurationsdateien kopieren (als xml/ Verzeichnis für WoltLab)
if [ -d "xml" ]; then
    mkdir -p "$BUILD_DIR/xml"
    cp xml/*.xml "$BUILD_DIR/xml/" 2>/dev/null || true
    echo "✅ XML-Konfigurationsdateien kopiert"
fi

echo ""
echo "📦 Erstelle finales Plugin-Paket..."

# Finales TAR-Paket erstellen - WICHTIG: package.xml muss im Root sein!
cd "$BUILD_DIR"
tar -cf "$SCRIPT_DIR/${PLUGIN_NAME}.tar" *

cd "$SCRIPT_DIR"

# Aufräumen
rm -rf "$BUILD_DIR"

echo ""
echo "=================================================="
echo "✅ Build erfolgreich!"
echo ""
echo "📦 Plugin-Paket: ${PLUGIN_NAME}.tar"
echo ""
echo "Installation:"
echo "1. Gehe zu ACP → Pakete → Paket installieren"
echo "2. Wähle '${PLUGIN_NAME}.tar' aus"
echo "3. Installieren klicken"
echo "=================================================="

# Zeige Inhalt des Pakets zur Überprüfung
echo ""
echo "📋 Paketinhalt:"
tar -tvf "${PLUGIN_NAME}.tar"
