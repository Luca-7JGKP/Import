# Duplicate Event Prevention - Technical Documentation

## Overview

Version 4.3.2 introduces comprehensive duplicate prevention mechanisms to ensure that events from ICS feeds are never created multiple times, even when the cronjob runs repeatedly.

## The Problem

Prior to v4.3.2, duplicates could occur in several scenarios:
1. **Race conditions** - Multiple simultaneous imports could create duplicate events
2. **UID mapping conflicts** - One event could be mapped to multiple UIDs or vice versa
3. **Property matching issues** - Events found by properties could be incorrectly reused
4. **Insufficient validation** - Lack of pre-create checks allowed duplicates to slip through

## The Solution

### 1. Enhanced UID Mapping with Bidirectional Validation

**File**: `ICalImportCronjob.class.php::saveUidMapping()`

**Validation performed**:
```php
// Check 1: Ensure UID is not already mapped to a DIFFERENT event
$existingEventID = findEventByUID($uid);
if ($existingEventID !== false && $existingEventID != $eventID) {
    log('error', 'UID already mapped to different event');
    return false; // Abort
}

// Check 2: Ensure event is not already mapped to a DIFFERENT UID
$existingUID = findUIDByEvent($eventID);
if ($existingUID !== false && $existingUID !== $uid) {
    log('error', 'Event already mapped to different UID');
    return false; // Abort
}
```

**Result**: Guarantees one-to-one relationship between UIDs and events.

### 2. Property Match Validation

**File**: `ICalImportCronjob.class.php::findExistingEvent()`

When an event is found by properties (startTime + location/title), we now validate:

```php
// Before reusing the matched event, check if it already has a UID
$existingUID = findUIDForEvent($matchedEventID);

if ($existingUID !== false && $existingUID !== $currentUID) {
    log('warning', 'Event already has different UID, treating as new');
    return null; // Don't reuse, create new event instead
}
```

**Why this matters**: An ICS feed might contain two similar events (same time, location) with different UIDs. Without this check, the second event would reuse the first event's record, creating a conflict.

### 3. Race Condition Prevention

**File**: `ICalImportCronjob.class.php::createEvent()`

**Final check before creating**:
```php
// CRITICAL: Last-second check before creating
$sql = "SELECT eventID FROM calendar1_ical_uid_map WHERE icalUID = ?";
$existingEventID = executeQuery($sql, [$uid]);

if ($existingEventID !== false) {
    log('error', 'Race condition detected: UID already mapped');
    $this->skippedCount++;
    return; // Abort creation
}
```

**Scenario**: Two cronjobs run simultaneously, both check for the UID (not found), and both attempt to create. The UNIQUE constraint on the database would catch this, but the final check prevents the error from occurring.

### 4. Comprehensive Decision Tracking

Every decision point now logs:
- **What was checked** (UID mapping, property matching, validation)
- **Why decision was made** (reason field: uid_mapping_match, property_match, uid_mismatch, race_condition_prevented)
- **What was found** (eventID, UID, timestamps, titles)

Example log entries:
```
[info] Existing event found by UID mapping | uid: abc123... | eventID: 42 | reason: uid_mapping_match
[warning] Event already has different UID, treating as new | eventID: 42 | existingUID: xyz789... | reason: uid_mismatch
[error] Race condition detected: UID already mapped | uid: abc123... | existingEventID: 42 | reason: race_condition_prevented
```

## Decision Flow Chart

```
┌─────────────────────────────────┐
│   Import Event from ICS Feed    │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  1. Search UID Mapping Table    │
│     SELECT eventID               │
│     WHERE icalUID = ?            │
└────────────┬────────────────────┘
             │
             ├─ Found? ──────────────┐
             │                       ▼
             │              ┌───────────────────┐
             │              │ Verify Event      │
             │              │ Still Exists      │
             │              └────────┬──────────┘
             │                       │
             │                  Yes  │  No (deleted)
             │                       │
             │                       ▼
             │              ┌───────────────────┐
             │              │ Clean Up Orphaned │
             │              │ Mapping & Retry   │
             │              └───────────────────┘
             │                       
             ├─ Not Found ─────────────────────┐
             │                                  ▼
             │                     ┌──────────────────────────┐
             │                     │ 2. Property Match        │
             │                     │    Search by:            │
             │                     │    - startTime ±5 min    │
             │                     │    - location (exact)    │
             │                     │    OR title (LIKE)       │
             │                     │    - categoryID          │
             │                     │    - No existing mapping │
             │                     └────────┬─────────────────┘
             │                              │
             │                              ├─ Found? ─────────────┐
             │                              │                       ▼
             │                              │          ┌──────────────────────┐
             │                              │          │ 3. Validate Match    │
             │                              │          │    Check if event    │
             │                              │          │    already has       │
             │                              │          │    different UID     │
             │                              │          └────────┬─────────────┘
             │                              │                   │
             │                              │              Yes (has different UID)
             │                              │                   │
             │                              │                   ▼
             │                              │          ┌──────────────────────┐
             │                              │          │ Reject Match         │
             │                              │          │ Create New Instead   │
             │                              │          └──────────────────────┘
             │                              │
             │                              │              No (no UID or same UID)
             │                              │                   │
             │                              │                   ▼
             │                              │          ┌──────────────────────┐
             │                              │          │ Accept Match         │
             │                              │          │ Create UID Mapping   │
             │                              │          └────────┬─────────────┘
             │                              │                   │
             ▼                              ▼                   ▼
┌──────────────────────────────────────────────────────────────────┐
│                         UPDATE EVENT                             │
│  - Update event details (title, time, location)                  │
│  - Update UID mapping lastUpdated timestamp                      │
│  - Log: "Event updated successfully"                             │
└──────────────────────────────────────────────────────────────────┘

             │
             ▼ (No match found)
┌──────────────────────────────────┐
│  4. Final Pre-Create Check       │
│     SELECT eventID               │
│     WHERE icalUID = ?            │
│     (Race condition protection)  │
└────────────┬─────────────────────┘
             │
             ├─ Found? ────────────────────┐
             │                              ▼
             │                     ┌──────────────────┐
             │                     │ Abort Create     │
             │                     │ (Race condition  │
             │                     │  prevented)      │
             │                     └──────────────────┘
             │
             ▼ (Not found - safe to create)
┌──────────────────────────────────┐
│       CREATE NEW EVENT            │
│  - Insert into calendar1_event   │
│  - Insert into event_date table  │
│  - Create UID mapping with       │
│    bidirectional validation      │
│  - Log: "Event created"          │
└──────────────────────────────────┘
```

## Database Schema

### calendar1_ical_uid_map
```sql
CREATE TABLE calendar1_ical_uid_map (
    mapID INT(10) NOT NULL AUTO_INCREMENT,
    eventID INT(10) NOT NULL,
    icalUID VARCHAR(255) NOT NULL,
    importID INT(10) DEFAULT NULL,
    lastUpdated INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (mapID),
    UNIQUE KEY icalUID (icalUID),  -- ← CRITICAL: Prevents duplicate UIDs
    KEY eventID (eventID),
    KEY importID (importID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key constraint**: `UNIQUE KEY icalUID` ensures no two mappings can have the same UID, providing database-level duplicate prevention.

## Testing

Use the provided `test_duplicate_prevention.php` script to verify:

1. **Table structure** - Ensures UNIQUE constraint exists
2. **No duplicate UIDs** - Detects if multiple events share a UID
3. **No multiple UIDs per event** - Detects if one event has multiple UIDs
4. **Orphaned mappings** - Finds mappings pointing to deleted events
5. **Log analysis** - Reviews recent logs for duplicate-related issues
6. **Statistics** - Shows how many events have UID mappings

### Running the Test Script

```bash
# Place in WCF root directory
php test_duplicate_prevention.php

# Or access via browser
https://your-site.com/test_duplicate_prevention.php
```

## Configuration

### Enable Debug Logging

Add to `config.inc.php`:
```php
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

This will log every decision made during import, making it easy to track down any issues.

### Log Locations

- **PHP error log**: `/path/to/php/error.log` (varies by server)
- **Database log**: `wcf1_calendar_import_log` table
- **Query**: `SELECT * FROM wcf1_calendar_import_log ORDER BY importTime DESC LIMIT 50;`

## Common Scenarios

### Scenario 1: First Import
1. No UID mappings exist
2. Events are created via `createEvent()`
3. UID mappings are created via `saveUidMapping()`
4. Result: All events have mappings

### Scenario 2: Subsequent Imports (No Changes)
1. UID mappings found for all events
2. Events are updated via `updateEvent()`
3. UID mapping timestamps updated
4. Result: No duplicates, events refreshed

### Scenario 3: ICS Feed Changes UID
1. New UID not found in mappings
2. Property match finds existing event by time + location
3. Validation: Event doesn't have different UID (no mapping exists)
4. New UID mapping created for existing event
5. Result: Event updated with new UID, no duplicate

### Scenario 4: Two Events, Same Time/Location, Different UIDs
1. First event: UID `abc`, time `2026-01-20 15:00`, location `Stadium`
   - Created, UID mapping `abc → event1`
2. Second event: UID `xyz`, time `2026-01-20 15:00`, location `Stadium`
   - Property match finds `event1`
   - Validation: `event1` has UID `abc`, but we need `xyz`
   - Rejection: Different UID detected
   - Result: New event created, no conflict

### Scenario 5: Race Condition (Two Cronjobs)
1. Cronjob A checks UID `abc` - not found
2. Cronjob B checks UID `abc` - not found (same time)
3. Cronjob A: Final pre-create check - still not found
4. Cronjob A: Creates event, UID mapping `abc → event1`
5. Cronjob B: Final pre-create check - **now found!**
6. Cronjob B: Aborts create with race condition warning
7. Result: Only one event created

## Migration from Older Versions

If upgrading from v4.3.1 or earlier:

1. **Existing events without mappings**: Will be found by property matching on next import
2. **Duplicate events**: Run cleanup query to remove true duplicates (review carefully first!)
3. **Orphaned mappings**: Automatically cleaned on next import

### Cleanup Query (Use with Caution!)

```sql
-- Find potential duplicates (same time + location)
SELECT e1.eventID, e1.subject, e1.location, ed1.startTime, COUNT(*) as cnt
FROM calendar1_event e1
JOIN calendar1_event_date ed1 ON e1.eventID = ed1.eventID
JOIN calendar1_event e2 ON e1.location = e2.location
JOIN calendar1_event_date ed2 ON e2.eventID = ed2.eventID 
WHERE ed1.startTime = ed2.startTime
  AND e1.eventID < e2.eventID
GROUP BY e1.eventID, e1.subject, e1.location, ed1.startTime
HAVING cnt > 1;

-- Review results manually before deleting!
```

## Performance Considerations

The enhanced validation adds minimal overhead:
- **UID lookup**: 1 indexed query (very fast)
- **Property matching**: 1-2 indexed queries (only when UID not found)
- **Validation queries**: 1-2 indexed queries per operation
- **Total overhead**: ~5-10ms per event (negligible for typical imports)

## Troubleshooting

### Issue: Duplicates Still Occurring

1. Check database constraints: `SHOW CREATE TABLE calendar1_ical_uid_map`
2. Review logs: `SELECT * FROM wcf1_calendar_import_log WHERE logLevel = 'error' ORDER BY importTime DESC LIMIT 20`
3. Run test script: `php test_duplicate_prevention.php`
4. Enable debug logging and review decision path

### Issue: Events Not Updating

1. Check UID mapping exists: `SELECT * FROM calendar1_ical_uid_map WHERE icalUID = 'your-uid'`
2. Verify event exists: `SELECT * FROM calendar1_event WHERE eventID = X`
3. Review logs for "already mapped to different UID" warnings
4. Check if property matching is rejecting matches

### Issue: Log Shows "Race Condition Prevented"

This is **expected behavior** and not an error. It means:
- Two cronjobs tried to create the same event simultaneously
- The second one detected this and aborted
- No duplicate was created
- Everything worked as designed

## Best Practices

1. **Enable debug logging** during initial setup to verify behavior
2. **Run test script** after each import to catch issues early
3. **Review logs regularly** for warnings or errors
4. **Don't delete UID mappings** manually unless necessary
5. **Back up database** before making manual changes
6. **Use single cronjob** - don't run multiple imports simultaneously if avoidable

## Summary

Version 4.3.2 provides robust duplicate prevention through:
- ✅ Bidirectional UID mapping validation
- ✅ Property match validation with UID conflict detection
- ✅ Race condition prevention with pre-create checks
- ✅ Comprehensive decision tracking and logging
- ✅ Database-level UNIQUE constraints
- ✅ Automatic cleanup of orphaned mappings

These mechanisms work together to ensure that even under adverse conditions (simultaneous imports, UID changes, similar events), no duplicate events are created.
