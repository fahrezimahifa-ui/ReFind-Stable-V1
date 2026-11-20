<#
.SYNOPSIS
  Reset the persistent ReFind localhost demo to its seeded baseline. Explicit, separately confirmed.
.DESCRIPTION
  Replaces every demo report, claim, pickup, notification, private photo and account change made since seeding
  with the saved baseline (state\demo\demo-baseline.json), then moves its dates so the story ends today.
  It touches only the demo schema refind_laravel_demo and state\demo. You must type RESET DEMO to continue
  (or pass exactly that phrase with -Confirmation). Startup never does this.
.PARAMETER Confirmation
  The confirmation phrase, RESET DEMO (case-sensitive). Without it, the script asks for it.
#>
param([string]$Confirmation)

$Root = 'C:\ReFind-Laravel'
$App = Join-Path $Root 'laravel-demo'
$Control = Join-Path $App 'tools\workspace\phase6-control.py'
$State = Join-Path $Root 'state\demo'

if (-not (Test-Path (Join-Path $State 'demo-baseline.json'))) {
    Write-Host 'No demo baseline has been saved; there is nothing to reset to. Nothing was changed.'
    exit 1
}
Write-Host 'Reset the ReFind localhost demo to its seeded baseline:'
Write-Host '  - all demo changes made since seeding are replaced (reports, claims, pickups, notifications,'
Write-Host '    photos, display names and passwords return to the baseline in DEMO-ACCOUNTS.md);'
Write-Host '  - dates are moved so the demo story ends today;'
Write-Host '  - nothing outside the demo is touched.'
if (-not $Confirmation) {
    $Confirmation = Read-Host 'Type RESET DEMO to continue'
}
if ($Confirmation -cne 'RESET DEMO') {
    Write-Host 'Reset cancelled. Nothing was changed.'
    exit 1
}

# The restore needs the database up and the demo web worker stopped.
& python -B $Control start --profile demo
if ($LASTEXITCODE -ne 0) { Write-Host 'Could not reach the demo database; nothing was changed.'; exit $LASTEXITCODE }
& python -B $Control stop --profile demo
if ($LASTEXITCODE -ne 0) { Write-Host 'Could not stop the demo web worker; nothing was changed.'; exit $LASTEXITCODE }

$saved = @{ REFIND_PROFILE = $env:REFIND_PROFILE; PHP_INI_SCAN_DIR = $env:PHP_INI_SCAN_DIR }
Push-Location $App
try {
    $env:REFIND_PROFILE = 'demo'
    $env:PHP_INI_SCAN_DIR = Join-Path $Root 'runtime\empty-ini-scan'
    & (Join-Path $Root 'runtime\php85\php.exe') -c (Join-Path $Root 'runtime\php85\php.ini') (Join-Path $App 'tools\demo-data.php') restore-baseline
    $code = $LASTEXITCODE
} finally {
    Pop-Location
    $env:REFIND_PROFILE = $saved.REFIND_PROFILE
    $env:PHP_INI_SCAN_DIR = $saved.PHP_INI_SCAN_DIR
}
if ($code -ne 0) {
    Write-Host 'The reset did not complete (see above). Common cause: academic web workers are running; stop them and retry.'
    exit $code
}

& python -B $Control start --profile demo
if ($LASTEXITCODE -ne 0) { Write-Host 'The demo was reset but did not restart; run start-demo.'; exit $LASTEXITCODE }
Write-Host 'The ReFind demo was reset to its baseline and is running at http://127.0.0.1:8030/'
