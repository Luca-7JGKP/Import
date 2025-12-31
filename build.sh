#!/bin/bash

# =============================================================================
# WoltLab Plugin Build Script
# Erstellt das installierbare Plugin-Paket für WoltLab Suite
# =============================================================================

set -e

PLUGIN_NAME="com.lucaberwind.wcf.calendar.import"
VERSION="1.3.2"
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
[ -f "eventListener.xml" ] && cp eventListener.xml "$BUILD_DIR/"
[ -f "options.xml" ] && cp options.xml "$BUILD_DIR/"
[ -f "acpMenu.xml" ] && cp acpMenu.xml "$BUILD_DIR/"
[ -f "install.sql" ] && cp install.sql "$BUILD_DIR/"
[ -f "uninstall.sql" ] && cp uninstall.sql "$BUILD_DIR/"

# Sprachdateien kopieren (als Verzeichnis)
if [ -d "language" ]; then
    mkdir -p "$BUILD_DIR/language"
    cp language/*.xml "$BUILD_DIR/language/" 2>/dev/null || true
    echo "✅ Sprachdateien kopiert"
fi

echo ""
echo "📦 Erstelle finales Plugin-Paket..."

# Finales TAR-Paket erstellen
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
echo "4. Cache leeren: ACP → Übersicht → Cache → Alles löschen"
echo "=================================================="

# Zeige Inhalt des Pakets
echo ""
echo "📋 Paketinhalt:"
tar -tf "${PLUGIN_NAME}.tar"
