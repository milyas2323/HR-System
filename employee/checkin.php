<?php
include "../includes/db.php";
include "../includes/functions.php";

if(session_status() == PHP_SESSION_NONE){
    session_start();
}

$user_id = $_SESSION['user']['id'];
$user = $_SESSION['user'];

// Refresh user info
$refreshUser = $conn->query("SELECT * FROM users WHERE id='$user_id' LIMIT 1");
if ($refreshUser && $refreshUser->num_rows > 0) {
    $user = $refreshUser->fetch_assoc();
}

/* =========================
   START SHIFT SUBMIT
   ========================= */
if(isset($_POST['start_shift'])){
    $message   = mysqli_real_escape_string($conn, trim($_POST['message']));
    $location  = mysqli_real_escape_string($conn, trim($_POST['current_location']));
    $latitude  = mysqli_real_escape_string($conn, trim($_POST['current_latitude']));
    $longitude = mysqli_real_escape_string($conn, trim($_POST['current_longitude']));
    $ip        = getUserIP();

    /* CHECK ACTIVE SHIFT */
    $check = $conn->query("SELECT id FROM shifts WHERE employee_id='$user_id' AND status='active' LIMIT 1");
    if($check->num_rows > 0){
        echo "<script>alert('Shift is already active!'); window.location.href='dashboard.php';</script>";
        exit();
    }

    /* SCREENSHOT SAVE */
    $fileName = "";
    if(!empty($_POST['screenshot_data'])){
        $image = $_POST['screenshot_data'];
        $image = str_replace('data:image/png;base64,', '', $image);
        $image = str_replace(' ', '+', $image);
        $imageData = base64_decode($image);

        $folder = "../uploads/screenshots/";
        if(!is_dir($folder)){
            mkdir($folder, 0777, true);
        }
        $fileName = time() . "_" . $user_id . ".png";
        file_put_contents($folder . $fileName, $imageData);
    }

    /* DEVICE / AGENT */
    $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT']);
    $device = $ua['device'] . " (" . $ua['os'] . " / " . $ua['browser'] . ")";

    /* INSERT SHIFT */
    $insert = $conn->query("
        INSERT INTO shifts
        (employee_id, screenshot, morning_message, start_time, status, ip_address, device, current_location, current_latitude, current_longitude)
        VALUES
        ('$user_id', '$fileName', '$message', NOW(), 'active', '$ip', '$device', '$location', '$latitude', '$longitude')
    ");

    if($insert){


        echo "<script>alert('Shift started successfully!'); window.location.href='dashboard.php';</script>";
    } else {
        echo "<script>alert('Database Error starting shift.');</script>";
    }
    exit();
}
?>

<div class="card">
    <h2>Start Daily Workday</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        To clock-in, share your screen (automatically captures workstation screenshot) and send a morning message.
    </p>

    <form method="POST" id="checkinForm">
        <!-- LOCATION DETECTION -->
        <div class="form-group">
            <label>Workstation Location</label>
            <div id="locationBadgeContainer" style="margin-bottom: 10px;">
                <span class="location-badge" style="background: rgba(255,255,255,0.05); color: var(--text-muted); border-color: rgba(255,255,255,0.1);">
                    🔄 Fetching workstation coordinates...
                </span>
            </div>
            
            <input type="hidden" name="current_location" id="current_location" value="Unknown Location">
            <input type="hidden" name="current_latitude" id="current_latitude" value="0.0">
            <input type="hidden" name="current_longitude" id="current_longitude" value="0.0">
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
/* 1. FETCH GEOLOCATION & ADDRESS */
if(navigator.geolocation){
    navigator.geolocation.getCurrentPosition(
        function(position){
            let lat = position.coords.latitude;
            let lng = position.coords.longitude;

            document.getElementById("current_latitude").value = lat;
            document.getElementById("current_longitude").value = lng;

            // Reverse geocode via OSM Nominatim API
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
            .then(res => res.json())
            .then(data => {
                let address = data.display_name || `Lat: ${lat}, Lng: ${lng}`;
                document.getElementById("current_location").value = address;
                document.getElementById("locationBadgeContainer").innerHTML = `
                    <span class="location-badge">
                        📍 Verified: ${address.split(',')[0]} (${lat.toFixed(4)}, ${lng.toFixed(4)})
                    </span>
                `;
            })
            .catch(() => {
                document.getElementById("locationBadgeContainer").innerHTML = `
                    <span class="location-badge" style="color: var(--warning); background: rgba(245,158,11,0.1); border-color: var(--warning-border);">
                        ⚠️ Coordinates Verified: ${lat.toFixed(4)}, ${lng.toFixed(4)} (Address Lookup Failed)
                    </span>
                `;
            });
        },
        function(error){
            document.getElementById("locationBadgeContainer").innerHTML = `
                <span class="location-badge" style="color: var(--danger); background: rgba(239,68,68,0.1); border-color: var(--danger-border);">
                    ❌ Geolocation Denied: Fails workstation geofence policy!
                </span>
            `;
        }
    );
}

/* 2. CAPTURE WORKSTATION SCREEN & SUBMIT */
document.getElementById("btnCaptureScreen").addEventListener("click", async function(){
    const btn = this;
    const loader = document.getElementById("loaderText");
    
    btn.disabled = true;
    loader.style.display = "block";

    // Attempt HTML5 Screen Capture API for TRUE laptop screen grab
    try {
        const stream = await navigator.mediaDevices.getDisplayMedia({
            video: {
                displaySurface: "monitor", // Request full monitor screenshot
                cursor: "always"
            },
            audio: false
        });

        // Capture a frame from stream
        const video = document.createElement("video");
        video.srcObject = stream;
        video.play();

        video.onloadedmetadata = () => {
            setTimeout(() => {
                const canvas = document.createElement("canvas");
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext("2d");
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                // Stop all tracks
                stream.getTracks().forEach(track => track.stop());

                // Set image data
                const base64Img = canvas.toDataURL("image/png");
                document.getElementById("screenshot_data").value = base64Img;

                // Preview
                document.getElementById("previewImg").src = base64Img;
                document.getElementById("screenPreviewBox").style.display = "block";

                // Append submission flag and send form
                submitShiftForm();
            }, 1000); // Wait for stream render
        };

    } catch (err) {
        console.warn("Screen Sharing API denied or unsupported. Falling back to page screenshot.", err);
        
        // Fallback to html2canvas capturing the page content
        html2canvas(document.body).then(function(canvas){
            let base64Img = canvas.toDataURL("image/png");
            document.getElementById("screenshot_data").value = base64Img;
            submitShiftForm();
        });
    }
});

function submitShiftForm() {
    let form = document.getElementById("checkinForm");
    let hiddenInput = document.createElement("input");
    hiddenInput.type = "hidden";
    hiddenInput.name = "start_shift";
    hiddenInput.value = "1";
    form.appendChild(hiddenInput);
    form.submit();
}
</script>