#!/bin/bash

# =============================================================================
# WoltLab Plugin Build Script
# Erstellt das installierbare Plugin-Paket für WoltLab Suite
# =============================================================================

set -e

PLUGIN_NAME="com.lucaberwind.wcf.calendar.import"
VERSION="1.2.0"

echo "🔨 Building WoltLab Plugin: $PLUGIN_NAME v$VERSION"
echo "=================================================="

# Arbeitsverzeichnis erstellen
BUILD_DIR="build"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "📁 Erstelle TAR-Archive..."

# files.tar erstellen (PHP-Dateien)
if [ -d "files" ]; then
    cd files
    tar -cvf "../$BUILD_DIR/files.tar" .
    cd ..
    echo "✅ files.tar erstellt"
else
    echo "⚠️  Verzeichnis 'files' nicht gefunden"
fi

# acptemplates.tar erstellen (Smarty-Templates)
if [ -d "acptemplates" ]; then
    cd acptemplates
    tar -cvf "../$BUILD_DIR/acptemplates.tar" .
    cd ..
    echo "✅ acptemplates.tar erstellt"
else
    echo "⚠️  Verzeichnis 'acptemplates' nicht gefunden"
fi

# templates.tar erstellen (falls vorhanden)
if [ -d "templates" ]; then
    cd templates
    tar -cvf "../$BUILD_DIR/templates.tar" .
    cd ..
    echo "✅ templates.tar erstellt"
fi

echo ""
echo "📦 Erstelle finales Plugin-Paket..."

# Dateien ins Build-Verzeichnis kopieren
cp package.xml "$BUILD_DIR/" 2>/dev/null || echo "⚠️  package.xml nicht gefunden"
cp eventListener.xml "$BUILD_DIR/" 2>/dev/null || true
cp options.xml "$BUILD_DIR/" 2>/dev/null || true
cp install.sql "$BUILD_DIR/" 2>/dev/null || true

# Sprachdateien kopieren
if [ -d "language" ]; then
    mkdir -p "$BUILD_DIR/language"
    cp language/*.xml "$BUILD_DIR/language/" 2>/dev/null || true
    echo "✅ Sprachdateien kopiert"
fi

# Finales TAR-Paket erstellen
cd "$BUILD_DIR"
tar -cvf "../${PLUGIN_NAME}.tar" .
cd ..

echo ""
echo "=================================================="
echo "✅ Build erfolgreich!"
echo ""
echo "📦 Plugin-Paket erstellt: ${PLUGIN_NAME}.tar"
echo ""
echo "Installation:"
echo "1. Lade die Datei '${PLUGIN_NAME}.tar' herunter"
echo "2. Gehe zu ACP → Pakete → Paket installieren"
echo "3. Wähle die TAR-Datei aus und installiere"
echo "=================================================="

# Aufräumen (optional - auskommentieren um Build-Verzeichnis zu behalten)
# rm -rf "$BUILD_DIR"

echo ""
echo "📁 Build-Verzeichnis: $BUILD_DIR/"
ls -la "$BUILD_DIR/"
