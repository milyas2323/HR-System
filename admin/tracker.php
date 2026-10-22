<?php
/**
 * Admin → Activity Tracker.
 * Shows, from the desktop agent's data:
 *   - a live "right now" board (active / idle / offline) for today,
 *   - a per-employee day summary: dedicated (active) hours vs idle vs seat span,
 *     contrasted with the shift's span-based worked hours,
 *   - a screenshot timeline for the selected date.
 *
 * Rendered via admin/dashboard.php?page=tracker (also safe to open directly).
 */

include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";
include_once "../includes/tracker_schema.php";
ensureTrackerSchema($conn); // ensure tracker tables exist even if the agent hasn't run yet

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

$today = date('Y-m-d');
$dbNowTs = getDatabaseNowTimestamp($conn);

// ---- Date selection (defaults to today) ----
$dateInput = trim($_GET['t_date'] ?? '');
$df = DateTime::createFromFormat('Y-m-d', $dateInput);
$validDate = $df && $df->format('Y-m-d') === $dateInput;
$selDate = $validDate ? $dateInput : $today;
$isToday = ($selDate === $today);
$selDateSql = mysqli_real_escape_string($conn, $selDate);

// Thresholds mirrored from the agent (seconds).
$ACTIVE_WINDOW = 150;   // activity within this many seconds of "now" == live
$IDLE_THRESHOLD = 300;  // no input for this long == away

// ---- Live "right now" board (today only) ----
$liveRows = [];
if ($isToday) {
    $liveSql = "
        SELECT u.id, u.name,
            s.id AS open_shift_id,
            (SELECT ta.window_start FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_window,
            (SELECT ta.is_idle FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_idle,
            (SELECT ta.max_idle_seconds FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_max_idle,
            (SELECT MAX(t.last_used_at) FROM api_tokens t WHERE t.user_id = u.id AND t.revoked = 0) AS agent_seen
        FROM users u
        LEFT JOIN shifts s ON s.employee_id = u.id AND s.status = 'active'
        WHERE u.role = 'employee'
        ORDER BY u.name ASC
    ";
    $res = $conn->query($liveSql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $lastWindowTs = !empty($r['last_window']) ? strtotime($r['last_window']) : 0;
            $age = $lastWindowTs ? ($dbNowTs - $lastWindowTs) : PHP_INT_MAX;

            if ($age <= $ACTIVE_WINDOW && (int) $r['last_idle'] === 0) {
                $r['live_status'] = 'active';
            } elseif ($age <= $ACTIVE_WINDOW) {
                $r['live_status'] = 'idle';
            } elseif ($age <= $IDLE_THRESHOLD) {
                $r['live_status'] = 'away';
            } else {
                $r['live_status'] = 'offline';
            }
            $liveRows[] = $r;
        }
    }
}

// ---- Day summary per employee for the selected date ----
$summary = [];
$sumSql = "
    SELECT ta.employee_id, u.name,
        SUM(ta.active_seconds) AS active_sec,
        SUM(CASE WHEN ta.shift_id > 0 THEN ta.active_seconds ELSE 0 END) AS onshift_active_sec,
        SUM(ta.idle_seconds)   AS idle_sec,
        COUNT(*)               AS windows,
        MAX(ta.max_idle_seconds) AS max_idle,
        MIN(ta.window_start)   AS first_seen,
        MAX(ta.window_end)     AS last_seen
    FROM tracker_activity ta
    JOIN users u ON u.id = ta.employee_id
    WHERE DATE(ta.window_start) = '$selDateSql'
    GROUP BY ta.employee_id, u.name
    ORDER BY active_sec DESC
";
$res = $conn->query($sumSql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $summary[(int) $r['employee_id']] = $r;
    }
}

// Screenshot counts for the date.
$shotCounts = [];
$res = $conn->query("SELECT employee_id, COUNT(*) AS c FROM tracker_screenshots WHERE DATE(captured_at) = '$selDateSql' GROUP BY employee_id");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $shotCounts[(int) $r['employee_id']] = (int) $r['c'];
    }
}

// Span-based worked hours from the shift(s) on that date, for contrast.
$shiftWorked = [];
if ($hasShiftSummary) {
    $res = $conn->query("SELECT * FROM shifts WHERE DATE(start_time) = '$selDateSql'");
    if ($res) {
        while ($s = $res->fetch_assoc()) {
            $w = getShiftWorkSummary($s, $dbNowTs);
            $eid = (int) $s['employee_id'];
            if (!isset($shiftWorked[$eid])) {
                $shiftWorked[$eid] = 0;
            }
            $shiftWorked[$eid] += (int) $w['worked_seconds'];
        }
    }
}

// Totals for the stat row.
$totActive = 0; $totIdle = 0; $totOnshift = 0;
foreach ($summary as $s) {
    $totActive += (int) $s['active_sec'];
    $totIdle += (int) $s['idle_sec'];
    $totOnshift += (int) $s['onshift_active_sec'];
}

// ---- Screenshots for the gallery (selected date) ----
$galleryEmployee = (int) ($_GET['t_emp'] ?? 0);
$employeeOptions = [];
$res = $conn->query("SELECT id, name FROM users WHERE role='employee' ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) { $employeeOptions[(int) $r['id']] = $r['name']; } }

$shotSql = "
    SELECT ts.*, u.name
    FROM tracker_screenshots ts
    JOIN users u ON u.id = ts.employee_id
    WHERE DATE(ts.captured_at) = '$selDateSql'
";
if ($galleryEmployee > 0) {
    $shotSql .= " AND ts.employee_id = " . $galleryEmployee;
}
$shotSql .= " ORDER BY ts.captured_at DESC LIMIT 200";
$shots = $conn->query($shotSql);

function liveBadge($status) {
    switch ($status) {
        case 'active':  return ['Active', 'var(--success)'];
        case 'idle':    return ['Idle', 'var(--warning)'];
        case 'away':    return ['Away', 'var(--warning)'];
        default:        return ['Offline', 'var(--text-muted)'];
    }
}
?>

<div class="page-title">Activity Tracker</div>

<!-- DATE FILTER -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="tracker">
        <div class="form-group" style="margin-bottom: 0;">
            <label>View date <span style="color: var(--text-muted); font-weight: normal;">(defaults to today)</span></label>
            <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                <input type="date" name="t_date" value="<?php echo htmlspecialchars($selDate); ?>" style="max-width:200px;">
                <button type="submit" class="btn glowing-element" style="margin-bottom:0;">View</button>
                <a href="dashboard.php?page=tracker" class="btn-secondary" style="display:inline-flex; align-items:center; padding:10px 16px; text-decoration:none;">Today</a>
            </div>
        </div>
    </form>
    <div class="alert info" style="margin-top:16px; margin-bottom:0;">
        <span>ℹ️</span>
        <span>
            <strong>Active</strong> = seconds at the keyboard/mouse (OS idle clock). <strong>Idle</strong> = no input for ≥ <?php echo (int) round($IDLE_THRESHOLD/60); ?> min.
            Data comes from the desktop agent and is linked to the employee's open shift.
        </span>
    </div>
</div>

<!-- STAT SUMMARY -->
<div class="grid">
    <div class="card stat-box">
        <h4>Tracked Employees (<?php echo date('d M', strtotime($selDate)); ?>)</h4>
        <h2><?php echo count($summary); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--success);">
        <h4>Dedicated Working Hrs <span style="font-weight:normal; color:var(--text-muted); font-size:0.7rem;">(on-shift)</span></h4>
        <h2 style="color:var(--success);"><?php echo htmlspecialchars(formatWorkDuration($totOnshift)); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--accent);">
        <h4>Total Active <span style="font-weight:normal; color:var(--text-muted); font-size:0.7rem;">(all, incl. off-shift)</span></h4>
        <h2 style="color:var(--accent);"><?php echo htmlspecialchars(formatWorkDuration($totActive)); ?></h2>
    </div>
    <div class="card stat-box" style="border-bottom:4px solid var(--warning);">
        <h4>Total Idle</h4>
        <h2 style="color:var(--warning);"><?php echo htmlspecialchars(formatWorkDuration($totIdle)); ?></h2>
    </div>
</div>

<?php if ($isToday) { ?>
<!-- LIVE BOARD -->
<div class="card">
    <h2>Right Now <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">(auto-refreshes)</span></h2>
    <div class="grid" style="margin-top:12px;">
        <?php if (empty($liveRows)) { ?>
            <p style="color:var(--text-muted);">No employees found.</p>
        <?php } foreach ($liveRows as $r) {
            list($label, $color) = liveBadge($r['live_status']);
            $seen = !empty($r['agent_seen']) ? date('h:i A', strtotime($r['agent_seen'])) : 'never';
        ?>
            <div class="card" style="border-left:4px solid <?php echo $color; ?>; margin:0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                    <span class="badge" style="background:<?php echo $color; ?>; color:#fff;"><?php echo $label; ?></span>
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:8px; line-height:1.6;">
                    <div>Shift: <?php echo $r['open_shift_id'] ? '🟢 open' : '— none'; ?></div>
                    <div>Agent last seen: <?php echo htmlspecialchars($seen); ?></div>
                    <?php if (in_array($r['live_status'], ['idle','away'], true) && !empty($r['last_max_idle'])) { ?>
                        <div>Idle for ~<?php echo htmlspecialchars(formatWorkDuration((int) $r['last_max_idle'])); ?></div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<script>
    // Refresh the live board every 30s while viewing today.
    setTimeout(function(){ location.reload(); }, 30000);
</script>
<?php } ?>

<!-- DAY SUMMARY TABLE -->
<div class="card">
    <h2>Daily Working Hours — <?php echo date('d M Y', strtotime($selDate)); ?></h2>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:16px;">
        <strong>Dedicated Working Hrs</strong> = active at-the-keyboard time recorded <em>while the shift was open</em> — the official worked hours.
        <strong>Total Active</strong> includes activity outside a shift (tracking runs all the time).
        <strong>Shift Span</strong> is clock-out − clock-in − break.
    </p>
    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Dedicated Working (on-shift)</th>
                <th>Total Active (all)</th>
                <th>Idle</th>
                <th>Shift Span</th>
                <th>Longest Idle Gap</th>
                <th>Screenshots</th>
            </tr>
            <?php
            if (empty($summary)) { ?>
                <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:30px;">No tracker activity recorded for this date.</td></tr>
            <?php } else {
                foreach ($summary as $eid => $s) {
                    $active = (int) $s['active_sec'];
                    $onshift = (int) $s['onshift_active_sec'];
                    $idle = (int) $s['idle_sec'];
                    $firstTs = strtotime($s['first_seen']);
                    $lastTs = strtotime($s['last_seen']);
                    $shotCount = $shotCounts[$eid] ?? 0;
                    $shiftSec = $shiftWorked[$eid] ?? null;
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                    <td style="color:var(--success); font-weight:700; font-size:0.95rem;">
                        <?php echo htmlspecialchars(formatWorkDuration($onshift)); ?>
                        <?php if ($onshift === 0) { ?><div style="color:var(--text-muted); font-size:0.7rem; font-weight:normal;">no shift activity</div><?php } ?>
                    </td>
                    <td style="color:var(--accent); font-size:0.9rem;">
                        <?php echo htmlspecialchars(formatWorkDuration($active)); ?>
                        <div style="color:var(--text-muted); font-size:0.7rem;">
                            <?php echo date('h:i A', $firstTs); ?> – <?php echo date('h:i A', $lastTs); ?>
                        </div>
                    </td>
                    <td style="color:var(--warning);"><?php echo htmlspecialchars(formatWorkDuration($idle)); ?></td>
                    <td style="font-size:0.85rem; color:var(--text-muted);">
                        <?php echo $shiftSec !== null && $shiftSec > 0 ? htmlspecialchars(formatWorkDuration($shiftSec)) : '— no shift'; ?>
                    </td>
                    <td><?php echo htmlspecialchars(formatWorkDuration((int) $s['max_idle'])); ?></td>
                    <td>
                        <?php if ($shotCount > 0) { ?>
                            <a href="dashboard.php?page=tracker&amp;t_date=<?php echo urlencode($selDate); ?>&amp;t_emp=<?php echo $eid; ?>#shots" style="color:var(--accent);">
                                <?php echo $shotCount; ?> 📷
                            </a>
                        <?php } else { ?>
                            <span style="color:var(--text-muted);">0</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php }
            } ?>
        </table>
    </div>
</div>

<!-- SCREENSHOT TIMELINE -->
<div class="card" id="shots">
    <h2>Screenshots — <?php echo date('d M Y', strtotime($selDate)); ?></h2>
    <form method="GET" action="dashboard.php" style="margin-bottom:16px;">
        <input type="hidden" name="page" value="tracker">
        <input type="hidden" name="t_date" value="<?php echo htmlspecialchars($selDate); ?>">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <label style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; display:block;">Employee</label>
                <select name="t_emp" onchange="this.form.submit()" style="min-width:200px; padding:10px; border-radius:8px;">
                    <option value="0">All employees</option>
                    <?php foreach ($employeeOptions as $eid => $ename) { ?>
                        <option value="<?php echo $eid; ?>" <?php echo $galleryEmployee === $eid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ename); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
        </div>
    </form>

    <div style="display:flex; flex-wrap:wrap; gap:14px;">
        <?php if ($shots && $shots->num_rows > 0) {
            while ($shot = $shots->fetch_assoc()) {
                $url = '../' . ltrim($shot['file'], '/');
        ?>
            <div style="width:220px; border:1px solid #27324a; border-radius:10px; overflow:hidden; background:var(--card, #1e293b);">
                <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($url); ?>" alt="screenshot" style="width:100%; height:130px; object-fit:cover; display:block;">
                </a>
                <div style="padding:8px 10px; font-size:0.78rem;">
                    <div><strong><?php echo htmlspecialchars($shot['name']); ?></strong></div>
                    <div style="color:var(--text-muted);"><?php echo date('h:i:s A', strtotime($shot['captured_at'])); ?></div>
                    <?php if ((int) $shot['is_idle'] === 1) { ?>
                        <span class="badge warning" style="font-size:0.65rem;">Idle <?php echo htmlspecialchars(formatWorkDuration((int) $shot['idle_seconds'])); ?></span>
                    <?php } else { ?>
                        <span class="badge success" style="font-size:0.65rem;">Active</span>
                    <?php } ?>
                </div>
            </div>
        <?php }
        } else { ?>
            <p style="color:var(--text-muted);">No screenshots for this date<?php echo $galleryEmployee ? ' and employee' : ''; ?>.</p>
        <?php } ?>
    </div>
</div>
