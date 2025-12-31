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

<form method="post" action="{link controller='CalendarImportSettings'}{/link}">
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.import{/lang}</h2>
        
        <dl>
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
        
        <dl>
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
        
        <dl>
            <dt><label for="maxEvents">{lang}wcf.acp.calendar.import.maxEvents{/lang}</label></dt>
            <dd>
                <input type="number" id="maxEvents" name="maxEvents" value="{$maxEvents}" class="short" min="1" max="10000">
                <small>{lang}wcf.acp.calendar.import.maxEvents.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
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
    <h2 class="sectionTitle">🔍 Debug-Informationen</h2>
    
    <div class="formFieldDesc" style="background: #1a1a2e; color: #eee; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 12px;">
        
        <h3 style="color: #00d4ff; margin-top: 0;">1. Plugin-Installation</h3>
        {if $debugInfo.package}
            <p style="color: #00ff88;">✅ Plugin installiert: {$debugInfo.package.package} v{$debugInfo.package.packageVersion}</p>
        {else}
            <p style="color: #ff6b6b;">❌ Plugin nicht gefunden!</p>
        {/if}
        
        <h3 style="color: #00d4ff;">2. Event-Listener ({$debugInfo.eventListeners|count})</h3>
        {if $debugInfo.eventListeners|count > 0}
            <p style="color: #00ff88;">✅ {$debugInfo.eventListeners|count} Event-Listener registriert</p>
            <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
                <tr style="background: #0f3460;"><th style="padding: 5px; text-align: left;">Name</th><th style="padding: 5px; text-align: left;">Event-Klasse</th></tr>
                {foreach from=$debugInfo.eventListeners item=listener}
                <tr style="border-bottom: 1px solid #2d3a5c;">
                    <td style="padding: 5px;">{$listener.listenerName}</td>
                    <td style="padding: 5px; font-size: 10px;">{$listener.eventClassName}</td>
                </tr>
                {/foreach}
            </table>
        {else}
            <p style="color: #ff6b6b;">❌ Keine Event-Listener registriert!</p>
        {/if}
        
        <h3 style="color: #00d4ff;">3. Plugin-Optionen</h3>
        <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
            <tr style="background: #0f3460;"><th style="padding: 5px; text-align: left;">Option</th><th style="padding: 5px; text-align: left;">DB-Wert</th><th style="padding: 5px; text-align: left;">Konstante</th></tr>
            {foreach from=$debugInfo.options key=optName item=optData}
            <tr style="border-bottom: 1px solid #2d3a5c;">
                <td style="padding: 5px; font-size: 10px;">{$optName}</td>
                <td style="padding: 5px;">{$optData.value}</td>
                <td style="padding: 5px;">{if $optData.constantDefined}<span style="color: #00ff88;">✅ {$optData.constantValue}</span>{else}<span style="color: #ff6b6b;">❌</span>{/if}</td>
            </tr>
            {/foreach}
        </table>
        
        <h3 style="color: #00d4ff;">4. Listener-Klassen</h3>
        {foreach from=$debugInfo.listenerClasses key=className item=classData}
            <p>{if $classData.exists}<span style="color: #00ff88;">✅</span>{else}<span style="color: #ff6b6b;">❌</span>{/if} {$className}</p>
        {/foreach}
        
        <h3 style="color: #00d4ff;">5. WoltLab Kalender-Klassen</h3>
        {foreach from=$debugInfo.eventClasses key=className item=exists}
            <p>{if $exists}<span style="color: #00ff88;">✅</span>{else}<span style="color: #feca57;">⚠️</span>{/if} {$className}</p>
        {/foreach}
        
        <h3 style="color: #00d4ff;">6. Kalender-Pakete</h3>
        {foreach from=$debugInfo.calendarPackages item=pkg}
            <p>📦 {$pkg.package} v{$pkg.packageVersion}</p>
        {/foreach}
    </div>
</section>

{include file='footer'}