<?php
// Included from dashboard.php — auth and POST handled there.
$user_id = (int) $user['id'];
$checkinAlertMessage = $checkinMessage ?? '';
$checkinAlertType = $checkinMessageType ?? 'danger';
?>

<div class="card">
    <h2>Start Daily Workday</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        To clock-in, share your screen (automatically captures workstation screenshot) and send a morning message.
    </p>

    <?php if ($checkinAlertMessage !== '') { ?>
        <div class="alert <?php echo htmlspecialchars($checkinAlertType); ?>" style="margin-bottom: 16px;">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($checkinAlertMessage); ?></span>
        </div>
    <?php } ?>

    <form method="POST" action="dashboard.php?page=start-shift" id="checkinForm">
        <!-- LOCATION DETECTION -->
        <div class="form-group">
            <label>Workstation Location</label>
            <div id="locationBadgeContainer" style="margin-bottom: 10px;">
                <span class="location-badge" style="background: rgba(255,255,255,0.05); color: var(--text-muted); border-color: rgba(255,255,255,0.1);">
                    🔄 Fetching workstation coordinates...
                </span>
            </div>
            
            <input type="hidden" name="current_location" id="current_location" value="Unknown Location">
            <input type="hidden" name="current_latitude" id="current_latitude" value="">
            <input type="hidden" name="current_longitude" id="current_longitude" value="">
            <input type="hidden" name="location_accuracy" id="location_accuracy" value="">
        </div>

        <!-- MORNING MESSAGE -->
        <div class="form-group">
            <label>Morning Message</label>
            <textarea name="message" required style="min-height: 80px;">Good Morning</textarea>
        </div>

        <!-- SCREENSHOT PAYLOAD -->
        <input type="hidden" name="screenshot_data" id="screenshot_data">

        <!-- PREVIEW BOX -->
        <div id="screenPreviewBox" style="display:none; margin-bottom: 20px;">
            <label>Screen Capture Preview</label>
            <img id="previewImg" style="width:100%; max-height:220px; object-fit:contain; border-radius:12px; border:1px dashed var(--primary);">
        </div>

        <!-- ACTIONS -->
        <button type="button" id="btnCaptureScreen" class="btn glowing-element" style="width: 100%; margin-bottom: 14px;">
            📸 Share Screen & Start Shift
        </button>

        <p id="loaderText" style="display: none; text-align: center; color: var(--primary); font-weight: 600; font-size: 0.9rem;">
            ⚙️ Capturing screen layout and matching geofence parameters...
        </p>
    </form>
</div>

<!-- LIBRARIES FOR FALLBACK SCREENSHOT -->
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>

<script>
const LOCATION_OPTIONS = {
    enableHighAccuracy: true,
    maximumAge: 0,
    timeout: 15000
};
const DESIRED_ACCURACY_METERS = 100;
const LOCATION_WATCH_MS = 12000;

const locationState = {
    ready: false,
    lat: null,
    lng: null,
    accuracy: null,
    address: null,
    error: null
};

function setLocationFields(lat, lng, accuracy, address) {
    document.getElementById("current_latitude").value = lat;
    document.getElementById("current_longitude").value = lng;
    document.getElementById("location_accuracy").value = accuracy != null ? String(Math.round(accuracy)) : '';
    if (address) {
        document.getElementById("current_location").value = address;
    }
}

function updateLocationBadge(status, message) {
    const styles = {
        loading: 'background: rgba(255,255,255,0.05); color: var(--text-muted); border-color: rgba(255,255,255,0.1);',
        ok: '',
        warn: 'color: var(--warning); background: rgba(245,158,11,0.1); border-color: var(--warning-border);',
        error: 'color: var(--danger); background: rgba(239,68,68,0.1); border-color: var(--danger-border);'
    };
    document.getElementById("locationBadgeContainer").innerHTML = `
        <span class="location-badge" style="${styles[status] || styles.ok}">
            ${message}
        </span>
    `;
}

function renderLocationBadge() {
    if (locationState.error && !locationState.ready) {
        updateLocationBadge('error', `❌ ${locationState.error}`);
        return;
    }
    if (!locationState.ready) {
        updateLocationBadge('loading', '🔄 Acquiring precise coordinates… allow location when prompted.');
        return;
    }

    const lat = locationState.lat;
    const lng = locationState.lng;
    const acc = locationState.accuracy;
    const shortAddress = locationState.address ? locationState.address.split(',')[0] : 'Coordinates only';
    const accLabel = acc != null ? `±${Math.round(acc)}m` : '';
    const quality = acc != null && acc <= DESIRED_ACCURACY_METERS ? 'ok' : 'warn';
    const qualityIcon = quality === 'ok' ? '📍' : '⚠️';

    updateLocationBadge(
        quality,
        `${qualityIcon} ${shortAddress} (${lat.toFixed(6)}, ${lng.toFixed(6)}${accLabel ? ' ' + accLabel : ''})`
    );
}

function reverseGeocode(lat, lng) {
    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`;
    return fetch(url, {
        headers: {
            'Accept': 'application/json',
            'Accept-Language': 'en'
        }
    })
    .then(res => res.json())
    .then(data => data.display_name || `Lat: ${lat}, Lng: ${lng}`)
    .catch(() => `Lat: ${lat}, Lng: ${lng}`);
}

function applyPosition(position) {
    const lat = position.coords.latitude;
    const lng = position.coords.longitude;
    const accuracy = position.coords.accuracy;

    if (locationState.accuracy != null && accuracy >= locationState.accuracy) {
        return false;
    }

    locationState.lat = lat;
    locationState.lng = lng;
    locationState.accuracy = accuracy;
    locationState.ready = true;
    locationState.error = null;

    setLocationFields(lat, lng, accuracy, null);
    renderLocationBadge();

    reverseGeocode(lat, lng).then(address => {
        if (locationState.lat === lat && locationState.lng === lng) {
            locationState.address = address;
            setLocationFields(lat, lng, accuracy, address);
            renderLocationBadge();
        }
    });

    return true;
}

function fetchBestLocation(maxWatchMs) {
    maxWatchMs = maxWatchMs || LOCATION_WATCH_MS;

    return new Promise(resolve => {
        if (!navigator.geolocation) {
            locationState.error = 'Geolocation is not supported in this browser.';
            renderLocationBadge();
            resolve(false);
            return;
        }

        let settled = false;
        let watchId = null;
        const startedAt = Date.now();

        const finish = () => {
            if (settled) {
                return;
            }
            settled = true;
            if (watchId != null) {
                navigator.geolocation.clearWatch(watchId);
            }
            resolve(locationState.ready);
        };

        watchId = navigator.geolocation.watchPosition(
            position => {
                applyPosition(position);
                const acc = position.coords.accuracy;
                const elapsed = Date.now() - startedAt;
                if (acc <= DESIRED_ACCURACY_METERS || elapsed >= maxWatchMs) {
                    finish();
                }
            },
            error => {
                navigator.geolocation.getCurrentPosition(
                    position => {
                        applyPosition(position);
                        finish();
                    },
                    () => {
                        locationState.error = error.code === 1
                            ? 'Location permission denied. Enable location access to clock in.'
                            : 'Could not determine location. Check Windows/browser location settings.';
                        renderLocationBadge();
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

document.getElementById("btnCaptureScreen").addEventListener("click", async function(){
    const btn = this;
    const loader = document.getElementById("loaderText");

    btn.disabled = true;
    loader.style.display = "block";
    loader.textContent = "📍 Refreshing GPS location before capture…";

    await fetchBestLocation(8000);

    if (!locationState.ready || locationState.lat == null || locationState.lng == null) {
        alert("Location could not be verified. Please allow location access in your browser and Windows settings, then try again.");
        btn.disabled = false;
        loader.style.display = "none";
        return;
    }

    if (locationState.accuracy != null && locationState.accuracy > 500) {
        const proceed = confirm(
            "Location accuracy is about " + Math.round(locationState.accuracy) +
            " meters (common on laptops without GPS). Coordinates may be approximate. Continue clock-in anyway?"
        );
        if (!proceed) {
            btn.disabled = false;
            loader.style.display = "none";
            return;
        }
    }

    loader.textContent = "⚙️ Capturing screen and starting shift…";

    try {
        const stream = await navigator.mediaDevices.getDisplayMedia({
            video: {
                displaySurface: "monitor",
                cursor: "always"
            },
            audio: false
        });

        const video = document.createElement("video");
        video.srcObject = stream;
        video.play();

        video.onloadedmetadata = () => {
            setTimeout(async () => {
                const canvas = document.createElement("canvas");
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext("2d");
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                stream.getTracks().forEach(track => track.stop());

                const base64Img = canvas.toDataURL("image/png");
                document.getElementById("screenshot_data").value = base64Img;
                document.getElementById("previewImg").src = base64Img;
                document.getElementById("screenPreviewBox").style.display = "block";

                await submitShiftForm();
            }, 1000);
        };

    } catch (err) {
        console.warn("Screen Sharing API denied or unsupported. Falling back to page screenshot.", err);

        html2canvas(document.body).then(async function(canvas){
            const base64Img = canvas.toDataURL("image/png");
            document.getElementById("screenshot_data").value = base64Img;
            await submitShiftForm();
        });
    }
});

async function submitShiftForm() {
    const loader = document.getElementById("loaderText");
    loader.textContent = "📍 Final location check…";
    await fetchBestLocation(5000);

    if (!locationState.ready || locationState.lat == null || locationState.lng == null) {
        alert("Location was lost before submit. Please try again.");
        document.getElementById("btnCaptureScreen").disabled = false;
        loader.style.display = "none";
        return;
    }

    const form = document.getElementById("checkinForm");
    const hiddenInput = document.createElement("input");
    hiddenInput.type = "hidden";
    hiddenInput.name = "start_shift";
    hiddenInput.value = "1";
    form.appendChild(hiddenInput);
    form.submit();
}
</script>