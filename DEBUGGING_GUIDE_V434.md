# Debugging Duplicate Events - Comprehensive Guide

## Version 4.3.4 Deep Debugging Features

This guide explains how to use the v4.3.4 enhanced debugging capabilities to trace and diagnose duplicate event issues.

---

## Overview

Despite comprehensive duplicate prevention mechanisms in v4.3.3, edge cases may still occur. Version 4.3.4 adds extensive logging and traceability to help diagnose these issues.

### What's New in v4.3.4

1. **Import Session Tracking** - Each cronjob run gets a unique session ID
2. **Per-Event Logging** - Full event details logged at each processing step
3. **Strategy Execution Tracking** - See which deduplication strategies are tried
4. **Enhanced Context** - All log messages include session context
5. **Lifecycle Visibility** - Trace an event from ICS parsing to database insertion

---

## Enabling Debug Logging

Add to `config.inc.php`:

```php
// Enable detailed debug logging
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

**Log Levels:**
- `error` - Only critical errors
- `warning` - Errors + warnings
- `info` - Standard operational logs (default)
- `debug` - Full detailed logs for debugging

**Important:** Debug logging is **verbose**. Only enable when actively troubleshooting.

---

## Reading the Logs

### Log Format

```
[Calendar Import v4.3.4] [level] message | Context: {"key":"value",...}
```

**Example:**
```
[Calendar Import v4.3.4] [info] Event decision: create | Context: {"sessionID":"import_6789abc","uid":"mainz-001-20260125","title":"Mainz 05 vs Bayern München","decision":"create","startTime":"2026-01-25 15:30:00"}
```

### Key Log Markers

#### Session Lifecycle

```
[info] === IMPORT SESSION START === | Context: {"sessionID":"import_6789abc","timestamp":"2026-01-18 21:30:00"}
...
[info] === IMPORT SESSION END === | Context: {"sessionID":"import_6789abc","duration":"3s","imported":5,"updated":10,"skipped":2}
```

**What to look for:**
- Multiple sessions running simultaneously (race condition indicator)
- Sessions with unexpected duration (> 60s may indicate issues)
- Sessions with high skip counts

#### Event Processing

```
[debug] Processing event | Context: {"sessionID":"...","uid":"mainz-001","title":"Mainz 05 vs Bayern","location":"Mewa Arena","startTime":"2026-01-25 15:30:00","allDay":"no"}
```

**What to look for:**
- Same UID appearing multiple times in one session
- Events with missing/invalid UIDs
- Time parsing issues

#### Deduplication Strategies

**Strategy 1: UID Mapping**
```
[debug] Starting event lookup | Context: {"sessionID":"...","uid":"mainz-001","strategies":"uid_mapping -> property_match"}
[debug] UID mapping found, validating event exists | Context: {"sessionID":"...","uid":"mainz-001","eventID":42}
[debug] Existing event found by UID mapping | Context: {"sessionID":"...","uid":"mainz-001","eventID":42,"reason":"uid_mapping_match","strategy":"primary"}
```

**Strategy 2: Property-Based (No UID Found)**
```
[debug] No UID mapping found, trying property-based matching | Context: {"sessionID":"...","uid":"mainz-001"}
[debug] Trying strategy 1: time + exact location | Context: {"sessionID":"...","location":"Mewa Arena"}
[info] Event matched by startTime + location | Context: {"sessionID":"...","eventID":42,"matchStrategy":"time_location_exact","strategy":"secondary"}
```

**Strategy 3: Fuzzy Matching**
```
[debug] Strategy 1 (time + location) found no match
[debug] Trying strategy 2: time + title LIKE | Context: {"sessionID":"...","titlePattern":"Mainz 05"}
[debug] Strategy 2 (time + title LIKE) found no match
[debug] Trying strategy 3: time + fuzzy title matching | Context: {"sessionID":"...","similarityThreshold":"70%"}
[info] Event matched by startTime + fuzzy title matching | Context: {"eventID":42,"similarity":"85.3%","matchStrategy":"time_title_fuzzy"}
```

**No Match Found:**
```
[debug] No property match found | Context: {"sessionID":"...","startTime":"2026-01-25 15:30:00","allStrategiesFailed":true}
```

#### Event Creation/Update Decision

```
[info] Event decision: create | Context: {"sessionID":"...","uid":"mainz-001","title":"Mainz 05 vs Bayern","decision":"create"}
[info] Event decision: update | Context: {"sessionID":"...","uid":"mainz-001","title":"Mainz 05 vs Bayern","decision":"update","existingEventID":42}
```

#### Race Condition Detection

```
[error] Race condition detected: UID already mapped, aborting create | Context: {"sessionID":"...","uid":"mainz-001","existingEventID":42,"reason":"race_condition_prevented"}
```

**Action:** This is **expected behavior** - the system prevented a duplicate. No action needed unless this happens frequently.

#### Intra-Run Duplicate

```
[warning] Event already processed in this run, skipping | Context: {"sessionID":"...","uid":"mainz-001","reason":"already_processed_in_run","firstProcessedAt":"2026-01-18 21:30:15"}
```

**Action:** Event appeared multiple times in ICS file, system correctly skipped the duplicate.

---

## Common Duplicate Scenarios

### Scenario 1: ICS File Contains Duplicates

**Symptoms:**
```
[warning] Duplicate UID in ICS file, skipping duplicate occurrence | Context: {"uid":"mainz-001","occurrence":2,"reason":"duplicate_uid_in_ics"}
[warning] Removed 3 duplicate events from ICS file | Context: {"originalCount":63,"deduplicatedCount":60}
```

**Cause:** ICS feed publisher included same event multiple times

**Action:** System handles this automatically, no action needed

### Scenario 2: UID Changes in Feed

**Symptoms:**
```
[debug] No UID mapping found, trying property-based matching
[info] Event matched by startTime + location
[info] Found existing event by properties, creating UID mapping
```

**Cause:** Feed provider changed the UID for an existing event

**Action:** System handles this automatically via property matching

### Scenario 3: Similar Events (Same Time, Different Details)

**Symptoms:**
```
[warning] Event found by properties already has different UID, treating as new event
[info] Event decision: create
```

**Cause:** Two genuinely different events at same time/location

**Action:** This is correct behavior - two separate events should be created

### Scenario 4: Time Shifted Events

**Symptoms:**
```
[debug] Strategy 1 (time + location) found no match
[debug] Strategy 2 (time + title LIKE) found no match  
[debug] Strategy 3 (fuzzy title) found no match above threshold
[info] Event decision: create
```

**Cause:** Event time changed by more than ±30 minutes AND title/location changed

**Possible Issue:** Time window might need adjustment, or this is a legitimately new event

**Action:** Review the specific event details. If it's a known existing event:
1. Check if time shift is > 30 minutes
2. Check if title similarity is < 70%
3. Consider if property matching criteria need adjustment

### Scenario 5: Actual Duplicate Creation

**Symptoms:**
```
[info] Event decision: create
[info] Creating new event | Context: {"uid":"mainz-001","title":"Mainz 05 vs Bayern"}
[info] Event created successfully via API | Context: {"eventID":50}
```

Later in same or different session:
```
[info] Event decision: create  
[info] Creating new event | Context: {"uid":"mainz-001","title":"Mainz 05 vs Bayern"}
[info] Event created successfully via API | Context: {"eventID":51}
```

**Cause:** System failed to find existing event through any strategy

**Action:** This is the issue we need to investigate. Check:
1. Was the first event actually created? (Check database)
2. Was a UID mapping created? (Check `calendar1_ical_uid_map`)
3. Why didn't the second lookup find the first event?

---

## Diagnostic Queries

### Check for Duplicate UIDs in Database

```sql
SELECT icalUID, COUNT(*) as count, GROUP_CONCAT(eventID) as eventIDs
FROM calendar1_ical_uid_map
GROUP BY icalUID
HAVING count > 1;
```

**Expected:** 0 rows (UNIQUE constraint should prevent this)
**If found:** Database constraint failed, serious issue

### Find Events Without UID Mapping

```sql
SELECT e.eventID, e.subject, ed.startTime
FROM calendar1_event e
JOIN calendar1_event_date ed ON e.eventID = ed.eventID
LEFT JOIN calendar1_ical_uid_map m ON e.eventID = m.eventID
WHERE m.mapID IS NULL
ORDER BY ed.startTime DESC
LIMIT 20;
```

**Action:** These events will be matched by properties on next import

### Find Potential Duplicates by Properties

```sql
SELECT e1.eventID as event1_id, e2.eventID as event2_id,
       e1.subject, e1.location,
       ed1.startTime,
       ABS(ed1.startTime - ed2.startTime) as time_diff_seconds
FROM calendar1_event e1
JOIN calendar1_event_date ed1 ON e1.eventID = ed1.eventID
JOIN calendar1_event e2 ON e1.location = e2.location
JOIN calendar1_event_date ed2 ON e2.eventID = ed2.eventID
WHERE e1.eventID < e2.eventID
  AND ABS(ed1.startTime - ed2.startTime) <= 1800
ORDER BY ed1.startTime DESC
LIMIT 20;
```

**Review:** Are these duplicates or genuinely separate events?

### Trace Specific Event by UID

```sql
-- Find in mapping
SELECT * FROM calendar1_ical_uid_map WHERE icalUID LIKE '%mainz-001%';

-- Find import logs
SELECT * FROM wcf1_calendar_import_log 
WHERE eventUID LIKE '%mainz-001%' 
ORDER BY importTime DESC;
```

### Analyze Import Session

```sql
-- Get logs for specific session
SELECT * FROM wcf1_calendar_import_log
WHERE message LIKE '%import_6789abc%'
ORDER BY importTime;
```

(Note: SessionID is in Context JSON, may need JSON extraction depending on MySQL version)

### Check Recent Import Activity

```sql
SELECT 
  DATE_FORMAT(FROM_UNIXTIME(importTime), '%Y-%m-%d %H:%i:%s') as time,
  logLevel,
  action,
  LEFT(message, 100) as message
FROM wcf1_calendar_import_log
WHERE importTime > UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR)
ORDER BY importTime DESC
LIMIT 50;
```

---

## Troubleshooting Workflow

### Step 1: Enable Debug Logging

```php
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

### Step 2: Trigger Import Manually

```php
// Via debug script
require_once('global.php');
require_once('lib/system/cronjob/ICalImportCronjob.class.php');

$cronjob = new \wcf\system\cronjob\ICalImportCronjob();
$cronjob->runManually();
```

### Step 3: Extract Session ID

Look for:
```
[info] === IMPORT SESSION START === | Context: {"sessionID":"import_6789abc",...}
```

Save this session ID for filtering logs.

### Step 4: Review Session Logs

Filter PHP error log for session ID:
```bash
grep "import_6789abc" /path/to/php-error.log
```

### Step 5: Check Database State

Run diagnostic queries (see above) to compare log expectations vs actual state.

### Step 6: Identify Discrepancy

Look for:
- Events that should have been found but weren't
- UID mappings that should exist but don't
- Property matches that should have succeeded but failed

### Step 7: Analyze Strategy Failures

For each event that duplicated:
1. Check: Did UID mapping lookup fail? Why?
2. Check: Did property matching fail all 3 strategies? Why?
3. Check: Are the matching criteria appropriate for this event type?

### Step 8: Determine Root Cause

Common root causes:
- **UID never saved:** Mapping creation failed after event creation
- **UID changed:** Feed provider changed UID, property matching failed
- **Time shifted:** Event time changed by > 30 minutes
- **Title changed significantly:** Fuzzy matching below 70% threshold
- **Location changed:** Exact location no longer matches
- **Database issue:** UNIQUE constraint violated or not enforced

---

## Advanced: Adjusting Matching Criteria

### Widen Time Window

If events are time-shifted by more than 30 minutes:

```php
// In ICalImportCronjob.class.php
const PROPERTY_MATCH_TIME_WINDOW = 3600; // 60 minutes instead of 30
```

**Warning:** Wider window increases false positives

### Adjust Fuzzy Matching Threshold

If titles vary more than 30%:

```php
const FUZZY_MATCH_SIMILARITY_THRESHOLD = 0.6; // 60% instead of 70%
```

**Warning:** Lower threshold increases false positives

### Add Custom Matching Strategy

If specific feed has unique characteristics, add strategy 4 in `findEventByProperties()`:

```php
// Strategy 4: Custom matching logic
if (!$bestMatch) {
    // Your custom logic here
}
```

---

## Preventing Future Duplicates

### Best Practices

1. **Monitor First Import:** Watch the first import closely, ensure all events mapped correctly
2. **Regular Audits:** Run duplicate detection queries weekly
3. **Log Review:** Periodically review warning/error logs
4. **Test Imports:** Test significant ICS feed changes in staging first
5. **Backup Before Cleanup:** Always backup before manually deleting suspected duplicates

### Automated Monitoring

Set up alerts for:
- High skip counts in import sessions
- Race condition detections (if frequent)
- UID mapping failures
- Property matching failures

### Emergency Cleanup

If duplicates do occur:

```sql
-- CAUTION: Review carefully before running!
-- This is just an example, adjust for your specific duplicates

-- 1. Identify duplicate pairs
SELECT e1.eventID as keep_id, e2.eventID as delete_id
FROM calendar1_event e1
JOIN calendar1_event e2 ON e1.subject = e2.subject
  AND e1.location = e2.location
JOIN calendar1_event_date ed1 ON e1.eventID = ed1.eventID
JOIN calendar1_event_date ed2 ON e2.eventID = ed2.eventID  
WHERE e1.eventID < e2.eventID
  AND ed1.startTime = ed2.startTime;

-- 2. Manually review results

-- 3. Delete duplicates (adjust IDs)
-- DELETE FROM calendar1_event WHERE eventID IN (51, 52, 53);

-- 4. Clean up orphaned mappings
DELETE m FROM calendar1_ical_uid_map m
LEFT JOIN calendar1_event e ON m.eventID = e.eventID
WHERE e.eventID IS NULL;
```

---

## Getting Help

If duplicates persist after following this guide:

1. **Collect Information:**
   - Session ID of import run where duplicate occurred
   - Affected event UID(s)
   - Complete logs for the session (grep output)
   - Results of diagnostic queries
   - ICS feed URL (if possible)

2. **Create Support Ticket:**
   - Include all collected information
   - Describe expected vs actual behavior
   - Note any custom configuration

3. **Temporary Workaround:**
   - Disable automatic imports
   - Manually review and import events
   - Monitor for patterns

---

## Summary

Version 4.3.4 provides comprehensive visibility into the duplicate prevention system. Use this logging to:

✅ Trace event lifecycle from ICS to database
✅ Identify which deduplication strategy succeeded/failed
✅ Correlate multiple operations in same import session
✅ Detect race conditions and edge cases
✅ Diagnose why duplicates occurred

**Remember:** Debug logging is verbose. Only enable when actively troubleshooting, disable afterwards.

---

**Last Updated:** 2026-01-18
**Version:** 4.3.4
