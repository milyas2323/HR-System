<?php
/**
 * Backfill shift_id, slot_date, slot_hour on legacy hourly_updates rows.
 *
 * Rules:
 * - Entries before HOURLY_SLOT_STRICT_START: relaxed (grandfathered) — map by hour if outside :00–:15.
 * - Entries on/after strict start: only in-window submissions get a slot (is_grandfathered=0).
 * - Duplicate rows for same employee + slot → keep earliest, remove later.
 *
 * CLI:
 *   php database/backfill_hourly_slots.php
 *   php database/backfill_hourly_slots.php --dry-run
 *
 * Browser (admin session required):
 *   /hr-system/database/backfill_hourly_slots.php?run=1
 */

$isCli = (PHP_SAPI === 'cli');
$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);

if (!$isCli) {
    session_start();
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        exit('Admin login required.');
    }
    $dryRun = isset($_GET['dry_run']);
    if (!isset($_GET['run']) && !$dryRun) {
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px;">';
        echo '<h2>Backfill hourly slot data</h2>';
        echo '<p>Grandfathers legacy submissions (hour-based) and keeps strict :00–:15 rules for new entries.</p>';
        echo '<p><a href="?dry_run=1">Preview (dry run)</a> &nbsp;|&nbsp; <a href="?run=1" onclick="return confirm(\'Apply backfill to database?\');">Run backfill</a></p>';
        echo '</body></html>';
        exit;
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Find shift that was active when the update was submitted.
 */
function backfillFindShiftForTimestamp($conn, $employeeId, $createdAt) {
    $employeeId = (int) $employeeId;
    $createdAt = mysqli_real_escape_string($conn, $createdAt);

    $result = $conn->query("
        SELECT id, start_time, status, end_time
        FROM shifts
        WHERE employee_id='$employeeId'
        AND start_time <= '$createdAt'
        AND (
            (end_time IS NOT NULL AND end_time >= '$createdAt')
            OR (end_time IS NULL AND status='active')
            OR (end_time IS NULL AND status='closed' AND DATE_ADD(start_time, INTERVAL 12 HOUR) >= '$createdAt')
        )
        ORDER BY start_time DESC
        LIMIT 1
    ");

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    $day = date('Y-m-d', strtotime($createdAt));
    $dayEsc = mysqli_real_escape_string($conn, $day);
    $fallback = $conn->query("
        SELECT id, start_time, status, end_time
        FROM shifts
        WHERE employee_id='$employeeId'
        AND DATE(start_time)='$dayEsc'
        ORDER BY start_time DESC
        LIMIT 1
    ");

    if ($fallback && $fallback->num_rows > 0) {
        return $fallback->fetch_assoc();
    }

    return null;
}

function backfillHourlySlotData($conn, $dryRun = false) {
    $strictStartTs = strtotime(HOURLY_SLOT_STRICT_START);
    $stats = [
        'total_scanned' => 0,
        'already_complete' => 0,
        'backfilled_strict' => 0,
        'backfilled_grandfathered' => 0,
        'late_cleared' => 0,
        'no_shift' => 0,
        'duplicates_removed' => 0,
        'errors' => 0,
        'details' => [],
    ];

    $rows = $conn->query("
        SELECT id, employee_id, shift_id, slot_date, slot_hour, is_grandfathered, created_at, update_text
        FROM hourly_updates
        ORDER BY created_at ASC, id ASC
    ");

    if (!$rows) {
        $stats['errors']++;
        return $stats;
    }

    while ($row = $rows->fetch_assoc()) {
        $stats['total_scanned']++;
        $id = (int) $row['id'];
        $employeeId = (int) $row['employee_id'];
        $createdTs = strtotime($row['created_at']);
        $hasSlot = isHourlyUpdateRowValidForSlot($row);

        if ($hasSlot) {
            $stats['already_complete']++;
            continue;
        }

        $shift = backfillFindShiftForTimestamp($conn, $employeeId, $row['created_at']);

        if (!$shift) {
            $stats['no_shift']++;
            $stats['details'][] = "ID $id: no matching shift — slot left empty.";
            if (!$dryRun) {
                $conn->query("
                    UPDATE hourly_updates
                    SET shift_id=NULL, slot_date=NULL, slot_hour=NULL, is_grandfathered=0
                    WHERE id='$id'
                ");
            }
            continue;
        }

        $shiftId = (int) $shift['id'];
        $isLegacyShift = strtotime($shift['start_time']) < $strictStartTs;
        $slots = getHourlySlotDefinitionsForShift($shift['start_time']);
        $slot = findHourlySlotForTimestamp($slots, $createdTs);
        $grandfathered = 0;

        if (!$slot && $isLegacyShift) {
            $slot = findLenientSlotForTimestamp($shift['start_time'], $createdTs);
            $grandfathered = $slot ? 1 : 0;
        }

        if (!$slot) {
            $stats['late_cleared']++;
            $stats['details'][] = "ID $id: outside slot window" . ($isLegacyShift ? ' (no hour match)' : '') . " — not counted.";
            if (!$dryRun) {
                $conn->query("
                    UPDATE hourly_updates
                    SET shift_id=NULL, slot_date=NULL, slot_hour=NULL, is_grandfathered=0
                    WHERE id='$id'
                ");
            }
            continue;
        }

        $slotDate = mysqli_real_escape_string($conn, $slot['slot_date']);
        $slotHour = (int) $slot['slot_hour'];

        $dupCheck = $conn->query("
            SELECT id FROM hourly_updates
            WHERE employee_id='$employeeId'
            AND slot_date='$slotDate'
            AND slot_hour='$slotHour'
            AND shift_id IS NOT NULL
            AND id != '$id'
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");

        if ($dupCheck && $dupCheck->num_rows > 0) {
            $stats['duplicates_removed']++;
            $stats['details'][] = "ID $id: duplicate slot — removed (earlier row kept).";
            if (!$dryRun) {
                $conn->query("DELETE FROM hourly_updates WHERE id='$id'");
            }
            continue;
        }

        if ($grandfathered) {
            $stats['backfilled_grandfathered']++;
            $stats['details'][] = "ID $id: grandfathered → shift $shiftId slot {$slot['label']}.";
        } else {
            $stats['backfilled_strict']++;
            $stats['details'][] = "ID $id: strict backfill shift $shiftId slot {$slot['label']}.";
        }

        if (!$dryRun) {
            $ok = $conn->query("
                UPDATE hourly_updates
                SET shift_id='$shiftId',
                    slot_date='$slotDate',
                    slot_hour='$slotHour',
                    is_grandfathered='$grandfathered'
                WHERE id='$id'
            ");
            if (!$ok) {
                $stats['errors']++;
                $stats['details'][] = "ID $id: UPDATE failed — " . $conn->error;
            }
        }
    }

    return $stats;
}

$stats = backfillHourlySlotData($conn, $dryRun);

if ($isCli) {
    echo ($dryRun ? "[DRY RUN] " : "") . "Hourly slot backfill complete\n";
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    exit($stats['errors'] > 0 ? 1 : 0);
}

header('Content-Type: text/html; charset=utf-8');
$mode = $dryRun ? 'Dry run (no changes)' : 'Applied';
echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px;max-width:900px;">';
echo '<h2>Backfill hourly slots — ' . htmlspecialchars($mode) . '</h2>';
echo '<ul>';
echo '<li>Scanned: ' . (int) $stats['total_scanned'] . '</li>';
echo '<li>Already complete: ' . (int) $stats['already_complete'] . '</li>';
echo '<li>Backfilled (strict): ' . (int) $stats['backfilled_strict'] . '</li>';
echo '<li>Backfilled (grandfathered): ' . (int) $stats['backfilled_grandfathered'] . '</li>';
echo '<li>Late / no match (not counted): ' . (int) $stats['late_cleared'] . '</li>';
echo '<li>No shift found: ' . (int) $stats['no_shift'] . '</li>';
echo '<li>Duplicates removed: ' . (int) $stats['duplicates_removed'] . '</li>';
echo '<li>Errors: ' . (int) $stats['errors'] . '</li>';
echo '</ul>';
if (!empty($stats['details'])) {
    echo '<h3>Details</h3><pre style="background:#111;color:#eee;padding:12px;overflow:auto;max-height:400px;">';
    echo htmlspecialchars(implode("\n", $stats['details']));
    echo '</pre>';
}
echo '<p><a href="backfill_hourly_slots.php">Back</a></p>';
echo '</body></html>';
