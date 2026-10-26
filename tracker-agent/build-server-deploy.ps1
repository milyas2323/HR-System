# Regenerates tracker-agent\dist\server-deploy.zip with every tracker server-side file.
# Run this after any change to the tracker's PHP, then upload the zip to the live
# project folder in cPanel (Upload -> right-click -> Extract -> Overwrite).
#
#   powershell -ExecutionPolicy Bypass -File tracker-agent\build-server-deploy.ps1
#
# NOTE: This bundle contains only files the tracker fully owns, so extracting it on
# live is always safe (it never overwrites shared core files). Two one-time edits are
# NOT in here on purpose (they would clobber your live files if overwritten):
#   - admin/dashboard.php  (added the "Activity Tracker" menu link + route)
#   - employee/dashboard.php (added the "Download Tracker" card)
# Those were pasted in once by hand and rarely change.

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
    'admin\tracker.php',
    'admin\tracker_delete.php',
    'admin\tracker_export.php',
    'admin\tracker_timeline.php',
    'admin\tracker_live.php',
    'includes\tracker_schema.php',
    'downloads\.htaccess',
    'downloads\README.txt'
)

# Optional: the installer itself, so one zip upload also publishes the download.
# (Big file ~78MB. Delete downloads\WorkforceHubTracker-Setup.exe if you want a
# lighter code-only zip.)
$optional = @(
    'downloads\WorkforceHubTracker-Setup.exe'
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
