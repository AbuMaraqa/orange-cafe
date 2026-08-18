<#
.SYNOPSIS
    Downloads the poppler Windows binaries and stages the pieces the app
    needs into bin\poppler\, ready to be bundled into the packaged .exe.

.DESCRIPTION
    EscPosImageRenderer shells out to pdftoppm to rasterize each thermal
    ticket — that step is what makes Arabic text print correctly, because
    cheap ESC/POS printers can't shape Arabic script in their built-in
    fonts. There is no PHP replacement for it, so the binary has to travel
    with the app.

    Run automatically by `php artisan native:build` (config/nativephp.php
    'prebuild'), and safe to run on its own. The download is cached under
    build\cache\, so repeat builds are offline and fast.

.NOTES
    bin\ rather than build\: config/nativephp.php excludes 'build' from the
    packaged app, so anything staged there would be stripped back out.
#>

$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$CacheDir = Join-Path $PSScriptRoot 'cache'
$TargetDir = Join-Path $RepoRoot 'bin\poppler'

# oschwartz10612's release is the maintained Windows build of poppler; the
# official project ships source only.
$Version = '24.08.0-0'
$ZipName = "Release-$Version.zip"
$Url = "https://github.com/oschwartz10612/poppler-windows/releases/download/v$Version/$ZipName"

$Probe = Join-Path $TargetDir 'pdftoppm.exe'
if (Test-Path $Probe) {
    Write-Host "poppler already staged at $TargetDir - skipping." -ForegroundColor DarkGray
    exit 0
}

New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null

$ZipPath = Join-Path $CacheDir $ZipName
if (-not (Test-Path $ZipPath)) {
    Write-Host "Downloading $Url"
    # GitHub release assets require TLS 1.2, which Windows PowerShell 5.1
    # does not negotiate by default.
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $Url -OutFile $ZipPath -UseBasicParsing
}

$ExtractDir = Join-Path $CacheDir 'poppler-extract'
if (Test-Path $ExtractDir) { Remove-Item $ExtractDir -Recurse -Force }
Expand-Archive -Path $ZipPath -DestinationPath $ExtractDir -Force

# The archive lays out poppler-<version>\Library\bin\*.
$SourceBin = Get-ChildItem -Path $ExtractDir -Filter 'pdftoppm.exe' -Recurse |
    Select-Object -First 1 -ExpandProperty DirectoryName

if (-not $SourceBin) {
    throw "pdftoppm.exe not found inside $ZipName - the release layout may have changed."
}

if (Test-Path $TargetDir) { Remove-Item $TargetDir -Recurse -Force }
New-Item -ItemType Directory -Force -Path $TargetDir | Out-Null

# pdftoppm.exe alone is not enough: it links against the poppler, freetype,
# jpeg, png and zlib DLLs sitting beside it, and Windows resolves those from
# the executable's own directory. Copying the whole bin folder (~40MB) is the
# only reliable way to get a working binary - hand-picking DLLs breaks on the
# next poppler release.
Copy-Item -Path (Join-Path $SourceBin '*') -Destination $TargetDir -Recurse -Force

# Trim the other poppler tools; this app only ever invokes pdftoppm, and the
# rest are another ~20MB inside the installer for no reason. Their shared
# DLLs stay.
Get-ChildItem -Path $TargetDir -Filter '*.exe' |
    Where-Object { $_.Name -ne 'pdftoppm.exe' } |
    Remove-Item -Force

$size = [math]::Round(((Get-ChildItem $TargetDir -Recurse | Measure-Object -Property Length -Sum).Sum / 1MB), 1)
Write-Host "poppler staged at $TargetDir ($size MB)" -ForegroundColor Green
