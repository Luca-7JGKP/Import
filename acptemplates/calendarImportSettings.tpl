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
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.import{/lang}</h2>
        
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
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">2. Event-Listener ({$debugInfo.eventListeners|count})</h3>
        {if $debugInfo.eventListeners|count > 0}
            <div style="background: #143d1e; padding: 10px; border-radius: 4px; border-left: 3px solid #00ff88;">
                {$debugInfo.eventListeners|count} Event-Listener registriert
            </div>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px;">
                <tr style="background: #0f3460;">
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Name</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Event-Klasse</th>
                    <th style="padding: 8px; text-align: left; color: #00d4ff;">Event</th>
                </tr>
                {foreach from=$debugInfo.eventListeners item=listener}
                <tr style="border-bottom: 1px solid #2d3a5c;">
                    <td style="padding: 8px;">{$listener.listenerName}</td>
                    <td style="padding: 8px; font-family: monospace; font-size: 11px;">{$listener.eventClassName}</td>
                    <td style="padding: 8px;">{$listener.eventName}</td>
                </tr>
                {/foreach}
            </table>
        {else}
            <div style="background: #3d1414; padding: 10px; border-radius: 4px; border-left: 3px solid #ff6b6b;">
                Keine Event-Listener registriert! Plugin neu installieren.
            </div>
        {/if}
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">3. Plugin-Optionen</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Option</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">DB-Wert</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Konstante</th>
            </tr>
            {foreach from=$debugInfo.options key=optionName item=optionData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace;">{$optionName}</td>
                <td style="padding: 8px;">{$optionData.value}</td>
                <td style="padding: 8px;">
                    {if $optionData.constantDefined}
                        <span style="color: #00ff88;">{$optionData.constantValue}</span>
                    {else}
                        <span style="color: #ff6b6b;">Nicht definiert</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">4. Listener PHP-Klassen</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Klasse</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Status</th>
            </tr>
            {foreach from=$debugInfo.listenerClasses key=className item=classData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace; font-size: 11px;">{$className}</td>
                <td style="padding: 8px;">
                    {if $classData.exists}
                        <span style="color: #00ff88;">Vorhanden</span>
                    {else}
                        <span style="color: #ff6b6b;">Fehlt</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">5. WoltLab Kalender-Klassen</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #0f3460;">
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Klasse</th>
                <th style="padding: 8px; text-align: left; color: #00d4ff;">Status</th>
            </tr>
            {foreach from=$debugInfo.eventClasses key=className item=exists}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 8px; font-family: monospace; font-size: 11px;">{$className}</td>
                <td style="padding: 8px;">
                    {if $exists}
                        <span style="color: #00ff88;">Vorhanden</span>
                    {else}
                        <span style="color: #feca57;">Nicht gefunden</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff; margin: 20px 0 10px 0;">6. Installierte Kalender-Pakete</h3>
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