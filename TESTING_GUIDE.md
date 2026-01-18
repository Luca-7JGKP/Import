# Quick Start Guide - Testing Duplicate Prevention (v4.3.2)

## For End Users

### What Changed?

Version 4.3.2 fixes a critical bug where events could be created multiple times during automatic imports. This update ensures that each event from your ICS feed is only imported once.

### Do I Need to Do Anything?

**For New Installations**: No action needed. Install and configure as usual.

**For Upgrades from v4.3.1 or earlier**:
1. Update the plugin via ACP → Packages
2. Optionally: Run the test script to verify no issues exist
3. Monitor the first few import runs to ensure everything works

### How to Verify It's Working

#### Quick Check (Recommended)

1. Upload `test_duplicate_prevention.php` to your WCF root directory
2. Access it via your browser: `https://your-site.com/test_duplicate_prevention.php`
3. Review the results:
   - ✅ All green checks = everything is fine
   - ⚠️ Yellow warnings = review but likely OK (orphaned mappings are auto-cleaned)
   - ❌ Red errors = review the details and investigate

4. **After testing**: Delete the test script for security

#### Command Line (For Advanced Users)

```bash
cd /path/to/wcf
php test_duplicate_prevention.php
```

### What to Look For

**Good Signs** ✅:
- "No duplicate UID mappings found"
- "No events with multiple UIDs found"
- "No duplicate-related errors or warnings in recent logs"
- Events count matches your expectation

**Warning Signs** ⚠️:
- "Orphaned UID mappings found" (these will be auto-cleaned on next import)
- "Events without UID mapping" (these will be matched and mapped on next import)

**Problem Signs** ❌:
- "Duplicate UIDs found" (should not occur with v4.3.2)
- "Events with multiple UIDs" (should not occur with v4.3.2)
- Error messages in logs about "UID already mapped" or "race condition"

### Testing With Your ICS Feed

1. **Configure Import**:
   - ACP → Kalender → Import → Add/Edit Import
   - Enter your ICS feed URL
   - Select category
   - Save

2. **Run Test Import**:
   - ACP → System → Cronjobs
   - Find "ICS Import (erweitert)"
   - Click "Execute"
   - Wait for completion

3. **Check Results**:
   - Go to your calendar
   - Verify events were imported
   - Note the number of events

4. **Run Again** (Important!):
   - Execute the cronjob again
   - Verify event count stays the same (no duplicates)
   - Check a few events to see they were updated (timestamps change)

5. **Review Logs** (Optional):
   ```sql
   SELECT * FROM wcf1_calendar_import_log 
   WHERE action = 'import_complete' 
   ORDER BY importTime DESC 
   LIMIT 5;
   ```
   
   Look for: "Import: X neu, Y aktualisiert, Z übersprungen"
   - First run: Most events "neu" (new)
   - Second run: Most events "aktualisiert" (updated)
   - Third+ run: All events "aktualisiert" (updated)

### Troubleshooting

#### Issue: Events Still Getting Duplicated

1. Run the test script to diagnose
2. Check database constraint exists:
   ```sql
   SHOW CREATE TABLE calendar1_ical_uid_map;
   ```
   Look for: `UNIQUE KEY 'icalUID'`

3. Enable debug logging (add to `config.inc.php`):
   ```php
   define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
   ```

4. Run import again and check logs:
   ```sql
   SELECT * FROM wcf1_calendar_import_log 
   ORDER BY importTime DESC 
   LIMIT 100;
   ```

5. Look for error patterns or "race condition prevented" messages

#### Issue: Events Not Updating

1. Check if events have UID mappings:
   ```sql
   SELECT COUNT(*) FROM calendar1_ical_uid_map;
   SELECT COUNT(*) FROM calendar1_event;
   ```
   These counts should be similar (or exact match)

2. Check logs for "Event already mapped to different UID" warnings
   - This means the ICS feed changed UIDs for existing events
   - Normal behavior: New event will be created with new UID

3. Verify cronjob is running:
   - ACP → System → Cronjobs
   - Check "Last Execution" timestamp

#### Issue: Test Script Shows Errors

**Orphaned Mappings** (Warning):
- Normal after deleting events manually
- Will be auto-cleaned on next import
- Not a problem

**Duplicate UIDs** (Error):
- Should not happen with v4.3.2
- Contact support with test script results

**Events with Multiple UIDs** (Error):
- Should not happen with v4.3.2
- Contact support with test script results

### Testing with Mainz 05 Feed (Example)

This feed is provided in the issue for testing:
```
http://i.cal.to/ical/1365/mainz05/spielplan/81d83bec.6bb2a14d-e04b249b.ics
```

1. Add as import source in ACP
2. Run cronjob 3 times manually
3. Verify:
   - First run: ~60 events created
   - Second run: 0 new, ~60 updated
   - Third run: 0 new, ~60 updated
   - Total events stays at ~60

### Enabling Debug Logging

For detailed troubleshooting, enable debug logging:

**Edit** `config.inc.php`:
```php
// Add this line at the end
define('CALENDAR_IMPORT_LOG_LEVEL', 'debug');
```

**View logs**:
- Method 1: Check PHP error log
- Method 2: Query database:
  ```sql
  SELECT * FROM wcf1_calendar_import_log 
  ORDER BY importTime DESC 
  LIMIT 50;
  ```

**Disable after testing** (set to 'info' or remove):
```php
define('CALENDAR_IMPORT_LOG_LEVEL', 'info');
```

### Expected Log Patterns

**Healthy Import** (Debug Level):
```
[info] Starte ICS-Import von: http://...
[info] 63 Events in ICS gefunden
[debug] Validated 63 events for duplicates
[debug] Existing event found by UID mapping | uid: abc123... | eventID: 42 | reason: uid_mapping_match
[info] Updating existing event | eventID: 42 | uid: abc123... | oldTitle: Game 1 | newTitle: Game 1
[info] Event updated successfully via API | eventID: 42 | uid: abc123...
[info] Import: 0 neu, 63 aktualisiert, 0 übersprungen
```

**First Import**:
```
[debug] No existing event found, will create new | uid: abc123... | reason: no_match_found
[info] Creating new event | uid: abc123... | title: Game 1 | startTime: 2026-01-20 15:00:00
[info] Event created successfully via API | eventID: 42 | uid: abc123...
```

**Property Match** (Rare):
```
[info] Event matched by startTime + location | eventID: 42 | matchStrategy: time_location_exact
[info] Found existing event by properties, creating UID mapping | uid: abc123... | eventID: 42
```

### Getting Help

1. **Run test script** and save results
2. **Check logs** with debug enabled
3. **Document the issue**:
   - What happened vs what you expected
   - Test script results
   - Relevant log entries
   - ICS feed URL (if shareable)
4. **Review documentation**:
   - `DUPLICATE_PREVENTION.md` - Technical details
   - `IMPLEMENTATION_SUMMARY.md` - Overview
5. **Contact support** with above information

### Best Practices

1. ✅ Run test script after installation/upgrade
2. ✅ Monitor first few imports after upgrade
3. ✅ Check logs regularly (weekly) for errors
4. ✅ Keep plugin updated
5. ❌ Don't delete UID mappings manually (unless you know what you're doing)
6. ❌ Don't run multiple imports simultaneously (should be handled, but avoid)
7. ✅ Back up database before major changes

### Summary

Version 4.3.2 makes the duplicate prevention system robust and bulletproof. The test script helps you verify everything is working correctly. If you see any duplicate events being created with this version, something else is wrong and should be investigated immediately.

**Key Takeaway**: After upgrade, run test script, execute cronjob 2-3 times, and verify event count stays stable. That's it!
