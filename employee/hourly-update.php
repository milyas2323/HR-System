<?php
// Included from dashboard.php — auth and POST submission handled there.
$employee_id = (int) $user['id'];
$message = $hourlyUpdateMessage ?? '';
$messageType = $hourlyUpdateMessageType ?? 'danger';

$activeShift = $conn->query("
    SELECT id, start_time FROM shifts
    WHERE employee_id='$employee_id' AND status='active'
    LIMIT 1
")->fetch_assoc();

$slots = [];
$currentSlot = null;
$dbNowTs = getDatabaseNowTimestamp($conn);

if ($activeShift) {
    $slots = getHourlySlotDefinitionsForShift($activeShift['start_time']);
    $currentSlot = findHourlySlotForTimestamp($slots, $dbNowTs);
}
?>

<div class="card">
    <h2>Hourly Task Update</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Submit exactly <strong>one update per hour</strong> during its <strong>15-minute window</strong>
        (e.g. 7:00–7:15 PM). Late or duplicate submissions do not count and may lead to salary deductions.
    </p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <?php if(!$activeShift) { ?>
        <div class="alert info">
            <span>ℹ️</span>
            <span>Start your shift first from the <strong>Start Shift</strong> page.</span>
        </div>
    <?php } else { ?>

        <?php if($currentSlot) {
            $slotTaken = hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $currentSlot['slot_date'], $currentSlot['slot_hour']);
        ?>
            <div class="alert <?php echo $slotTaken ? 'warning' : 'success'; ?>" style="margin-bottom: 20px;">
                <span><?php echo $slotTaken ? '✓' : '⏰'; ?></span>
                <span>
                    <strong>Current window:</strong> <?php echo htmlspecialchars($currentSlot['label']); ?>
                    <?php if($slotTaken) { ?>
                        — already submitted for this slot.
                    <?php } else { ?>
                        — you may submit now.
                    <?php } ?>
                </span>
            </div>
        <?php } else { ?>
            <div class="alert warning" style="margin-bottom: 20px;">
                <span>⚠️</span>
                <span>
                    <strong>No active submission window.</strong> Wait for the next slot (see schedule below).
                    Submitting outside a window counts as a <strong>missed update</strong>.
                </span>
            </div>
        <?php } ?>

        <div class="card" style="padding: 16px; margin-bottom: 24px; background: rgba(255,255,255,0.02);">
            <h3 style="margin: 0 0 12px 0; font-size: 1rem;">Today's slot schedule</h3>
            <div class="table-box">
                <table>
                    <tr>
                        <th>Time window</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach($slots as $slot) {
                        $filled = hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $slot['slot_date'], $slot['slot_hour']);
                        if (!isHourlySlotRequiredForShift($slot, $activeShift['start_time'])) {
                            $status = 'N/A (before clock-in)';
                            $badge = 'warning';
                        } elseif ($filled) {
                            $status = 'Submitted';
                            $badge = 'success';
                        } elseif ($dbNowTs > $slot['end_ts']) {
                            $status = 'Missed';
                            $badge = 'danger';
                        } elseif ($dbNowTs >= $slot['start_ts'] && $dbNowTs <= $slot['end_ts']) {
                            $status = 'Open now';
                            $badge = 'warning';
                        } else {
                            $status = 'Upcoming';
                            $badge = 'warning';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($slot['label']); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>

        <?php
        $canSubmit = $currentSlot && !hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $currentSlot['slot_date'], $currentSlot['slot_hour']);
        ?>

        <form method="POST" action="dashboard.php?page=hourly-update" id="hourlyUpdateForm">
            <div class="form-group">
                <label>Describe Your Current Work</label>
                <textarea
                    name="update_text"
                    placeholder="E.g., Troubleshooting database connection issues / Working on styling components..."
                    required
                    style="min-height: 150px;"
                    <?php echo $canSubmit ? '' : 'disabled'; ?>
                ></textarea>
            </div>

            <?php if ($canSubmit) { ?>
            <div class="form-group">
                <label>Current Location <span style="color: var(--danger); font-weight: 600;">(required)</span></label>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 10px 0;">
                    Hourly updates cannot be submitted without live location access. Allow location permission in your browser.
                </p>
                <div id="hourlyLocationBadgeContainer">
                    <span class="location-badge" style="background: rgba(255,255,255,0.05); color: var(--text-muted); border-color: rgba(255,255,255,0.1);">
                        🔄 Fetching your current location…
                    </span>
                </div>
            </div>
            <?php } ?>

            <input type="hidden" name="hourly_update" value="1">
            <input type="hidden" name="current_location" id="hourly_current_location" value="">
            <input type="hidden" name="current_latitude" id="hourly_current_latitude" value="">
            <input type="hidden" name="current_longitude" id="hourly_current_longitude" value="">
            <input type="hidden" name="location_accuracy" id="hourly_location_accuracy" value="">

            <button type="submit" name="submit" class="glowing-element" id="hourlySubmitBtn" <?php echo $canSubmit ? 'disabled' : 'disabled'; ?>>
                🚀 Submit Progress Log
            </button>
            <?php if(!$canSubmit && $currentSlot) { ?>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">
                    This slot already has a submission.
                </p>
            <?php } elseif(!$canSubmit) { ?>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">
                    Form unlocks when the next 15-minute window opens.
                </p>
            <?php } ?>
        </form>

    <?php } ?>
</div>

<?php if (($activeShift ?? null) && ($canSubmit ?? false)) { ?>
<script>
(function () {
    var LOCATION_OPTIONS = { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 };
    var DESIRED_ACCURACY_METERS = 100;
    var LOCATION_WATCH_MS = 12000;

    var state = { ready: false, lat: null, lng: null, accuracy: null, address: null, error: null };

    var badge = document.getElementById("hourlyLocationBadgeContainer");
    var locationField = document.getElementById("hourly_current_location");
    var latitudeField = document.getElementById("hourly_current_latitude");
    var longitudeField = document.getElementById("hourly_current_longitude");
    var accuracyField = document.getElementById("hourly_location_accuracy");
    var form = document.getElementById("hourlyUpdateForm");
    var submitBtn = document.getElementById("hourlySubmitBtn");
    var submitting = false;
    var allowNativeSubmit = false;

    function updateSubmitState() {
        if (!submitBtn) {
            return;
        }
        submitBtn.disabled = submitting || !state.ready || !!state.error;
    }

    function setBadge(status, message) {
        var styles = {
            loading: 'background: rgba(255,255,255,0.05); color: var(--text-muted); border-color: rgba(255,255,255,0.1);',
            ok: '',
            warn: 'color: var(--warning); background: rgba(245,158,11,0.1); border-color: var(--warning-border);',
            error: 'color: var(--danger); background: rgba(239,68,68,0.1); border-color: var(--danger-border);'
        };
        if (badge) {
            badge.innerHTML = '<span class="location-badge" style="' + (styles[status] || styles.ok) + '">' + message + '</span>';
        }
    }

    function setFields() {
        if (state.lat != null && state.lng != null) {
            latitudeField.value = String(state.lat);
            longitudeField.value = String(state.lng);
        }
        if (state.address) {
            locationField.value = state.address;
        } else if (state.lat != null && state.lng != null) {
            locationField.value = 'Lat: ' + state.lat + ', Lng: ' + state.lng;
        } else {
            locationField.value = '';
        }
        accuracyField.value = state.accuracy != null ? String(Math.round(state.accuracy)) : '';
    }

    function renderBadge() {
        if (state.error && !state.ready) {
            setBadge('error', '❌ ' + state.error);
            updateSubmitState();
            return;
        }
        if (!state.ready) {
            setBadge('loading', '🔄 Acquiring your location… allow access when prompted.');
            updateSubmitState();
            return;
        }
        var shortAddress = state.address ? state.address.split(',')[0] : 'Coordinates only';
        var accLabel = state.accuracy != null ? ' ±' + Math.round(state.accuracy) + 'm' : '';
        var quality = state.accuracy != null && state.accuracy <= DESIRED_ACCURACY_METERS ? 'ok' : 'warn';
        var icon = quality === 'ok' ? '📍' : '⚠️';
        setBadge(quality, icon + ' ' + shortAddress + ' (' + state.lat.toFixed(6) + ', ' + state.lng.toFixed(6) + accLabel + ')');
        updateSubmitState();
    }

    function reverseGeocode(lat, lng) {
        var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng) + '&zoom=18&addressdetails=1';
        return fetch(url, { headers: { 'Accept': 'application/json', 'Accept-Language': 'en' } })
            .then(function (res) { return res.json(); })
            .then(function (data) { return data.display_name || ('Lat: ' + lat + ', Lng: ' + lng); })
            .catch(function () { return 'Lat: ' + lat + ', Lng: ' + lng; });
    }

    function applyPosition(position) {
        var lat = position.coords.latitude;
        var lng = position.coords.longitude;
        var accuracy = position.coords.accuracy;
        if (state.accuracy != null && accuracy >= state.accuracy) { return; }
        state.lat = lat; state.lng = lng; state.accuracy = accuracy; state.ready = true; state.error = null;
        setFields();
        renderBadge();
        reverseGeocode(lat, lng).then(function (address) {
            if (state.lat === lat && state.lng === lng) {
                state.address = address;
                setFields();
                renderBadge();
            }
        });
    }

    function fetchBestLocation(maxWatchMs) {
        maxWatchMs = maxWatchMs || LOCATION_WATCH_MS;
        return new Promise(function (resolve) {
            if (!navigator.geolocation) {
                state.error = 'Geolocation is not supported in this browser.';
                renderBadge();
                resolve(false);
                return;
            }
            var settled = false;
            var watchId = null;
            var startedAt = Date.now();
            function finish() {
                if (settled) { return; }
                settled = true;
                if (watchId != null) { navigator.geolocation.clearWatch(watchId); }
                resolve(state.ready);
            }
            watchId = navigator.geolocation.watchPosition(
                function (position) {
                    applyPosition(position);
                    var elapsed = Date.now() - startedAt;
                    if (position.coords.accuracy <= DESIRED_ACCURACY_METERS || elapsed >= maxWatchMs) { finish(); }
                },
                function (error) {
                    navigator.geolocation.getCurrentPosition(
                        function (position) { applyPosition(position); finish(); },
                        function () {
                            state.error = error.code === 1
                                ? 'Location permission denied. Enable location access to submit.'
                                : 'Could not determine location. Check browser location settings.';
                            renderBadge();
                            finish();
                        },
                        LOCATION_OPTIONS
                    );
                },
                LOCATION_OPTIONS
            );
            setTimeout(finish, maxWatchMs + 500);
        });
    }

    fetchBestLocation();

    if (form) {
        form.addEventListener('submit', function (e) {
            if (allowNativeSubmit) {
                return;
            }
            if (submitting) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            submitting = true;
            updateSubmitState();
            setBadge('loading', '📍 Confirming your location before submit…');
            fetchBestLocation(6000).then(function (ok) {
                setFields();
                if (!ok || !state.ready || state.lat == null || state.lng == null) {
                    submitting = false;
                    state.error = state.error || 'Location access is required before submitting an hourly update.';
                    renderBadge();
                    alert('Location access is required. Allow browser location permission, wait for the location badge to turn green, then try again.');
                    return;
                }
                if (Math.abs(state.lat) < 0.000001 && Math.abs(state.lng) < 0.000001) {
                    submitting = false;
                    state.error = 'Invalid location coordinates received. Please try again.';
                    renderBadge();
                    alert('Invalid location coordinates. Please refresh the page and allow location access.');
                    return;
                }
                if (!locationField.value || locationField.value === 'Unknown Location') {
                    locationField.value = 'Lat: ' + state.lat + ', Lng: ' + state.lng;
                }
                allowNativeSubmit = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitBtn);
                } else {
                    form.submit();
                }
            });
        });
    }
})();
</script>
<?php } ?>

<?php if (isset($_SESSION['hourly_success_popup'])) {
    $hourlyPopup = $_SESSION['hourly_success_popup'];
    unset($_SESSION['hourly_success_popup']);
?>
<div id="hourly-success-modal" class="success-modal-overlay is-open" role="dialog" aria-modal="true" aria-labelledby="hourly-success-title">
    <div class="success-modal">
        <div class="success-modal-icon">✅</div>
        <h3 id="hourly-success-title">Hourly Update Submitted</h3>
        <p>
            Your progress log for <strong><?php echo htmlspecialchars($hourlyPopup['slot']); ?></strong>
            was saved successfully.
        </p>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px;">
            Submitted at <?php echo htmlspecialchars($hourlyPopup['submitted_at']); ?>
        </p>
        <button type="button" class="btn glowing-element" id="hourly-success-close">Got it</button>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('hourly-success-modal');
    var closeBtn = document.getElementById('hourly-success-close');
    function closeModal() {
        if (modal) {
            modal.classList.remove('is-open');
        }
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }
})();
</script>
<?php } ?>
