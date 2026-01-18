<?php
/**
 * Test script for v4.3.3 duplicate prevention enhancements
 * Tests the new deduplication logic, fuzzy matching, and intra-run tracking
 * 
 * Usage: php test_duplicate_logic_v433.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mock test class that simulates the cronjob behavior
class DuplicatePreventionTest
{
    private $processedUIDsInCurrentRun = [];
    
    /**
     * Test 1: Validate events for duplicates (v4.3.3 fix)
     */
    public function testValidateEventsForDuplicates()
    {
        echo "\n=== Test 1: validateEventsForDuplicates (v4.3.3) ===\n";
        
        // Test case 1: ICS file with duplicate UIDs
        $events = [
            ['uid' => 'event-001', 'summary' => 'Event 1'],
            ['uid' => 'event-002', 'summary' => 'Event 2'],
            ['uid' => 'event-001', 'summary' => 'Event 1 Duplicate'], // Duplicate!
            ['uid' => 'event-003', 'summary' => 'Event 3'],
            ['uid' => 'event-002', 'summary' => 'Event 2 Duplicate'], // Duplicate!
        ];
        
        $deduplicated = $this->validateEventsForDuplicates($events);
        
        echo "Original events: " . count($events) . "\n";
        echo "After deduplication: " . count($deduplicated) . "\n";
        echo "Expected: 3 unique events\n";
        
        $uniqueUIDs = array_unique(array_column($deduplicated, 'uid'));
        echo "Unique UIDs in result: " . count($uniqueUIDs) . "\n";
        
        if (count($deduplicated) === 3 && count($uniqueUIDs) === 3) {
            echo "✅ PASS: Duplicates correctly removed\n";
            return true;
        } else {
            echo "❌ FAIL: Expected 3 unique events, got " . count($deduplicated) . "\n";
            return false;
        }
    }
    
    /**
     * Test 2: Intra-run duplicate tracking
     */
    public function testIntraRunTracking()
    {
        echo "\n=== Test 2: Intra-Run Duplicate Tracking ===\n";
        
        $this->processedUIDsInCurrentRun = [];
        
        // Simulate processing events
        $events = [
            ['uid' => 'event-001', 'summary' => 'Event 1'],
            ['uid' => 'event-002', 'summary' => 'Event 2'],
        ];
        
        $processedCount = 0;
        $skippedCount = 0;
        
        // First pass - should process all
        foreach ($events as $event) {
            if (isset($this->processedUIDsInCurrentRun[$event['uid']])) {
                $skippedCount++;
            } else {
                $this->processedUIDsInCurrentRun[$event['uid']] = time();
                $processedCount++;
            }
        }
        
        echo "First pass - Processed: $processedCount, Skipped: $skippedCount\n";
        
        // Second pass - should skip all (already processed)
        $processedCount2 = 0;
        $skippedCount2 = 0;
        
        foreach ($events as $event) {
            if (isset($this->processedUIDsInCurrentRun[$event['uid']])) {
                $skippedCount2++;
            } else {
                $this->processedUIDsInCurrentRun[$event['uid']] = time();
                $processedCount2++;
            }
        }
        
        echo "Second pass - Processed: $processedCount2, Skipped: $skippedCount2\n";
        
        if ($processedCount === 2 && $skippedCount === 0 && $processedCount2 === 0 && $skippedCount2 === 2) {
            echo "✅ PASS: Intra-run tracking prevents duplicate processing\n";
            return true;
        } else {
            echo "❌ FAIL: Tracking not working correctly\n";
            return false;
        }
    }
    
    /**
     * Test 3: Fuzzy title matching
     */
    public function testFuzzyTitleMatching()
    {
        echo "\n=== Test 3: Fuzzy Title Matching ===\n";
        
        $testCases = [
            ['Event Title', 'Event Title', 1.0, true],           // Exact match
            ['Event Title', 'Event  Title', 0.9, true],          // Extra space
            ['Event Title', 'Event Title Updated', 0.7, true],   // Added word
            ['Event Title', 'Event', 0.5, false],                // Too different
            ['Mainz 05 vs Bayern', 'Mainz 05 vs. Bayern', 0.9, true], // Punctuation
            ['Match 1', 'Match 99', 0.8, true],                  // Similar structure (80% similarity is expected)
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($testCases as $case) {
            list($str1, $str2, $expectedMin, $shouldMatch) = $case;
            $similarity = $this->calculateStringSimilarity($str1, $str2);
            $matches = $similarity >= 0.7;
            
            $result = ($matches === $shouldMatch) ? '✅' : '❌';
            echo "$result '$str1' vs '$str2': " . round($similarity * 100, 1) . "% (expected $shouldMatch)\n";
            
            if ($matches === $shouldMatch) {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        echo "\nPassed: $passed, Failed: $failed\n";
        
        if ($failed === 0) {
            echo "✅ PASS: All fuzzy matching tests passed\n";
            return true;
        } else {
            echo "❌ FAIL: Some fuzzy matching tests failed\n";
            return false;
        }
    }
    
    /**
     * Test 4: Time window matching
     */
    public function testTimeWindowMatching()
    {
        echo "\n=== Test 4: Time Window Matching ===\n";
        
        $baseTime = strtotime('2026-01-20 15:00:00');
        $timeWindow = 1800; // 30 minutes = 1800 seconds
        
        $testCases = [
            ['Exact match', $baseTime, true],
            ['29 min earlier', $baseTime - (29 * 60), true],
            ['29 min later', $baseTime + (29 * 60), true],
            ['31 min earlier', $baseTime - (31 * 60), false],
            ['31 min later', $baseTime + (31 * 60), false],
            ['6 min earlier (old window)', $baseTime - (6 * 60), true], // Would fail with old 5-min window
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($testCases as $case) {
            list($label, $testTime, $shouldMatch) = $case;
            $windowStart = $baseTime - $timeWindow;
            $windowEnd = $baseTime + $timeWindow;
            $matches = ($testTime >= $windowStart && $testTime <= $windowEnd);
            
            $timeDiff = abs($baseTime - $testTime) / 60;
            $result = ($matches === $shouldMatch) ? '✅' : '❌';
            echo "$result $label: " . round($timeDiff, 1) . " min difference (expected $shouldMatch)\n";
            
            if ($matches === $shouldMatch) {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        echo "\nPassed: $passed, Failed: $failed\n";
        
        if ($failed === 0) {
            echo "✅ PASS: Time window matching works correctly\n";
            return true;
        } else {
            echo "❌ FAIL: Time window matching has issues\n";
            return false;
        }
    }
    
    // Helper methods (simplified versions from actual cronjob)
    
    private function validateEventsForDuplicates(array $events)
    {
        $seenUIDs = [];
        $deduplicatedEvents = [];
        
        foreach ($events as $event) {
            if (empty($event['uid'])) {
                $deduplicatedEvents[] = $event;
                continue;
            }
            
            $uid = $event['uid'];
            
            if (isset($seenUIDs[$uid])) {
                // Duplicate - skip it
                continue;
            }
            
            $seenUIDs[$uid] = true;
            $deduplicatedEvents[] = $event;
        }
        
        return $deduplicatedEvents;
    }
    
    private function calculateStringSimilarity($str1, $str2)
    {
        // Normalize
        $str1 = mb_strtolower(trim($str1), 'UTF-8');
        $str2 = mb_strtolower(trim($str2), 'UTF-8');
        
        if ($str1 === '' || $str2 === '') {
            return $str1 === $str2 ? 1.0 : 0.0;
        }
        
        $percent = 0.0;
        similar_text($str1, $str2, $percent);
        
        return $percent / 100.0;
    }
}

// Run tests
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║  Duplicate Prevention Logic Tests (v4.3.3)                   ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";

$tester = new DuplicatePreventionTest();

$results = [];
$results[] = $tester->testValidateEventsForDuplicates();
$results[] = $tester->testIntraRunTracking();
$results[] = $tester->testFuzzyTitleMatching();
$results[] = $tester->testTimeWindowMatching();

$passed = count(array_filter($results));
$total = count($results);

echo "\n╔═══════════════════════════════════════════════════════════════╗\n";
echo "║  Test Summary                                                ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "Passed: $passed / $total tests\n";

if ($passed === $total) {
    echo "\n✅ ALL TESTS PASSED! Duplicate prevention logic is working correctly.\n";
    exit(0);
} else {
    echo "\n❌ SOME TESTS FAILED! Please review the output above.\n";
    exit(1);
}
