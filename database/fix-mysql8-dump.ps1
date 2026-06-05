# Converts MySQL 8.0 dump collations for XAMPP MariaDB / MySQL 5.7 import.
# Usage: .\fix-mysql8-dump.ps1 -InputFile "C:\path\to\live_dump.sql"

param(
    [Parameter(Mandatory = $true)]
    [string]$InputFile
)

if (-not (Test-Path $InputFile)) {
    Write-Error "File not found: $InputFile"
    exit 1
}

$dir = Split-Path $InputFile -Parent
$base = [System.IO.Path]::GetFileNameWithoutExtension($InputFile)
$ext = [System.IO.Path]::GetExtension($InputFile)
$OutputFile = Join-Path $dir ($base + "_mariadb" + $ext)

$content = Get-Content $InputFile -Raw -Encoding UTF8

$replacements = @{
    'utf8mb4_0900_ai_ci' = 'utf8mb4_unicode_ci'
    'utf8mb4_0900_as_ci' = 'utf8mb4_unicode_ci'
    'utf8mb4_0900_as_cs' = 'utf8mb4_unicode_ci'
    'utf8mb3_0900_ai_ci' = 'utf8_unicode_ci'
}

foreach ($key in $replacements.Keys) {
    $content = $content.Replace($key, $replacements[$key])
}

Set-Content -Path $OutputFile -Value $content -Encoding UTF8 -NoNewline

Write-Host "Done. Import this file instead:"
Write-Host $OutputFile
