<?php
/**
 * Admin → Activity Tracker.
 *
 * Reports the desktop agent's data with SHIFT-ACCURATE grouping. The evening
 * shift runs ~6 PM–3 AM and crosses midnight, so calendar-date grouping would
 * split one work-night across two days. We instead:
 *   - group the Shift Report by the actual shift (clock-in → clock-out), and
 *   - treat a "work day" as a 6 AM→6 AM window (offset 6h), so a 6 PM–3 AM night
 *     is attributed to the date it STARTED.
 *
 * Rendered via admin/dashboard.php?page=tracker.
 */

include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";
include_once "../includes/tracker_schema.php";
ensureTrackerSchema($conn);
maybePurgeOldTrackerScreenshots($conn);

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    exit("Access Denied");
}

// Fallbacks so the page works even on a server whose functions.php predates these helpers.
if (!function_exists('formatWorkDuration')) {
    function formatWorkDuration($seconds) {
        $seconds = max(0, (int) $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $h . 'h ' . $m . 'm';
    }
}
$hasShiftSummary = function_exists('getShiftWorkSummary');
if (!function_exists('getDatabaseNowTimestamp')) {
    function getDatabaseNowTimestamp($conn) {
        $r = $conn->query("SELECT UNIX_TIMESTAMP() AS ts");
        $row = $r ? $r->fetch_assoc() : null;
        return $row ? (int) $row['ts'] : time();
    }
}

// Work-day window: 6 PM–3 AM night is attributed to the day it started.
// Boundary at 6 AM => subtract 6h before taking the date.
define('TRK_WORKDAY_OFFSET_HOURS', 6);
$WD_SHIFT = 'INTERVAL ' . TRK_WORKDAY_OFFSET_HOURS . ' HOUR';

$dbNowTs = getDatabaseNowTimestamp($conn);
$todayWork = date('Y-m-d', $dbNowTs - TRK_WORKDAY_OFFSET_HOURS * 3600); // current work-day

// ---- Filters ----
function trkValidDate($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string) $d);
    return $dt && $dt->format('Y-m-d') === $d;
}
$fromInput = trim($_GET['t_from'] ?? '');
$toInput   = trim($_GET['t_to'] ?? '');
// Back-compat: a single ?t_date= still works.
if ($fromInput === '' && $toInput === '' && !empty($_GET['t_date']) && trkValidDate($_GET['t_date'])) {
    $fromInput = $toInput = $_GET['t_date'];
}
$from = trkValidDate($fromInput) ? $fromInput : $todayWork;
$to   = trkValidDate($toInput) ? $toInput : $todayWork;
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
$fromSql = mysqli_real_escape_string($conn, $from);
$toSql   = mysqli_real_escape_string($conn, $to);

$empFilter = (int) ($_GET['t_emp'] ?? 0);
$empClauseActivity = $empFilter > 0 ? " AND ta.employee_id = $empFilter " : '';
$empClauseShift    = $empFilter > 0 ? " AND s.employee_id = $empFilter " : '';
$empClauseShot     = $empFilter > 0 ? " AND ts.employee_id = $empFilter " : '';

$isSingleToday = ($from === $todayWork && $to === $todayWork);
$rangeIncludesToday = ($to >= $todayWork);

$employeeOptions = [];
$res = $conn->query("SELECT id, name FROM users WHERE role='employee' ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) { $employeeOptions[(int) $r['id']] = $r['name']; } }

// ---- Thresholds ----
$ACTIVE_WINDOW = 150;   // seconds — activity newer than this == live
$IDLE_THRESHOLD = 300;  // seconds — away

// ---- Live "Right Now" board (only when the range includes the current work-day) ----
$liveRows = [];
if ($rangeIncludesToday) {
    $liveSql = "
        SELECT u.id, u.name,
            s.id AS open_shift_id,
            s.current_location AS shift_location,
            (SELECT ta.window_start FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_window,
            (SELECT ta.is_idle FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_idle,
            (SELECT ta.max_idle_seconds FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_max_idle,
            (SELECT MAX(t.last_used_at) FROM api_tokens t WHERE t.user_id = u.id AND t.revoked = 0) AS agent_seen,
            (SELECT t2.last_location FROM api_tokens t2 WHERE t2.user_id = u.id AND t2.revoked = 0 AND t2.last_location IS NOT NULL ORDER BY t2.last_used_at DESC LIMIT 1) AS last_location
        FROM users u
        LEFT JOIN shifts s ON s.employee_id = u.id AND s.status = 'active'
        WHERE u.role = 'employee' " . ($empFilter > 0 ? " AND u.id = $empFilter " : '') . "
        ORDER BY u.name ASC
    ";
    $res = $conn->query($liveSql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $lastWindowTs = !empty($r['last_window']) ? strtotime($r['last_window']) : 0;
            $age = $lastWindowTs ? ($dbNowTs - $lastWindowTs) : PHP_INT_MAX;
            if ($age <= $ACTIVE_WINDOW && (int) $r['last_idle'] === 0) { $r['live_status'] = 'active'; }
            elseif ($age <= $ACTIVE_WINDOW) { $r['live_status'] = 'idle'; }
            elseif ($age <= $IDLE_THRESHOLD) { $r['live_status'] = 'away'; }
            else { $r['live_status'] = 'offline'; }
            $liveRows[] = $r;
        }
    }
}

// ---- Shift Report: one row per shift (accurate clock-in → clock-out) ----
$shiftRows = [];
$shiftIds = [];
$sql = "
    SELECT s.*, u.name
    FROM shifts s
    JOIN users u ON u.id = s.employee_id
    WHERE DATE(s.start_time - $WD_SHIFT) BETWEEN '$fromSql' AND '$toSql'
    $empClauseShift
    ORDER BY s.start_time DESC
";
$res = $conn->query($sql);
if ($res) { while ($row = $res->fetch_assoc()) { $shiftRows[] = $row; $shiftIds[] = (int) $row['id']; } }

// Aggregate active/idle + screenshots per shift in one pass each.
$shiftActivity = [];
$shiftShots = [];
if (!empty($shiftIds)) {
    $idList = implode(',', array_map('intval', $shiftIds));
    $res = $conn->query("SELECT shift_id, SUM(active_seconds) a, SUM(idle_seconds) i FROM tracker_activity WHERE shift_id IN ($idList) GROUP BY shift_id");
    if ($res) { while ($r = $res->fetch_assoc()) { $shiftActivity[(int) $r['shift_id']] = $r; } }
    $res = $conn->query("SELECT shift_id, COUNT(*) c FROM tracker_screenshots WHERE shift_id IN ($idList) GROUP BY shift_id");
    if ($res) { while ($r = $res->fetch_assoc()) { $shiftShots[(int) $r['shift_id']] = (int) $r['c']; } }
}

// ---- Per-employee summary over the range (work-day grouped) ----
$summary = [];
$res = $conn->query("
    SELECT ta.employee_id, u.name,
        SUM(CASE WHEN ta.shift_id > 0 THEN ta.active_seconds ELSE 0 END) AS onshift_active_sec,
        SUM(ta.active_seconds) AS active_sec,
        SUM(ta.idle_seconds)   AS idle_sec,
        COUNT(DISTINCT CASE WHEN ta.shift_id > 0 THEN ta.shift_id END) AS shift_count,
        MAX(ta.max_idle_seconds) AS max_idle
    FROM tracker_activity ta
    JOIN users u ON u.id = ta.employee_id
    WHERE DATE(ta.window_start - $WD_SHIFT) BETWEEN '$fromSql' AND '$toSql'
    $empClauseActivity
    GROUP BY ta.employee_id, u.name
    ORDER BY onshift_active_sec DESC
");
if ($res) { while ($r = $res->fetch_assoc()) { $summary[(int) $r['employee_id']] = $r; } }

$totActive = 0; $totIdle = 0; $totOnshift = 0;
foreach ($summary as $s) {
    $totActive += (int) $s['active_sec'];
    $totIdle += (int) $s['idle_sec'];
    $totOnshift += (int) $s['onshift_active_sec'];
}

// ---- Screenshots (range + employee) ----
$shots = $conn->query("
    SELECT ts.*, u.name
    FROM tracker_screenshots ts
    JOIN users u ON u.id = ts.employee_id
    WHERE DATE(ts.captured_at - $WD_SHIFT) BETWEEN '$fromSql' AND '$toSql'
    $empClauseShot
    ORDER BY ts.captured_at DESC
    LIMIT 300
");

function trkLiveBadge($status) {
    switch ($status) {
        case 'active': return ['Active', 'var(--success)'];
        case 'idle':   return ['Idle', 'var(--warning)'];
        case 'away':   return ['Away', 'var(--warning)'];
        default:       return ['Offline', 'var(--text-muted)'];
    }
}
function trkIdlePct($active, $idle) {
    $tot = $active + $idle;
    return $tot > 0 ? round($idle * 100 / $tot) : 0;
}
function trkRangeLabel($from, $to) {
    return $from === $to
        ? date('d M Y', strtotime($from))
        : date('d M', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
}
?>

<div class="page-title">Activity Tracker</div>

<!-- FILTERS -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="tracker">
        <div style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
            <div>
                <label style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; display:block;">From (work-day)</label>
                <input type="date" name="t_from" value="<?php echo htmlspecialchars($from); ?>" style="max-width:180px;">
            </div>
            <div>
                <label style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; display:block;">To (work-day)</label>
                <input type="date" name="t_to" value="<?php echo htmlspecialchars($to); ?>" style="max-width:180px;">
            </div>
            <div>
                <label style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; display:block;">Employee</label>
                <select name="t_emp" style="min-width:190px; padding:10px; border-radius:8px;">
                    <option value="0">All employees</option>
                    <?php foreach ($employeeOptions as $eid => $ename) { ?>
                        <option value="<?php echo $eid; ?>" <?php echo $empFilter === $eid ? 'selected' : ''; ?>><?php echo htmlspecialchars($ename); ?></option>
                    <?php } ?>
                </select>
            </div>
            <button type="submit" class="btn glowing-element" style="margin-bottom:0;">Apply</button>
            <a href="dashboard.php?page=tracker" class="btn-secondary" style="display:inline-flex; align-items:center; padding:10px 16px; text-decoration:none;">Tonight</a>
        </div>
    </form>
    <div class="alert info" style="margin-top:16px; margin-bottom:0;">
        <span>ℹ️</span>
        <span>
            A <strong>work-day</strong> is the <strong>6 PM–3 AM</strong> night (grouped by the day it started, so a shift crossing midnight stays together).
            <strong>Active</strong> = keyboard &amp; mouse. <strong>Dedicated Working Hrs</strong> = active time during an open shift.
        </span>
    </div>
</div>

<!-- SUMMARY -->
<div class="grid">
    <div class="card stat-box">
        <h4>Tracked Employees</h4>
        <h2><?php echo count($summary); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--success);">
        <h4>Dedicated Working Hrs <span style="font-weight:normal; color:var(--text-muted); font-size:0.7rem;">(on-shift)</span></h4>
        <h2 style="color:var(--success);"><?php echo htmlspecialchars(formatWorkDuration($totOnshift)); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--accent);">
        <h4>Total Active <span style="font-weight:normal; color:var(--text-muted); font-size:0.7rem;">(incl. off-shift)</span></h4>
        <h2 style="color:var(--accent);"><?php echo htmlspecialchars(formatWorkDuration($totActive)); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--warning);">
        <h4>Total Idle</h4>
        <h2 style="color:var(--warning);"><?php echo htmlspecialchars(formatWorkDuration($totIdle)); ?></h2>
    </div>
</div>

<?php if ($rangeIncludesToday) { ?>
<!-- LIVE BOARD -->
<div class="card">
    <h2>Right Now <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">(auto-refreshes)</span></h2>
    <div class="grid" style="margin-top:12px;">
        <?php if (empty($liveRows)) { ?>
            <p style="color:var(--text-muted);">No employees found.</p>
        <?php } foreach ($liveRows as $r) {
            list($label, $color) = trkLiveBadge($r['live_status']);
            $seen = !empty($r['agent_seen']) ? date('h:i A', strtotime($r['agent_seen'])) : 'never';
            $shiftLoc = trim((string) ($r['shift_location'] ?? ''));
            if ($shiftLoc !== '' && stripos($shiftLoc, 'unknown') === false) { $locText = explode(',', $shiftLoc)[0]; $locSrc = 'check-in GPS'; }
            elseif (!empty($r['last_location'])) { $locText = $r['last_location']; $locSrc = 'approx. by IP'; }
            else { $locText = 'Locating…'; $locSrc = ''; }
        ?>
            <div class="card" style="border-left:4px solid <?php echo $color; ?>; margin:0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                    <span class="badge" style="background:<?php echo $color; ?>; color:#fff;"><?php echo $label; ?></span>
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:8px; line-height:1.6;">
                    <div>Shift: <?php echo $r['open_shift_id'] ? '🟢 open' : '— none'; ?></div>
                    <div>📍 <?php echo htmlspecialchars($locText); ?><?php if ($locSrc) { ?> <span style="font-size:0.68rem; opacity:0.7;">(<?php echo $locSrc; ?>)</span><?php } ?></div>
                    <div>Agent last seen: <?php echo htmlspecialchars($seen); ?></div>
                    <?php if (in_array($r['live_status'], ['idle','away'], true) && !empty($r['last_max_idle'])) { ?>
                        <div>Idle for ~<?php echo htmlspecialchars(formatWorkDuration((int) $r['last_max_idle'])); ?></div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<script>setTimeout(function(){ location.reload(); }, 30000);</script>
<?php } ?>

<!-- SHIFT REPORT (accurate clock-in → clock-out, cross-midnight safe) -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
        <h2>Shift Report <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">— <?php echo htmlspecialchars(trkRangeLabel($from, $to)); ?> (6 PM–3 AM work-days)</span></h2>
        <a href="tracker_export.php?t_from=<?php echo urlencode($from); ?>&amp;t_to=<?php echo urlencode($to); ?>&amp;t_emp=<?php echo $empFilter; ?>" class="btn-secondary" style="display:inline-flex; align-items:center; padding:9px 14px; text-decoration:none;">⬇️ Export CSV</a>
    </div>
    <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:16px;">
        One row per shift. Hours are counted against the actual <strong>clock-in → clock-out</strong>, so a shift that runs past midnight is a single accurate entry.
    </p>
    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Work Night</th>
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Shift Span</th>
                <th>Dedicated Active</th>
                <th>Idle</th>
                <th>Idle %</th>
                <th>Shots</th>
                <th>Timeline</th>
            </tr>
            <?php if (empty($shiftRows)) { ?>
                <tr><td colspan="10" style="text-align:center; color:var(--text-muted); padding:30px;">No shifts in this range.</td></tr>
            <?php } else {
                foreach ($shiftRows as $s) {
                    $sid = (int) $s['id'];
                    $act = isset($shiftActivity[$sid]) ? (int) $shiftActivity[$sid]['a'] : 0;
                    $idl = isset($shiftActivity[$sid]) ? (int) $shiftActivity[$sid]['i'] : 0;
                    $shotC = $shiftShots[$sid] ?? 0;
                    $workNight = date('d M Y', strtotime($s['start_time'] . ' -' . TRK_WORKDAY_OFFSET_HOURS . ' hours'));
                    $span = $hasShiftSummary ? (int) getShiftWorkSummary($s, $dbNowTs)['worked_seconds'] : 0;
                    $open = strtolower((string) $s['status']) === 'active';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                    <td style="font-size:0.85rem;"><?php echo htmlspecialchars($workNight); ?></td>
                    <td style="font-size:0.85rem; color:var(--success);"><?php echo date('h:i A', strtotime($s['start_time'])); ?><div style="color:var(--text-muted); font-size:0.7rem;"><?php echo date('d M', strtotime($s['start_time'])); ?></div></td>
                    <td style="font-size:0.85rem; color:var(--danger);">
                        <?php if (!empty($s['end_time'])) { ?>
                            <?php echo date('h:i A', strtotime($s['end_time'])); ?><div style="color:var(--text-muted); font-size:0.7rem;"><?php echo date('d M', strtotime($s['end_time'])); ?></div>
                        <?php } else { ?><span class="badge warning" style="font-size:0.65rem;">Running</span><?php } ?>
                    </td>
                    <td style="font-size:0.85rem; color:var(--text-muted);"><?php echo $hasShiftSummary ? htmlspecialchars(formatWorkDuration($span)) : '—'; ?></td>
                    <td style="color:var(--success); font-weight:700;"><?php echo htmlspecialchars(formatWorkDuration($act)); ?></td>
                    <td style="color:var(--warning);"><?php echo htmlspecialchars(formatWorkDuration($idl)); ?></td>
                    <td style="font-size:0.85rem; color:<?php echo trkIdlePct($act,$idl) >= 40 ? 'var(--danger)' : 'var(--text-muted)'; ?>;"><?php echo trkIdlePct($act, $idl); ?>%</td>
                    <td>
                        <?php if ($shotC > 0) { ?>
                            <a href="dashboard.php?page=tracker&amp;t_from=<?php echo urlencode($from); ?>&amp;t_to=<?php echo urlencode($to); ?>&amp;t_emp=<?php echo (int) $s['employee_id']; ?>#shots" style="color:var(--accent);"><?php echo $shotC; ?> 📷</a>
                        <?php } else { ?><span style="color:var(--text-muted);">0</span><?php } ?>
                    </td>
                    <td>
                        <button type="button" class="trk-timeline"
                                data-shift="<?php echo $sid; ?>"
                                data-name="<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>"
                                style="padding:5px 10px; font-size:0.72rem; border:0; border-radius:6px; background:var(--primary, #4f46e5); color:#fff; cursor:pointer;">📊 View</button>
                    </td>
                </tr>
            <?php }
            } ?>
        </table>
    </div>
</div>

<!-- PER-EMPLOYEE SUMMARY -->
<div class="card">
    <h2>Per-Employee Summary <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">— <?php echo htmlspecialchars(trkRangeLabel($from, $to)); ?></span></h2>
    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Shifts</th>
                <th>Dedicated Working (on-shift)</th>
                <th>Total Active (all)</th>
                <th>Idle</th>
                <th>Idle %</th>
                <th>Longest Idle Gap</th>
            </tr>
            <?php if (empty($summary)) { ?>
                <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:30px;">No activity in this range.</td></tr>
            <?php } else {
                foreach ($summary as $eid => $s) {
                    $active = (int) $s['active_sec'];
                    $onshift = (int) $s['onshift_active_sec'];
                    $idle = (int) $s['idle_sec'];
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                    <td><?php echo (int) $s['shift_count']; ?></td>
                    <td style="color:var(--success); font-weight:700;"><?php echo htmlspecialchars(formatWorkDuration($onshift)); ?></td>
                    <td style="color:var(--accent);"><?php echo htmlspecialchars(formatWorkDuration($active)); ?></td>
                    <td style="color:var(--warning);"><?php echo htmlspecialchars(formatWorkDuration($idle)); ?></td>
                    <td style="color:<?php echo trkIdlePct($active,$idle) >= 40 ? 'var(--danger)' : 'var(--text-muted)'; ?>;"><?php echo trkIdlePct($active, $idle); ?>%</td>
                    <td><?php echo htmlspecialchars(formatWorkDuration((int) $s['max_idle'])); ?></td>
                </tr>
            <?php }
            } ?>
        </table>
    </div>
</div>

<!-- IDLE RANKING -->
<?php
$ranking = [];
foreach ($summary as $eid => $s) {
    $a = (int) $s['active_sec']; $i = (int) $s['idle_sec']; $tot = $a + $i;
    if ($tot <= 0) { continue; }
    $ranking[] = ['name' => $s['name'], 'pct' => (int) round($i * 100 / $tot), 'active' => $a, 'idle' => $i];
}
usort($ranking, function ($x, $y) { return $y['pct'] <=> $x['pct']; });
if (!empty($ranking)) { ?>
<div class="card">
    <h2>Idle Ranking <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">— most idle first</span></h2>
    <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:16px;">Green = active share, amber = idle share of tracked time in the range.</p>
    <?php foreach ($ranking as $row) { ?>
        <div style="margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; font-size:0.85rem; margin-bottom:5px; flex-wrap:wrap; gap:4px;">
                <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                <span style="color:<?php echo $row['pct'] >= 40 ? 'var(--danger)' : 'var(--text-muted)'; ?>;">
                    <?php echo $row['pct']; ?>% idle · <?php echo htmlspecialchars(formatWorkDuration($row['active'])); ?> active / <?php echo htmlspecialchars(formatWorkDuration($row['idle'])); ?> idle
                </span>
            </div>
            <div style="height:10px; border-radius:5px; background:var(--success); overflow:hidden;">
                <div style="height:100%; width:<?php echo $row['pct']; ?>%; background:var(--warning); float:right;"></div>
            </div>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- SCREENSHOTS -->
<div class="card" id="shots">
    <h2>Screenshots <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">— <?php echo htmlspecialchars(trkRangeLabel($from, $to)); ?></span></h2>
    <p style="color:var(--text-muted); font-size:0.8rem; margin:-4px 0 14px;">
        Kept for the last <strong><?php echo (int) TRACKER_SCREENSHOT_RETENTION_DAYS; ?> days</strong>; older ones are deleted automatically. Click any image to open; use ← → to browse.
    </p>

    <div style="display:flex; flex-wrap:wrap; gap:14px;">
        <?php if ($shots && $shots->num_rows > 0) {
            while ($shot = $shots->fetch_assoc()) {
                $url = '../' . ltrim($shot['file'], '/');
        ?>
            <div class="trk-card" style="width:220px; position:relative; border:1px solid #27324a; border-radius:10px; overflow:hidden; background:var(--card, #1e293b);">
                <button type="button" class="trk-del" data-id="<?php echo (int) $shot['id']; ?>" title="Delete this screenshot"
                        style="position:absolute; top:6px; right:6px; z-index:2; width:28px; height:28px; padding:0; border:0; border-radius:6px; background:rgba(220,38,38,0.92); color:#fff; cursor:pointer; font-size:14px; line-height:28px; text-align:center;">🗑</button>
                <img src="<?php echo htmlspecialchars($url); ?>" alt="screenshot"
                     class="trk-shot"
                     data-full="<?php echo htmlspecialchars($url); ?>"
                     data-name="<?php echo htmlspecialchars($shot['name'], ENT_QUOTES); ?>"
                     data-time="<?php echo date('d M Y, h:i:s A', strtotime($shot['captured_at'])); ?>"
                     style="width:100%; height:130px; object-fit:cover; display:block; cursor:zoom-in;">
                <div style="padding:8px 10px; font-size:0.78rem;">
                    <div><strong><?php echo htmlspecialchars($shot['name']); ?></strong></div>
                    <div style="color:var(--text-muted);"><?php echo date('d M, h:i:s A', strtotime($shot['captured_at'])); ?></div>
                    <?php if ((int) $shot['is_idle'] === 1) { ?>
                        <span class="badge warning" style="font-size:0.65rem;">Idle <?php echo htmlspecialchars(formatWorkDuration((int) $shot['idle_seconds'])); ?></span>
                    <?php } else { ?>
                        <span class="badge success" style="font-size:0.65rem;">Active</span>
                    <?php } ?>
                </div>
            </div>
        <?php }
        } else { ?>
            <p style="color:var(--text-muted);">No screenshots in this range<?php echo $empFilter ? ' for this employee' : ''; ?>.</p>
        <?php } ?>
    </div>
</div>

<!-- SCREENSHOT LIGHTBOX (with prev/next navigation) -->
<style>
    #trkShotModal { position: fixed; inset: 0; z-index: 10000; background: rgba(2,6,23,0.92);
        display: none; flex-direction: column; align-items: center; justify-content: center; padding: 24px; }
    #trkShotModal.open { display: flex; }
    #trkShotModal .trk-bar { width: 92%; max-width: 1100px; display: flex; justify-content: space-between; align-items: center;
        color: #e2e8f0; margin-bottom: 12px; font-size: 0.9rem; }
    #trkShotModal .trk-close { cursor: pointer; font-size: 1.9rem; line-height: 1; color: #e2e8f0; user-select: none; }
    #trkShotModal .trk-stage { display: flex; align-items: center; gap: 14px; max-width: 96%; }
    #trkShotModal img { max-width: 86vw; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 16px 50px rgba(0,0,0,0.6); background: #000; }
    #trkShotModal .trk-nav { width: 46px; height: 46px; border:0; border-radius: 50%; background: rgba(255,255,255,0.12); color:#fff; font-size: 1.4rem; cursor: pointer; flex: 0 0 auto; }
    #trkShotModal .trk-nav:hover { background: rgba(255,255,255,0.25); }
    #trkShotModal .trk-nav:disabled { opacity: 0.25; cursor: default; }
    #trkShotModal .trk-hint { color: #94a3b8; font-size: 0.75rem; margin-top: 12px; }
</style>
<div id="trkShotModal">
    <div class="trk-bar">
        <span id="trkShotCaption"></span>
        <span class="trk-close" id="trkShotClose" title="Close">&times;</span>
    </div>
    <div class="trk-stage">
        <button class="trk-nav" id="trkPrev" title="Previous (←)">&#8249;</button>
        <img id="trkShotImg" src="" alt="screenshot preview">
        <button class="trk-nav" id="trkNext" title="Next (→)">&#8250;</button>
    </div>
    <div class="trk-hint">← → to browse · click outside or Esc to close</div>
</div>

<script>
(function () {
    var modal = document.getElementById('trkShotModal');
    var img = document.getElementById('trkShotImg');
    var cap = document.getElementById('trkShotCaption');
    var prevBtn = document.getElementById('trkPrev');
    var nextBtn = document.getElementById('trkNext');
    if (!modal) return;

    var shots = [];   // current snapshot of gallery images
    var idx = -1;

    function rebuild() {
        shots = Array.prototype.slice.call(document.querySelectorAll('.trk-shot'));
    }
    function show(i) {
        if (i < 0 || i >= shots.length) return;
        idx = i;
        var el = shots[i];
        img.src = el.getAttribute('data-full');
        cap.textContent = (el.getAttribute('data-name') || '') + '  ·  ' + (el.getAttribute('data-time') || '')
            + '   (' + (i + 1) + ' / ' + shots.length + ')';
        prevBtn.disabled = (i <= 0);
        nextBtn.disabled = (i >= shots.length - 1);
    }
    function openAt(el) { rebuild(); show(shots.indexOf(el)); modal.classList.add('open'); }
    function close() { modal.classList.remove('open'); img.src = ''; }

    document.querySelectorAll('.trk-shot').forEach(function (el) {
        el.addEventListener('click', function () { openAt(el); });
    });
    prevBtn.addEventListener('click', function (e) { e.stopPropagation(); show(idx - 1); });
    nextBtn.addEventListener('click', function (e) { e.stopPropagation(); show(idx + 1); });
    document.getElementById('trkShotClose').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal || e.target.className === 'trk-stage') close(); });
    document.addEventListener('keydown', function (e) {
        if (!modal.classList.contains('open')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') show(idx - 1);
        else if (e.key === 'ArrowRight') show(idx + 1);
    });

    // Inline delete (no reload).
    document.querySelectorAll('.trk-del').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!confirm('Delete this screenshot permanently?')) return;
            var id = btn.getAttribute('data-id');
            btn.disabled = true; btn.textContent = '…';
            fetch('tracker_delete.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + encodeURIComponent(id) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) {
                    var card = btn.closest('.trk-card');
                    if (card) { card.style.transition = 'opacity .2s'; card.style.opacity = '0'; setTimeout(function () { card.remove(); rebuild(); }, 200); }
                } else { alert('Could not delete: ' + ((d && d.error) || 'error')); btn.disabled = false; btn.textContent = '🗑'; }
            })
            .catch(function () { alert('Network error while deleting.'); btn.disabled = false; btn.textContent = '🗑'; });
        });
    });
})();
</script>

<!-- SHIFT TIMELINE DRILLDOWN -->
<style>
    #trkTlModal { position: fixed; inset: 0; z-index: 10000; background: rgba(2,6,23,0.92);
        display: none; align-items: center; justify-content: center; padding: 24px; }
    #trkTlModal.open { display: flex; }
    #trkTlBox { width: min(1050px, 95vw); max-height: 88vh; overflow:auto; background: #0f172a; border:1px solid #27324a; border-radius: 12px; padding: 20px 22px; }
    #trkTlBox .tl-head { display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px; }
    #trkTlBox .tl-close { cursor:pointer; font-size:1.7rem; color:#e2e8f0; line-height:1; }
    #trkTlBar { display:flex; width:100%; height:34px; border-radius:6px; overflow:hidden; margin:14px 0 6px; border:1px solid #27324a; }
    #trkTlBar > div { flex:1 1 0; min-width:1px; height:100%; }
    #trkTlAxis { display:flex; justify-content:space-between; color:#94a3b8; font-size:0.72rem; }
    .tl-legend { display:flex; gap:16px; margin-top:14px; font-size:0.78rem; color:#94a3b8; flex-wrap:wrap; }
    .tl-legend span { display:inline-flex; align-items:center; gap:6px; }
    .tl-dot { width:12px; height:12px; border-radius:3px; display:inline-block; }
</style>
<div id="trkTlModal">
    <div id="trkTlBox">
        <div class="tl-head">
            <h2 id="trkTlTitle" style="margin:0; font-size:1.05rem;">Timeline</h2>
            <span class="tl-close" id="trkTlClose" title="Close">&times;</span>
        </div>
        <div id="trkTlSummary" style="color:#94a3b8; font-size:0.85rem;"></div>
        <div id="trkTlBar"></div>
        <div id="trkTlAxis"></div>
        <div class="tl-legend">
            <span><i class="tl-dot" style="background:#34d399;"></i> Active</span>
            <span><i class="tl-dot" style="background:#a3e635;"></i> Mostly active</span>
            <span><i class="tl-dot" style="background:#fbbf24;"></i> Idle</span>
            <span><i class="tl-dot" style="background:#334155;"></i> No data (agent off)</span>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('trkTlModal');
    if (!modal) return;
    var box = document.getElementById('trkTlBox');

    function pad(n){ return (n<10?'0':'')+n; }
    function keyOf(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+' '+pad(d.getHours())+':'+pad(d.getMinutes()); }
    function hm(d){ var h=d.getHours(), m=d.getMinutes(), ap=h>=12?'PM':'AM', h12=((h+11)%12)+1; return h12+':'+pad(m)+' '+ap; }
    function dur(s){ s=Math.max(0,s|0); return Math.floor(s/3600)+'h '+Math.floor((s%3600)/60)+'m'; }
    function parse(ts){ return new Date(String(ts).replace(' ','T')); }

    function color(cell){
        if (!cell) return '#334155';                 // no data
        var a=cell.a, i=cell.i;
        if (a>0 && i===0) return '#34d399';           // fully active
        if (a===0 && i>0) return '#fbbf24';           // idle
        return a>=i ? '#a3e635' : '#fbbf24';          // mixed
    }

    function open(shiftId, name) {
        document.getElementById('trkTlTitle').textContent = 'Timeline — ' + name;
        document.getElementById('trkTlSummary').textContent = 'Loading…';
        document.getElementById('trkTlBar').innerHTML = '';
        document.getElementById('trkTlAxis').innerHTML = '';
        modal.classList.add('open');

        fetch('tracker_timeline.php?shift=' + encodeURIComponent(shiftId))
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.success) { document.getElementById('trkTlSummary').textContent = (d && d.error) || 'Error'; return; }
            render(d);
        })
        .catch(function(){ document.getElementById('trkTlSummary').textContent = 'Network error.'; });
    }

    function render(d) {
        var s = d.shift;
        var start = parse(s.start);
        var end = s.end ? parse(s.end) : parse(d.server_now);
        var mins = Math.max(1, Math.min(1440, Math.round((end - start) / 60000)));
        var total = s.active_seconds + s.idle_seconds;
        var idlePct = total > 0 ? Math.round(s.idle_seconds * 100 / total) : 0;

        document.getElementById('trkTlSummary').innerHTML =
            hm(start) + ' → ' + (s.end ? hm(end) : 'running') +
            '  ·  <b style="color:#34d399;">' + dur(s.active_seconds) + '</b> active' +
            '  ·  <b style="color:#fbbf24;">' + dur(s.idle_seconds) + '</b> idle' +
            '  ·  ' + idlePct + '% idle';

        var bar = document.getElementById('trkTlBar');
        var cur = new Date(start.getTime());
        for (var k = 0; k < mins; k++) {
            var cell = d.minutes[keyOf(cur)] || null;
            var seg = document.createElement('div');
            seg.style.background = color(cell);
            seg.title = hm(cur) + (cell ? ('  ·  ' + cell.a + 's active / ' + cell.i + 's idle') : '  ·  no data');
            bar.appendChild(seg);
            cur.setMinutes(cur.getMinutes() + 1);
        }

        var axis = document.getElementById('trkTlAxis');
        axis.innerHTML = '<span>' + hm(start) + '</span><span>' + (s.end ? hm(end) : 'now') + '</span>';
    }

    function close() { modal.classList.remove('open'); }

    document.querySelectorAll('.trk-timeline').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn.getAttribute('data-shift'), btn.getAttribute('data-name') || 'Shift'); });
    });
    document.getElementById('trkTlClose').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
})();
</script>
