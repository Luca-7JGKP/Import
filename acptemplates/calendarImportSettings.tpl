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
                <input type="number" id="calendarID" name="calendarID" value="{$calendarID}" class="short" min="0">
                <small>ID des Kalenders in den importiert werden soll</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'targetImportID'} class="formError"{/if}>
            <dt><label for="targetImportID">{lang}wcf.acp.calendar.import.targetImportID{/lang}</label></dt>
            <dd>
                <input type="number" id="targetImportID" name="targetImportID" value="{$targetImportID}" class="short" min="0">
                <small>{lang}wcf.acp.calendar.import.targetImportID.description{/lang}</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.tracking{/lang}</h2>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="autoMarkPastRead" value="1"{if $autoMarkPastRead} checked{/if}> {lang}wcf.acp.calendar.import.autoMarkPastEventsRead{/lang}</label>
                <small>{lang}wcf.acp.calendar.import.autoMarkPastEventsRead.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="markUpdatedUnread" value="1"{if $markUpdatedUnread} checked{/if}> {lang}wcf.acp.calendar.import.markUpdatedAsUnread{/lang}</label>
                <small>{lang}wcf.acp.calendar.import.markUpdatedAsUnread.description{/lang}</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.general{/lang}</h2>
        
        <dl{if $errorField == 'boardID'} class="formError"{/if}>
            <dt><label for="boardID">{lang}wcf.acp.calendar.import.boardID{/lang}</label></dt>
            <dd>
                <input type="number" id="boardID" name="boardID" value="{$boardID}" class="short" min="0">
                <small>{lang}wcf.acp.calendar.import.boardID.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="createThreads" value="1"{if $createThreads} checked{/if}> {lang}wcf.acp.calendar.import.createThreads{/lang}</label>
                <small>{lang}wcf.acp.calendar.import.createThreads.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="convertTimezone" value="1"{if $convertTimezone} checked{/if}> {lang}wcf.acp.calendar.import.convertTimezone{/lang}</label>
                <small>{lang}wcf.acp.calendar.import.convertTimezone.description{/lang}</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.advanced{/lang}</h2>
        
        <dl{if $errorField == 'maxEvents'} class="formError"{/if}>
            <dt><label for="maxEvents">{lang}wcf.acp.calendar.import.maxEvents{/lang}</label></dt>
            <dd>
                <input type="number" id="maxEvents" name="maxEvents" value="{$maxEvents}" class="short" min="1" max="10000">
                <small>{lang}wcf.acp.calendar.import.maxEvents.description{/lang}</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'logLevel'} class="formError"{/if}>
            <dt><label for="logLevel">{lang}wcf.acp.calendar.import.logLevel{/lang}</label></dt>
            <dd>
                <select id="logLevel" name="logLevel">
                    <option value="error"{if $logLevel == 'error'} selected{/if}>{lang}wcf.acp.calendar.import.logLevel.error{/lang}</option>
                    <option value="warning"{if $logLevel == 'warning'} selected{/if}>{lang}wcf.acp.calendar.import.logLevel.warning{/lang}</option>
                    <option value="info"{if $logLevel == 'info'} selected{/if}>{lang}wcf.acp.calendar.import.logLevel.info{/lang}</option>
                    <option value="debug"{if $logLevel == 'debug'} selected{/if}>{lang}wcf.acp.calendar.import.logLevel.debug{/lang}</option>
                </select>
                <small>{lang}wcf.acp.calendar.import.logLevel.description{/lang}</small>
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
    
    <div style="background: #1a1a2e; color: #eee; padding: 20px; border-radius: 8px; margin-top: 15px;">
        <p style="color: #888; margin-bottom: 15px;">Zeitstempel: {$debugInfo.timestamp}</p>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">1. Plugin-Installation</h3>
        {if $debugInfo.package}
            <div style="background: #143d1e; padding: 10px; border-radius: 4px; border-left: 3px solid #00ff88;">
                Plugin installiert: <strong>{$debugInfo.package.package}</strong><br>
                Version: {$debugInfo.package.packageVersion} | Package-ID: {$debugInfo.package.packageID}
            </div>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px; border-left: 3px solid #ff6b6b;">
                Plugin nicht gefunden!
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">2. ICS-URL Test</h3>
        {if $debugInfo.icsTest}
            {if $debugInfo.icsTest.reachable}
                <div style="background: #143d1e; padding: 10px; border-radius: 4px; border-left: 3px solid #00ff88;">
                    ✅ URL erreichbar | HTTP {$debugInfo.icsTest.statusCode} | {$debugInfo.icsTest.eventCount} Events gefunden
                </div>
                {if $debugInfo.icsTest.sampleEvents|count > 0}
                    <p style="margin-top: 10px; color: #aaa;">Beispiel-Events:</p>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                        {foreach from=$debugInfo.icsTest.sampleEvents item=eventTitle}
                            <li style="color: #ccc;">{$eventTitle}</li>
                        {/foreach}
                    </ul>
                {/if}
            {else}
                <div style="background: #3d1414; padding: 10px; border-radius: 4px; border-left: 3px solid #ff6b6b;">
                    ❌ URL nicht erreichbar | HTTP {$debugInfo.icsTest.statusCode}
                    {if $debugInfo.icsTest.error}<br>Fehler: {$debugInfo.icsTest.error}{/if}
                </div>
            {/if}
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px; border-left: 3px solid #feca57;">
                ⚠️ Keine ICS-URL konfiguriert
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">3. Verfügbare Kalender</h3>
        {if $debugInfo.calendars|count > 0}
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="background: #0f3460;">
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">ID</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Titel</th>
                </tr>
                {foreach from=$debugInfo.calendars item=cal}
                <tr style="border-bottom: 1px solid #2d3a5c;">
                    <td style="padding: 8px;">{$cal.calendarID}</td>
                    <td style="padding: 8px;">{$cal.title}</td>
                </tr>
                {/foreach}
            </table>
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px; border-left: 3px solid #feca57;">
                ⚠️ Keine Kalender gefunden
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">4. Cronjob-Status</h3>
        {if $debugInfo.cronjobs|count > 0}
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="background: #0f3460;">
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Cronjob</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Status</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Letzter Lauf</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Nächster Lauf</th>
                </tr>
                {foreach from=$debugInfo.cronjobs item=cron}
                <tr style="border-bottom: 1px solid #2d3a5c;">
                    <td style="padding: 8px; font-family: monospace; font-size: 10px;">{$cron.cronjobClassName|truncate:40}</td>
                    <td style="padding: 8px;">
                        {if $cron.isDisabled}
                            <span style="color: #ff6b6b;">🔴 Deaktiviert</span>
                        {else}
                            <span style="color: #00ff88;">🟢 Aktiv</span>
                        {/if}
                        {if $cron.failCount > 0}
                            <span style="color: #feca57;">({$cron.failCount} Fehler)</span>
                        {/if}
                    </td>
                    <td style="padding: 8px;">{if $cron.lastExec > 0}{$cron.lastExec|date:'Y-m-d H:i'}{else}Nie{/if}</td>
                    <td style="padding: 8px;">{if $cron.nextExec > 0}{$cron.nextExec|date:'Y-m-d H:i'}{else}N/A{/if}</td>
                </tr>
                {/foreach}
            </table>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px; border-left: 3px solid #ff6b6b;">
                ❌ Keine Cronjobs gefunden
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">5. Cronjob PHP-Klassen</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Klasse</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Status</th>
            </tr>
            {foreach from=$debugInfo.cronjobClasses key=className item=classData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace; font-size: 11px;">{$className}</td>
                <td style="padding: 8px;">
                    {if $classData.exists}
                        <span style="color: #00ff88;">✅ Vorhanden</span>
                    {else}
                        <span style="color: #ff6b6b;">❌ Fehlt</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">6. Letzte importierte Events</h3>
        {if $debugInfo.recentImports|count > 0}
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="background: #0f3460;">
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">ID</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Titel</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Importiert</th>
                </tr>
                {foreach from=$debugInfo.recentImports item=evt}
                <tr style="border-bottom: 1px solid #2d3a5c;">
                    <td style="padding: 8px;">{$evt.eventID}</td>
                    <td style="padding: 8px;">{$evt.subject}</td>
                    <td style="padding: 8px;">{$evt.time|date:'Y-m-d H:i'}</td>
                </tr>
                {/foreach}
            </table>
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px; border-left: 3px solid #feca57;">
                ⚠️ Noch keine Events importiert
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">7. Plugin-Optionen</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Option</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">DB-Wert</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Konstante</th>
            </tr>
            {foreach from=$debugInfo.options key=optionName item=optionData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace;">{$optionName}</td>
                <td style="padding: 8px;">{$optionData.value|truncate:50}</td>
                <td style="padding: 8px;">
                    {if $optionData.constantDefined}
                        <span style="color: #00ff88;">{$optionData.constantValue|truncate:50}</span>
                    {else}
                        <span style="color: #ff6b6b;">Nicht definiert</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">8. Event-Listener ({$debugInfo.eventListeners|count})</h3>
        {if $debugInfo.eventListeners|count > 0}
            <div style="background: #143d1e; padding: 10px; border-radius: 4px; border-left: 3px solid #00ff88;">
                {$debugInfo.eventListeners|count} Event-Listener registriert
            </div>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px; border-left: 3px solid #ff6b6b;">
                Keine Event-Listener registriert!
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">9. Installierte Kalender-Pakete</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Paket</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Version</th>
            </tr>
            {foreach from=$debugInfo.calendarPackages item=pkg}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace;">{$pkg.package}</td>
                <td style="padding: 8px;">{$pkg.packageVersion}</td>
            </tr>
            {/foreach}
        </table>
    </div>
</section>

{include file='footer'}