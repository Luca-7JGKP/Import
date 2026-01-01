{include file='header' pageTitle='wcf.acp.calendar.import.settings'}

<header class="contentHeader">
    <div class="contentHeaderTitle">
        <h1 class="contentTitle">{lang}wcf.acp.calendar.import.settings{/lang}</h1>
        <p class="contentHeaderDescription">{lang}wcf.acp.calendar.import.settings.description{/lang}</p>
    </div>
</header>

{if $success|isset}
    <woltlab-core-notice type="success">{lang}wcf.global.success.edit{/lang}</woltlab-core-notice>
{/if}

{if $errorField}
    <woltlab-core-notice type="error">{lang}wcf.global.form.error{/lang}</woltlab-core-notice>
{/if}

<form method="post" action="{link controller='CalendarImportSettings'}{/link}">
    <section class="section">
        <h2 class="sectionTitle">ICS-Import Einstellungen</h2>
        
        <dl{if $errorField == 'icsUrl'} class="formError"{/if}>
            <dt><label for="icsUrl">ICS-URL</label></dt>
            <dd>
                <input type="text" id="icsUrl" name="icsUrl" value="{$icsUrl}" class="long">
                <small>URL zur ICS-Datei (z.B. https://example.com/calendar.ics)</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'calendarID'} class="formError"{/if}>
            <dt><label for="calendarID">Ziel-Kalender-ID</label></dt>
            <dd>
                <input type="number" id="calendarID" name="calendarID" value="{$calendarID}" class="short" min="1">
                <small>ID des Kalenders in den importiert werden soll (siehe Debug-Bereich unten)</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Tracking</h2>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="autoMarkPastRead" value="1"{if $autoMarkPastRead} checked{/if}> Vergangene Events automatisch als gelesen markieren</label>
                <small>Events deren Datum in der Vergangenheit liegt werden automatisch als gelesen markiert</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="markUpdatedUnread" value="1"{if $markUpdatedUnread} checked{/if}> Aktualisierte Events als ungelesen markieren</label>
                <small>Wenn ein Event aktualisiert wird, wird es wieder als ungelesen markiert</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Allgemein</h2>
        
        <dl{if $errorField == 'boardID'} class="formError"{/if}>
            <dt><label for="boardID">Forum-ID für Threads</label></dt>
            <dd>
                <input type="number" id="boardID" name="boardID" value="{$boardID}" class="short" min="0">
                <small>ID des Forums in dem Threads erstellt werden (0 = deaktiviert)</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="createThreads" value="1"{if $createThreads} checked{/if}> Threads für Events erstellen</label>
                <small>Erstellt automatisch einen Forum-Thread für jedes importierte Event</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="convertTimezone" value="1"{if $convertTimezone} checked{/if}> Zeitzone konvertieren</label>
                <small>Konvertiert UTC-Zeiten aus der ICS-Datei in die lokale Zeitzone</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Erweitert</h2>
        
        <dl{if $errorField == 'maxEvents'} class="formError"{/if}>
            <dt><label for="maxEvents">Maximale Events</label></dt>
            <dd>
                <input type="number" id="maxEvents" name="maxEvents" value="{$maxEvents}" class="short" min="1" max="10000">
                <small>Maximale Anzahl Events pro Import</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'logLevel'} class="formError"{/if}>
            <dt><label for="logLevel">Log-Level</label></dt>
            <dd>
                <select id="logLevel" name="logLevel">
                    <option value="error"{if $logLevel == 'error'} selected{/if}>Nur Fehler</option>
                    <option value="warning"{if $logLevel == 'warning'} selected{/if}>Warnungen</option>
                    <option value="info"{if $logLevel == 'info'} selected{/if}>Info</option>
                    <option value="debug"{if $logLevel == 'debug'} selected{/if}>Debug</option>
                </select>
                <small>Detailgrad der Protokollierung</small>
            </dd>
        </dl>
    </section>
    
    <div class="formSubmit">
        <input type="submit" value="{lang}wcf.global.button.submit{/lang}" accesskey="s">
        {csrfToken}
    </div>
</form>

<section class="section">
    <h2 class="sectionTitle">Debug-Informationen</h2>
    
    <div style="background: #1a1a2e; color: #eee; padding: 20px; border-radius: 8px;">
        <p style="color: #888;">Zeitstempel: {$debugInfo.timestamp}</p>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Plugin</h3>
        {if $debugInfo.package}
            <div style="background: #143d1e; padding: 10px; border-radius: 4px;">
                ✅ {$debugInfo.package.package} v{$debugInfo.package.packageVersion}
            </div>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px;">❌ Nicht gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">ICS-URL Test</h3>
        {if $debugInfo.icsTest}
            {if $debugInfo.icsTest.reachable}
                <div style="background: #143d1e; padding: 10px; border-radius: 4px;">
                    ✅ Erreichbar - {$debugInfo.icsTest.eventCount} Events gefunden
                </div>
            {else}
                <div style="background: #3d1414; padding: 10px; border-radius: 4px;">
                    ❌ Fehler: HTTP {$debugInfo.icsTest.statusCode}
                </div>
            {/if}
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px;">⚠️ Keine URL konfiguriert</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Verfügbare Kalender</h3>
        {if $debugInfo.calendars|count > 0}
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            {foreach from=$debugInfo.calendars item=cal}
                <span style="background: #0f3460; padding: 5px 12px; border-radius: 4px;">
                    ID: <strong>{$cal.calendarID}</strong>
                </span>
            {/foreach}
            </div>
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px;">⚠️ Keine Kalender gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Cronjobs</h3>
        {if $debugInfo.cronjobs|count > 0}
            {foreach from=$debugInfo.cronjobs item=cron}
                <div style="background: #0f3460; padding: 8px 12px; border-radius: 4px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-family: monospace; font-size: 11px;">
                        {if $cron.className|isset}{$cron.className}{else}ID: {$cron.cronjobID}{/if}
                    </span>
                    <span>
                        {if $cron.isDisabled}<span style="color:#ff6b6b;">🔴 Deaktiviert</span>{else}<span style="color:#00ff88;">🟢 Aktiv</span>{/if}
                    </span>
                </div>
            {/foreach}
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px;">❌ Keine Cronjobs gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">PHP-Klassen</h3>
        {foreach from=$debugInfo.cronjobClasses key=className item=classData}
            <div style="margin-bottom: 5px;">
                {if $classData.exists}<span style="color:#00ff88;">✅</span>{else}<span style="color:#ff6b6b;">❌</span>{/if}
                <span style="font-family: monospace; font-size: 11px; margin-left: 8px;">{$className}</span>
            </div>
        {/foreach}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Aktuelle Optionen</h3>
        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left;">Option</th>
                <th style="padding: 8px; text-align: left;">Wert</th>
            </tr>
            {foreach from=$debugInfo.options key=optionName item=optionData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 6px; font-family: monospace; font-size: 11px;">{$optionName}</td>
                <td style="padding: 6px;">{$optionData.value}</td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Event-Listener</h3>
        <div style="background: {if $debugInfo.eventListeners|count > 0}#143d1e{else}#3d1414{/if}; padding: 10px; border-radius: 4px;">
            {if $debugInfo.eventListeners|count > 0}
                ✅ {$debugInfo.eventListeners|count} Event-Listener registriert
            {else}
                ❌ Keine Event-Listener - Plugin neu installieren!
            {/if}
        </div>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Kalender-Pakete</h3>
        {foreach from=$debugInfo.calendarPackages item=pkg}
            <div style="font-size: 12px; padding: 3px 0;">{$pkg.package} v{$pkg.packageVersion}</div>
        {/foreach}
    </div>
</section>

{include file='footer'}