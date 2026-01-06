<?php
/**
 * Calendar Import Debug Tool v3.0
 */
require_once(__DIR__ . '/global.php');
use wcf\system\WCF;

if (!WCF::getUser()->userID) {
    header('Location: ' . WCF::getPath() . 'login/');
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$message = '';
$messageType = '';

if ($action === 'test_import') {
    try {
        require_once(__DIR__ . '/lib/system/cronjob/ICalImportCronjob.class.php');
        $cronjob = new \wcf\system\cronjob\ICalImportCronjob();
        $cronjob->runManually();
        $message = 'Import wurde ausgefuehrt!';
        $messageType = 'success';
    } catch (\Exception $e) {
        $message = 'Import-Fehler: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

if ($action === 'fix_url' && isset($_POST['full_url'])) {
    try {
        $fullUrl = trim($_POST['full_url']);
        $importID = (int)$_POST['import_id'];
        $sql = "UPDATE calendar1_event_import SET url = ? WHERE importID = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$fullUrl, $importID]);
        $message = 'URL wurde aktualisiert!';
        $messageType = 'success';
    } catch (\Exception $e) {
        $message = 'Fehler: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

$testResult = null;
if ($action === 'test_url' && isset($_GET['url'])) {
    $testUrl = $_GET['url'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $content = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    $testResult = [
        'url' => $testUrl,
        'reachable' => ($content !== false && !empty($content)),
        'size' => strlen($content),
        'events_count' => preg_match_all('/BEGIN:VEVENT/', $content, $m) ? count($m[0]) : 0,
        'error' => $error
    ];
}

$data = [];

try {
    $sql = "SELECT optionID, optionName, optionValue FROM wcf1_option WHERE optionName LIKE 'calendar_import_%' ORDER BY optionName";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $data['options'] = $statement->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $data['options'] = [];
}

try {
    $sql = "SELECT * FROM calendar1_event_import ORDER BY importID";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $data['imports'] = $statement->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $data['imports'] = [];
}

try {
    $sql = "SELECT c.cronjobID, c.className, c.isDisabled, c.lastExec FROM wcf1_cronjob c WHERE c.className LIKE '%ICalImport%' ORDER BY c.className";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $data['cronjobs'] = $statement->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $data['cronjobs'] = [];
}

try {
    $sql = "SELECT COUNT(*) as count FROM calendar1_ical_uid_map";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $row = $statement->fetchArray();
    $data['uid_map_count'] = $row['count'];
} catch (\Exception $e) {
    $data['uid_map_count'] = 0;
}

try {
    $sql = "SELECT COUNT(*) as count FROM calendar1_event";
    $statement = WCF::getDB()->prepareStatement($sql);
    $statement->execute();
    $row = $statement->fetchArray();
    $data['events_count'] = $row['count'];
} catch (\Exception $e) {
    $data['events_count'] = 0;
}

$issues = [];
if (!empty($data['imports']) && strlen($data['imports'][0]['url']) < 70) {
    $issues[] = 'Die ICS-URL scheint unvollstaendig zu sein!';
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calendar Import Debug v3.0</title>';
echo '<style>body{font-family:Arial,sans-serif;margin:20px;background:#1a1a2e;color:#eee}';
echo '.container{max-width:1200px;margin:0 auto}h1{color:#00d4ff}h3{color:#ffd93d}';
echo '.card{background:#16213e;border-radius:10px;padding:20px;margin:15px 0;border:1px solid #0f3460}';
echo '.success{background:#1e5631;border-color:#2ecc71}.error{background:#5c1e1e;border-color:#e74c3c}';
echo 'table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:left;border-bottom:1px solid #0f3460}';
echo 'th{background:#0f3460;color:#00d4ff}.tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px}';
echo '.green{background:#2ecc71;color:#000}.red{background:#e74c3c}.yellow{background:#f1c40f;color:#000}';
echo '.btn{display:inline-block;padding:10px 20px;background:#00d4ff;color:#000;text-decoration:none;border-radius:6px;font-weight:bold;border:none;cursor:pointer;margin:5px}';
echo 'input[type=url]{width:100%;padding:10px;border:1px solid #0f3460;border-radius:6px;background:#0a0a15;color:#fff}';
echo '</style></head><body><div class="container">';

echo '<h1>Calendar Import Debug v3.0</h1>';
echo '<p>Erstellt: ' . date('Y-m-d H:i:s') . ' | User: ' . htmlspecialchars(WCF::getUser()->username) . '</p>';

if ($message) {
    echo '<div class="card ' . $messageType . '">' . htmlspecialchars($message) . '</div>';
}

echo '<div class="card">';
echo '<h3>Schnell-Aktionen</h3>';
echo '<a href="?action=test_import" class="btn" onclick="return confirm(\'Import jetzt ausfuehren?\')">Import jetzt ausfuehren</a>';
if (!empty($data['imports'])) {
    echo '<a href="?action=test_url&url=' . urlencode($data['imports'][0]['url']) . '" class="btn">URL testen</a>';
}
echo '<a href="?" class="btn">Seite neu laden</a>';
echo '</div>';

if ($testResult !== null) {
    echo '<div class="card ' . ($testResult['reachable'] ? 'success' : 'error') . '">';
    echo '<h3>URL-Test Ergebnis</h3>';
    echo '<table>';
    echo '<tr><th>URL</th><td>' . htmlspecialchars($testResult['url']) . '</td></tr>';
    echo '<tr><th>Erreichbar</th><td><span class="tag ' . ($testResult['reachable'] ? 'green' : 'red') . '">' . ($testResult['reachable'] ? 'JA' : 'NEIN') . '</span></td></tr>';
    echo '<tr><th>Groesse</th><td>' . number_format($testResult['size']) . ' Bytes</td></tr>';
    echo '<tr><th>Events gefunden</th><td><span class="tag green">' . $testResult['events_count'] . '</span></td></tr>';
    if ($testResult['error']) {
        echo '<tr><th>Fehler</th><td class="tag red">' . htmlspecialchars($testResult['error']) . '</td></tr>';
    }
    echo '</table></div>';
}

echo '<div class="card">';
echo '<h3>Import-Konfigurationen</h3>';
if (!empty($data['imports'])) {
    echo '<table><tr><th>ID</th><th>Titel</th><th>URL</th><th>Status</th><th>Letzter Lauf</th></tr>';
    foreach ($data['imports'] as $imp) {
        echo '<tr>';
        echo '<td>' . $imp['importID'] . '</td>';
        echo '<td>' . htmlspecialchars($imp['title']) . '</td>';
        echo '<td style="max-width:300px;word-break:break-all;font-size:11px">' . htmlspecialchars($imp['url']);
        if (strlen($imp['url']) < 70) {
            echo ' <span class="tag red">URL unvollstaendig!</span>';
        }
        echo '</td>';
        echo '<td><span class="tag ' . ($imp['isDisabled'] ? 'red' : 'green') . '">' . ($imp['isDisabled'] ? 'Aus' : 'An') . '</span></td>';
        echo '<td>' . ($imp['lastRun'] ? date('Y-m-d H:i', $imp['lastRun']) : 'Nie') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<h3>URL reparieren</h3>';
    echo '<form method="POST" action="?action=fix_url">';
    echo '<input type="hidden" name="import_id" value="' . $data['imports'][0]['importID'] . '">';
    echo '<input type="url" name="full_url" value="http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-c24ed538.ics">';
    echo '<br><br><button type="submit" class="btn">URL speichern</button>';
    echo '</form>';
} else {
    echo '<p class="tag red">Keine Import-Konfigurationen gefunden!</p>';
}
echo '</div>';

echo '<div class="card">';
echo '<h3>Plugin-Optionen</h3>';
if (!empty($data['options'])) {
    echo '<table><tr><th>Option</th><th>Wert</th></tr>';
    foreach ($data['options'] as $opt) {
        echo '<tr><td><code>' . htmlspecialchars($opt['optionName']) . '</code></td>';
        $val = $opt['optionValue'];
        if (empty($val)) {
            echo '<td><span class="tag yellow">LEER</span></td>';
        } else {
            echo '<td>' . htmlspecialchars(strlen($val) > 50 ? substr($val, 0, 50) . '...' : $val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}
echo '</div>';

echo '<div class="card">';
echo '<h3>Cronjobs</h3>';
if (!empty($data['cronjobs'])) {
    echo '<table><tr><th>Klasse</th><th>Status</th><th>Letzter Lauf</th></tr>';
    foreach ($data['cronjobs'] as $cj) {
        echo '<tr>';
        echo '<td><code style="font-size:10px">' . htmlspecialchars($cj['className']) . '</code></td>';
        echo '<td><span class="tag ' . ($cj['isDisabled'] ? 'red' : 'green') . '">' . ($cj['isDisabled'] ? 'Aus' : 'An') . '</span></td>';
        echo '<td>' . ($cj['lastExec'] ? date('Y-m-d H:i', strtotime($cj['lastExec'])) : '<span class="tag yellow">Nie</span>') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
echo '</div>';

echo '<div class="card">';
echo '<h3>Statistiken</h3>';
echo '<table>';
echo '<tr><th>UID Mappings</th><td>' . $data['uid_map_count'] . '</td></tr>';
echo '<tr><th>Events total</th><td>' . $data['events_count'] . '</td></tr>';
echo '</table>';
echo '</div>';

echo '<div class="card">';
echo '<h3>Automatische Diagnose</h3>';
echo '<ul>';
if (empty($issues)) {
    echo '<li><span class="tag green">Keine kritischen Probleme gefunden!</span></li>';
} else {
    foreach ($issues as $issue) {
        echo '<li><span class="tag red">' . htmlspecialchars($issue) . '</span></li>';
    }
}
echo '</ul>';
echo '</div>';

echo '<p style="color:#666;font-size:12px">Diese Debug-Datei nach dem Debugging wieder loeschen!</p>';
echo '</div></body></html>';
