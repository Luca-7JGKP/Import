<?php
/**
 * Kalender Import Plugin - Externes Debug-Script
 * 
 * Dieses Script kann direkt aufgerufen werden ohne Plugin-Installation.
 * Upload ins WoltLab-Hauptverzeichnis und aufrufen via: https://domain.de/calendar_import_debug.php
 * 
 * WICHTIG: Nach dem Debugging wieder löschen!
 * 
 * @author  Copilot
 * @license GNU Lesser General Public License
 */

// WoltLab-Framework laden
require_once(__DIR__ . '/global.php');

use wcf\system\WCF;
use wcf\system\language\LanguageFactory;

// Nur für Admins zugänglich
if (!WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    die('Zugriff verweigert. Bitte als Administrator einloggen.');
}

$debugInfo = [];

// 1. Aktuelle Spracheinstellungen
$debugInfo['current_language'] = [
    'id' => WCF::getLanguage()->languageID,
    'name' => WCF::getLanguage()->languageName,
    'code' => WCF::getLanguage()->languageCode
];

// 2. Alle verfügbaren Sprachen
$debugInfo['available_languages'] = [];
foreach (LanguageFactory::getInstance()->getLanguages() as $language) {
    $debugInfo['available_languages'][] = [
        'id' => $language->languageID,
        'name' => $language->languageName,
        'code' => $language->languageCode
    ];
}

// 3. Sprachvariablen-Test
$testKeys = [
    'wcf.acp.menu.link.calendar.import',
    'wcf.acp.calendar.import.settings',
    'wcf.acp.calendar.import.settings.description',
    'wcf.acp.calendar.import.targetImportID',
    'wcf.acp.calendar.import.boardID',
    'wcf.acp.calendar.import.createThreads',
    'wcf.acp.calendar.import.autoMarkPastEventsRead',
    'wcf.acp.calendar.import.markUpdatedAsUnread',
    'wcf.acp.calendar.import.convertTimezone',
    'wcf.acp.calendar.import.general',
    'wcf.acp.calendar.import.tracking',
    'wcf.acp.calendar.import.advanced',
    'wcf.acp.calendar.import.import',
    'wcf.acp.calendar.import.maxEvents',
    'wcf.acp.calendar.import.logLevel'
];

$debugInfo['language_keys'] = [];
foreach ($testKeys as $key) {
    $value = WCF::getLanguage()->get($key);
    $isDefined = ($value !== $key && !empty($value));
    $debugInfo['language_keys'][$key] = [
        'value' => $value ?: '(LEER)',
        'is_defined' => $isDefined,
        'status' => $isDefined ? 'OK' : 'MISSING'
    ];
}

// 4. Datenbank-Einträge
$sql = "SELECT li.languageItemID, li.languageItem, li.languageItemValue, li.languageCategoryID, li.languageID, l.languageCode
        FROM wcf".WCF_N."_language_item li
        LEFT JOIN wcf".WCF_N."_language l ON l.languageID = li.languageID
        WHERE li.languageItem LIKE ? OR li.languageItem LIKE ?
        ORDER BY li.languageItem, l.languageCode";
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute(['wcf.acp.calendar.import%', 'wcf.acp.menu.link.calendar.import%']);

$debugInfo['database_entries'] = [];
while ($row = $statement->fetchArray()) {
    $debugInfo['database_entries'][] = $row;
}

// 5. Sprachkategorie prüfen
$sql = "SELECT * FROM wcf".WCF_N."_language_category WHERE languageCategory = ?";
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute(['wcf.acp.calendar.import']);
$debugInfo['category_check'] = $statement->fetchArray() ?: ['status' => 'NOT FOUND'];

// 6. Package-Info
$sql = "SELECT * FROM wcf".WCF_N."_package WHERE package LIKE ?";
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute(['%calendar%import%']);
$debugInfo['package_info'] = [];
while ($row = $statement->fetchArray()) {
    $debugInfo['package_info'][] = $row;
}

// 7. Cache-Info
$cacheDir = WCF_DIR . 'cache/';
$debugInfo['cache_info'] = [
    'cache_dir' => $cacheDir,
    'cache_dir_exists' => is_dir($cacheDir),
    'cache_dir_writable' => is_writable($cacheDir)
];

// HTML Output
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalender Import - Debug</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #00d4ff; }
        h2 { color: #ff6b6b; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #2d3a5c; }
        th { background: #0f3460; color: #00d4ff; }
        .ok { color: #00ff88; font-weight: bold; }
        .missing { color: #ff6b6b; font-weight: bold; }
        .info-box { background: #16213e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00d4ff; }
        .warning-box { background: #3d2914; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #feca57; }
        .error-box { background: #3d1414; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ff6b6b; }
        .success-box { background: #143d1e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00ff88; }
        code { background: #0f3460; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Kalender Import - Debug</h1>
    <p>Zeitstempel: <?= date('Y-m-d H:i:s') ?></p>
    
    <h2>1. Aktuelle Sprache</h2>
    <div class="info-box">
        <p><strong>Sprache:</strong> <?= htmlspecialchars($debugInfo['current_language']['name']) ?> (<?= $debugInfo['current_language']['code'] ?>)</p>
        <p><strong>ID:</strong> <?= $debugInfo['current_language']['id'] ?></p>
    </div>
    
    <h2>2. Sprachvariablen-Test</h2>
    <?php
    $missing = array_filter($debugInfo['language_keys'], fn($d) => $d['status'] === 'MISSING');
    if (count($missing) > 0): ?>
        <div class="warning-box">⚠️ <?= count($missing) ?> Variablen werden NICHT geladen!</div>
    <?php else: ?>
        <div class="success-box">✅ Alle Variablen werden geladen!</div>
    <?php endif; ?>
    
    <table>
        <tr><th>Schlüssel</th><th>Wert</th><th>Status</th></tr>
        <?php foreach ($debugInfo['language_keys'] as $key => $data): ?>
        <tr>
            <td><code><?= htmlspecialchars($key) ?></code></td>
            <td><?= htmlspecialchars(substr($data['value'], 0, 50)) ?></td>
            <td class="<?= $data['status'] === 'OK' ? 'ok' : 'missing' ?>"><?= $data['status'] === 'OK' ? '✅ OK' : '❌ MISSING' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>3. Datenbank-Einträge</h2>
    <?php if (count($debugInfo['database_entries']) > 0): ?>
        <div class="success-box">✅ <?= count($debugInfo['database_entries']) ?> Einträge gefunden</div>
        <table>
            <tr><th>ID</th><th>Schlüssel</th><th>Sprache</th><th>Kategorie-ID</th></tr>
            <?php foreach ($debugInfo['database_entries'] as $row): ?>
            <tr>
                <td><?= $row['languageItemID'] ?></td>
                <td><code><?= htmlspecialchars($row['languageItem']) ?></code></td>
                <td><?= $row['languageCode'] ?></td>
                <td><?= $row['languageCategoryID'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="error-box">❌ Keine Einträge in der Datenbank!</div>
    <?php endif; ?>
    
    <h2>4. Sprachkategorie</h2>
    <?php if (isset($debugInfo['category_check']['languageCategoryID'])): ?>
        <div class="success-box">✅ Kategorie "wcf.acp.calendar.import" existiert (ID: <?= $debugInfo['category_check']['languageCategoryID'] ?>)</div>
    <?php else: ?>
        <div class="error-box">❌ Kategorie "wcf.acp.calendar.import" fehlt!</div>
    <?php endif; ?>
    
    <h2>5. Plugin-Info</h2>
    <?php if (count($debugInfo['package_info']) > 0): ?>
        <table>
            <tr><th>ID</th><th>Package</th><th>Version</th></tr>
            <?php foreach ($debugInfo['package_info'] as $pkg): ?>
            <tr>
                <td><?= $pkg['packageID'] ?></td>
                <td><code><?= htmlspecialchars($pkg['package']) ?></code></td>
                <td><?= htmlspecialchars($pkg['packageVersion']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="warning-box">⚠️ Kein Plugin gefunden</div>
    <?php endif; ?>
    
    <h2>6. Lösung</h2>
    <div class="warning-box">
        <h3>Cache leeren:</h3>
        <ol>
            <li>ACP → System → Daten zurücksetzen</li>
            <li>Häkchen bei "Sprachcache" und "Template-Cache"</li>
            <li>"Daten zurücksetzen" klicken</li>
            <li>Browser neu laden (Strg+F5)</li>
        </ol>
        <p>Falls das nicht hilft: Plugin deinstallieren und neu installieren.</p>
    </div>
    
    <hr>
    <p style="color: #ff6b6b;"><strong>⚠️ Diese Datei nach dem Debugging löschen!</strong></p>
</div>
</body>
</html>