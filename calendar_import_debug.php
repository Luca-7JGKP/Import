<?php
/**
 * Kalender Import Plugin - Debug-Script v3
 * Prüft Event-Listener, Optionen und Gelesen/Ungelesen-Status
 */

require_once(__DIR__ . '/global.php');

use wcf\system\WCF;

// Prüfen, ob der Benutzer eingeloggt ist
if (!WCF::getUser()->userID) {
    $loginUrl = htmlspecialchars(WCF::getPath() . 'index.php?login/', ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Zugriff verweigert</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; padding: 40px; text-align: center; }
        .error-box { background: #3d1414; padding: 30px; border-radius: 8px; margin: 20px auto; max-width: 600px; border-left: 4px solid #ff6b6b; }
        h1 { color: #ff6b6b; }
        a { color: #00d4ff; text-decoration: none; font-weight: bold; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>🔒 Zugriff verweigert</h1>
        <p>Der Zutritt zu dieser Seite ist Ihnen leider verwehrt.</p>
        <p>Sie müssen eingeloggt sein, um diese Debug-Seite aufrufen zu können.</p>
        <p style="margin-top: 30px;">
            <a href="<?= $loginUrl ?>">→ Zur Anmeldung</a>
        </p>
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
        <div class="success-box">
            ✅ Plugin installiert: <strong><?= htmlspecialchars($package['package']) ?></strong><br>
            Version: <?= htmlspecialchars($package['packageVersion']) ?> | Package-ID: <?= $package['packageID'] ?>
        </div>
    <?php else: ?>
        <div class="error-box">❌ Plugin nicht gefunden!</div>
    <?php endif; ?>
    
    <?php
    // 2. Event-Listener für dieses Package
    $listeners = [];
    if ($package) {
        try {
            $sql = "SELECT * FROM wcf".WCF_N."_event_listener WHERE packageID = ?";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute([$package['packageID']]);
            while ($row = $statement->fetchArray()) {
                $listeners[] = $row;
            }
        } catch (\Exception $e) {}
    }
    ?>
    
    <h2>2. Event-Listener (für dieses Plugin)</h2>
    <?php if (!empty($listeners)): ?>
        <div class="success-box">✅ <?= count($listeners) ?> Event-Listener registriert</div>
        <table>
            <tr><th>Name</th><th>Event-Klasse</th><th>Event-Name</th><th>Listener-Klasse</th></tr>
            <?php foreach ($listeners as $l): ?>
            <tr>
                <td><?= htmlspecialchars($l['listenerName']) ?></td>
                <td class="truncate"><code><?= htmlspecialchars($l['eventClassName']) ?></code></td>
                <td><?= htmlspecialchars($l['eventName']) ?></td>
                <td class="truncate"><code><?= htmlspecialchars($l['listenerClassName']) ?></code></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="error-box">
            ❌ <strong>Keine Event-Listener registriert!</strong><br><br>
            Die <code>eventListener.xml</code> wurde nicht verarbeitet.<br>
            Prüfe ob die Datei im TAR-Archiv enthalten ist.
        </div>
    <?php endif; ?>
    
    <?php
    // 3. Optionen
    $options = [];
    try {
        $sql = "SELECT * FROM wcf".WCF_N."_option WHERE optionName LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['calendar_import%']);
        while ($row = $statement->fetchArray()) {
            $options[] = $row;
        }
    } catch (\Exception $e) {}
    ?>
    
    <h2>3. Plugin-Optionen</h2>
    <?php if (!empty($options)): ?>
        <div class="success-box">✅ <?= count($options) ?> Optionen gefunden</div>
        <table>
            <tr><th>Name</th><th>Wert</th><th>Konstante definiert</th></tr>
            <?php foreach ($options as $opt): 
                $constName = strtoupper($opt['optionName']);
                $defined = defined($constName);
            ?>
            <tr>
                <td><code><?= htmlspecialchars($opt['optionName']) ?></code></td>
                <td><?= htmlspecialchars($opt['optionValue']) ?></td>
                <td class="<?= $defined ? 'ok' : 'error' ?>"><?= $defined ? '✅ ' . constant($constName) : '❌ Nein' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="error-box">❌ Keine Optionen gefunden!</div>
    <?php endif; ?>
    
    <?php
    // 4. Listener-Klassen
    $listenerClasses = [
        'wcf\\system\\event\\listener\\ICalImportExtensionEventListener',
        'wcf\\system\\event\\listener\\CalendarEventViewListener'
    ];
    ?>
    
    <h2>4. Listener PHP-Klassen</h2>
    <table>
        <tr><th>Klasse</th><th>Existiert</th><th>Datei</th></tr>
        <?php foreach ($listenerClasses as $class): 
            $exists = class_exists($class);
        ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td class="<?= $exists ? 'ok' : 'error' ?>"><?= $exists ? '✅ Ja' : '❌ Nein' ?></td>
            <td class="truncate"><?= $exists ? (new \ReflectionClass($class))->getFileName() : '-' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <?php
    // 5. WoltLab Kalender Event-Klassen
    $eventClasses = [
        'calendar\\page\\CalendarEventPage',
        'calendar\\page\\CalendarPage', 
        'calendar\\system\\cronjob\\CalendarFeedImportCronjob',
        'calendar\\data\\event\\EventAction'
    ];
    ?>
    
    <h2>5. WoltLab Kalender Klassen</h2>
    <table>
        <tr><th>Klasse</th><th>Existiert</th></tr>
        <?php foreach ($eventClasses as $class): 
            $exists = class_exists($class);
        ?>
        <tr>
            <td><code><?= htmlspecialchars($class) ?></code></td>
            <td class="<?= $exists ? 'ok' : 'warning' ?>"><?= $exists ? '✅ Ja' : '⚠️ Nein' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <?php
    // 6. Kalender-Packages
    $calendarPackages = [];
    try {
        $sql = "SELECT package, packageVersion FROM wcf".WCF_N."_package WHERE package LIKE ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute(['%calendar%']);
        while ($row = $statement->fetchArray()) {
            $calendarPackages[] = $row;
        }
    } catch (\Exception $e) {}
    ?>
    
    <h2>6. Installierte Kalender-Plugins</h2>
    <table>
        <tr><th>Package</th><th>Version</th></tr>
        <?php foreach ($calendarPackages as $pkg): ?>
        <tr>
            <td><code><?= htmlspecialchars($pkg['package']) ?></code></td>
            <td><?= htmlspecialchars($pkg['packageVersion']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <?php
    // 7. Gelesen/Ungelesen Tabelle prüfen
    $visitTableExists = false;
    $visitCount = 0;
    try {
        $sql = "SELECT COUNT(*) as cnt FROM wcf".WCF_N."_tracked_visit WHERE objectTypeID IN (SELECT objectTypeID FROM wcf".WCF_N."_object_type WHERE objectType LIKE '%calendar%')";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();
        $visitTableExists = true;
        $visitCount = $row['cnt'];
    } catch (\Exception $e) {
        // Versuche alternative Tabelle
        try {
            $sql = "SELECT COUNT(*) as cnt FROM calendar".WCF_N."_event_visit";
            $statement = WCF::getDB()->prepareStatement($sql);
            $statement->execute();
            $row = $statement->fetchArray();
            $visitTableExists = true;
            $visitCount = $row['cnt'];
        } catch (\Exception $e2) {}
    }
    ?>
    
    <h2>7. Gelesen/Ungelesen-Tracking</h2>
    <?php if ($visitTableExists): ?>
        <div class="success-box">✅ Visit-Tracking Tabelle gefunden (<?= $visitCount ?> Einträge)</div>
    <?php else: ?>
        <div class="warning-box">⚠️ Keine Visit-Tracking Tabelle gefunden - WoltLab nutzt wcf_tracked_visit</div>
    <?php endif; ?>
    
    <hr style="margin: 40px 0; border-color: #2d3a5c;">
    <p style="color: #ff6b6b;"><strong>⚠️ Diese Datei nach dem Debugging löschen!</strong></p>
</div>
</body>
</html>