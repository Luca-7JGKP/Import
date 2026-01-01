<?php
/**
 * Kalender Import Plugin - Debug-Script v3
 * Prüft Event-Listener, Optionen und Gelesen/Ungelesen-Status
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

// Berechtigungsprüfung: Nur Administratoren erlauben
if (!WCF::getUser()->userID || !WCF::getSession()->getPermission('admin.general.canUseAcp')) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Zugriff verweigert</title>
        <style>
            body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 40px; text-align: center; }
            .error-container { max-width: 500px; margin: 100px auto; background: #16213e; padding: 40px; border-radius: 12px; border-left: 4px solid #ff6b6b; }
            h1 { color: #ff6b6b; margin-bottom: 20px; }
            p { color: #aaa; margin-bottom: 20px; }
            a { color: #00d4ff; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>🔒 Zugriff verweigert</h1>
            <p>Diese Seite ist nur für Administratoren zugänglich.</p>
            <p>Bitte melden Sie sich mit einem Administrator-Konto an.</p>
            <p><a href="index.php?login/">→ Zur Anmeldung</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kalender Import - Debug v3</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00d4ff; }
        h2 { color: #ff6b6b; margin-top: 30px; border-bottom: 1px solid #2d3a5c; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #2d3a5c; }
        th { background: #0f3460; color: #00d4ff; }
        .ok { color: #00ff88; font-weight: bold; }
        .error { color: #ff6b6b; font-weight: bold; }
        .warning { color: #feca57; font-weight: bold; }
        .success-box { background: #143d1e; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #00ff88; }
        .error-box { background: #3d1414; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ff6b6b; }
        .warning-box { background: #3d2914; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #feca57; }
        code { background: #0f3460; padding: 2px 6px; border-radius: 4px; }
        .truncate { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Kalender Import - Debug v3</h1>
    <p>Zeitstempel: <?= date('Y-m-d H:i:s') ?></p>
    
    <?php
    // 1. Package-Info
    $package = null;
    try {
        $sql = "SELECT * FROM wcf".WCF_N."_package WHERE package = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['com.lucaberwind.wcf.calendar.import']);
        $package = $statement->fetchArray();
    } catch (\Exception $e) {}
    ?>
    
    <h2>1. Plugin-Installation</h2>
    <?php if ($package): ?>