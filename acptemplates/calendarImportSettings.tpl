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
                <small>URL zur ICS-Datei</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'calendarID'} class="formError"{/if}>
            <dt><label for="calendarID">Ziel-Kalender-ID</label></dt>
            <dd>
                <input type="number" id="calendarID" name="calendarID" value="{$calendarID}" class="short" min="0">
                <small>ID des Kalenders</small>
            </dd>
        </dl>
        
        <dl{if $errorField == 'targetImportID'} class="formError"{/if}>
            <dt><label for="targetImportID">Ziel-Import-ID</label></dt>
            <dd>
                <input type="number" id="targetImportID" name="targetImportID" value="{$targetImportID}" class="short" min="0">
                <small>Import-ID</small>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Tracking</h2>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="autoMarkPastRead" value="1"{if $autoMarkPastRead} checked{/if}> Vergangene als gelesen</label>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="markUpdatedUnread" value="1"{if $markUpdatedUnread} checked{/if}> Updates als ungelesen</label>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Allgemein</h2>
        
        <dl{if $errorField == 'boardID'} class="formError"{/if}>
            <dt><label for="boardID">Forum-ID</label></dt>
            <dd>
                <input type="number" id="boardID" name="boardID" value="{$boardID}" class="short" min="0">
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="createThreads" value="1"{if $createThreads} checked{/if}> Threads erstellen</label>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label><input type="checkbox" name="convertTimezone" value="1"{if $convertTimezone} checked{/if}> Zeitzone konvertieren</label>
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">Erweitert</h2>
        
        <dl{if $errorField == 'maxEvents'} class="formError"{/if}>
            <dt><label for="maxEvents">Max Events</label></dt>
            <dd>
                <input type="number" id="maxEvents" name="maxEvents" value="{$maxEvents}" class="short" min="1" max="10000">
            </dd>
        </dl>
        
        <dl{if $errorField == 'logLevel'} class="formError"{/if}>
            <dt><label for="logLevel">Log-Level</label></dt>
            <dd>
                <select id="logLevel" name="logLevel">
                    <option value="error"{if $logLevel == 'error'} selected{/if}>Fehler</option>
                    <option value="warning"{if $logLevel == 'warning'} selected{/if}>Warnung</option>
                    <option value="info"{if $logLevel == 'info'} selected{/if}>Info</option>
                    <option value="debug"{if $logLevel == 'debug'} selected{/if}>Debug</option>
                </select>
            </dd>
        </dl>
    </section>
    
    <div class="formSubmit">
        <input type="submit" value="{lang}wcf.global.button.submit{/lang}" accesskey="s">
        {csrfToken}
    </div>
</form>

<section class="section">
    <h2 class="sectionTitle">Debug</h2>
    
    <div style="background: #1a1a2e; color: #eee; padding: 20px; border-radius: 8px;">
        <p style="color: #888;">Zeit: {$debugInfo.timestamp}</p>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Plugin</h3>
        {if $debugInfo.package}
            <div style="background: #143d1e; padding: 10px; border-radius: 4px;">
                {$debugInfo.package.package} v{$debugInfo.package.packageVersion}
            </div>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px;">Nicht gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">ICS-URL Test</h3>
        {if $debugInfo.icsTest}
            {if $debugInfo.icsTest.reachable}
                <div style="background: #143d1e; padding: 10px; border-radius: 4px;">
                    OK - {$debugInfo.icsTest.eventCount} Events
                </div>
            {else}
                <div style="background: #3d1414; padding: 10px; border-radius: 4px;">
                    Fehler: HTTP {$debugInfo.icsTest.statusCode}
                </div>
            {/if}
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px;">Keine URL</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Kalender</h3>
        {if $debugInfo.calendars|count > 0}
            {foreach from=$debugInfo.calendars item=cal}
                <span style="background: #0f3460; padding: 3px 8px; border-radius: 3px; margin-right: 5px;">#{$cal.calendarID}</span>
            {/foreach}
        {else}
            <div style="background: #3d3414; padding: 10px; border-radius: 4px;">Keine gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Cronjobs</h3>
        {if $debugInfo.cronjobs|count > 0}
            {foreach from=$debugInfo.cronjobs item=cron}
                <div style="background: #0f3460; padding: 5px 10px; border-radius: 4px; margin-bottom: 5px;">
                    {if $cron.className|isset}{$cron.className}{else}ID: {$cron.cronjobID}{/if}
                    - {if $cron.isDisabled}<span style="color:#ff6b6b;">Aus</span>{else}<span style="color:#00ff88;">An</span>{/if}
                </div>
            {/foreach}
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px;">Keine gefunden</div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">PHP-Klassen</h3>
        {foreach from=$debugInfo.cronjobClasses key=className item=classData}
            <div style="margin-bottom: 3px;">
                {if $classData.exists}<span style="color:#00ff88;">✓</span>{else}<span style="color:#ff6b6b;">✗</span>{/if}
                <span style="font-family: monospace; font-size: 11px;">{$className}</span>
            </div>
        {/foreach}
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Optionen</h3>
        <table style="width: 100%; font-size: 11px;">
            {foreach from=$debugInfo.options key=optionName item=optionData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 4px; font-family: monospace;">{$optionName}</td>
                <td style="padding: 4px;">{$optionData.value}</td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Event-Listener: {$debugInfo.eventListeners|count}</h3>
        
        <h3 style="color: #00d4ff; margin: 15px 0 10px;">Pakete</h3>
        {foreach from=$debugInfo.calendarPackages item=pkg}
            <div style="font-size: 11px;">{$pkg.package} v{$pkg.packageVersion}</div>
        {/foreach}
    </div>
</section>

{include file='footer'}