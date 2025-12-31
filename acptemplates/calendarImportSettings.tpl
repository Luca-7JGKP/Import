{include file='header' pageTitle='wcf.acp.calendar.import.settings'}

<header class="contentHeader">
    <div class="contentHeaderTitle">
        <h1 class="contentTitle">{lang}wcf.acp.calendar.import.settings{/lang}</h1>
        <p class="contentHeaderDescription">{lang}wcf.acp.calendar.import.settings.description{/lang}</p>
    </div>
</header>

{if $success|isset}
    <p class="success">{lang}wcf.global.success.edit{/lang}</p>
{/if}

{if $errorField}
    <p class="error">{lang}wcf.global.form.error{/lang}</p>
{/if}

<form method="post" action="{link controller='CalendarImportSettings'}{/link}">
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.import{/lang}</h2>
        
        <dl{if $errorField == 'targetImportID'} class="formError"{/if}>
            <dt><label for="targetImportID">{lang}wcf.acp.calendar.import.targetImportID{/lang}</label></dt>
            <dd>
                <input type="number" id="targetImportID" name="targetImportID" value="{$targetImportID}" class="short" min="0">
                <small>{lang}wcf.acp.calendar.import.targetImportID.description{/lang}</small>
                {if $errorField == 'targetImportID'}
                    <small class="innerError">
                        {lang}wcf.acp.calendar.import.targetImportID.error.{@$errorType}{/lang}
                    </small>
                {/if}
            </dd>
        </dl>
    </section>
    
    <section class="section">
        <h2 class="sectionTitle">{lang}wcf.acp.calendar.import.tracking{/lang}</h2>
        
        <dl>
            <dt></dt>
            <dd>
                <label>
                    <input type="checkbox" name="autoMarkPastRead" value="1"{if $autoMarkPastRead} checked{/if}>
                    {lang}wcf.acp.calendar.import.autoMarkPastEventsRead{/lang}
                </label>
                <small>{lang}wcf.acp.calendar.import.autoMarkPastEventsRead.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label>
                    <input type="checkbox" name="markUpdatedUnread" value="1"{if $markUpdatedUnread} checked{/if}>
                    {lang}wcf.acp.calendar.import.markUpdatedAsUnread{/lang}
                </label>
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
                {if $errorField == 'boardID'}
                    <small class="innerError">
                        {lang}wcf.acp.calendar.import.boardID.error.{@$errorType}{/lang}
                    </small>
                {/if}
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label>
                    <input type="checkbox" name="createThreads" value="1"{if $createThreads} checked{/if}>
                    {lang}wcf.acp.calendar.import.createThreads{/lang}
                </label>
                <small>{lang}wcf.acp.calendar.import.createThreads.description{/lang}</small>
            </dd>
        </dl>
        
        <dl>
            <dt></dt>
            <dd>
                <label>
                    <input type="checkbox" name="convertTimezone" value="1"{if $convertTimezone} checked{/if}>
                    {lang}wcf.acp.calendar.import.convertTimezone{/lang}
                </label>
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
                {if $errorField == 'maxEvents'}
                    <small class="innerError">
                        {lang}wcf.acp.calendar.import.maxEvents.error.{@$errorType}{/lang}
                    </small>
                {/if}
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
        {@SECURITY_TOKEN_INPUT_TAG}
    </div>
</form>

{include file='footer'}
