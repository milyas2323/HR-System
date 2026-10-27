# Regenerates tracker-agent\dist\server-deploy.zip with every tracker server-side file.
# Run this after any change to the tracker's PHP, then upload the zip to the live
# project folder in cPanel (Upload -> right-click -> Extract -> Overwrite).
#
#   powershell -ExecutionPolicy Bypass -File tracker-agent\build-server-deploy.ps1
#
# NOTE: This bundle includes includes/functions.php (it carries the "Idle Alert Fine"
# label + other logic). Extracting it OVERWRITES live functions.php, so make a backup
# of the live file first (cPanel: copy/rename it) before the first time you deploy this.
# Deliberately NOT included (do not overwrite these on live):
#   - includes/db.php           -> holds live DB credentials; overwriting breaks the site
#   - admin/dashboard.php       -> one-time "Activity Tracker" menu link + route (already live)
#   - employee/dashboard.php    -> one-time "Download Tracker" card (already live)

$ErrorActionPreference = 'Stop'
$root  = Split-Path -Parent $PSScriptRoot          # project root (…\hr-system)
$stage = Join-Path $env:TEMP ('trk-deploy-' + [Guid]::NewGuid().ToString('N'))
$out   = Join-Path $PSScriptRoot 'dist\server-deploy.zip'

# Files the tracker owns (safe to overwrite on live). Required.
$files = @(
    'api\_bootstrap.php',
    'api\login.php',
    'api\shift.php',
    'api\ping.php',
    'api\screenshot.php',
    'api\idle_alert.php',
    'admin\tracker.php',
    'admin\tracker_delete.php',
    'admin\tracker_export.php',
    'admin\tracker_timeline.php',
    'admin\tracker_live.php',
    'includes\tracker_schema.php',
    'includes\functions.php',
    'downloads\.htaccess',
    'downloads\README.txt'
)

# Optional: the installer itself, so one zip upload also publishes the download.
# (Big file ~78MB. Delete downloads\WorkforceHubTracker-Setup.exe if you want a
# lighter code-only zip.)
$optional = @(
    'downloads\WorkforceHubTracker.zip'
)

New-Item -ItemType Directory -Force -Path $stage | Out-Null
foreach ($rel in $files) {
    $src = Join-Path $root $rel
    if (-not (Test-Path $src)) { throw "Missing file: $src" }
    $dest = Join-Path $stage $rel
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null
    Copy-Item $src $dest -Force
}
foreach ($rel in $optional) {
    $src = Join-Path $root $rel
    if (Test-Path $src) {
        $dest = Join-Path $stage $rel
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null
        Copy-Item $src $dest -Force
    }
}

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $out) | Out-Null
if (Test-Path $out) { Remove-Item $out -Force }

# Compress-Archive skips dotfiles via -Path wildcards, so zip with .NET to include .htaccess.
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($stage, $out)

Remove-Item $stage -Recurse -Force
Write-Host "Built: $out"
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::OpenRead($out).Entries | ForEach-Object { "  " + $_.FullName }
