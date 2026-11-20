<#
.SYNOPSIS
  Start the persistent ReFind localhost demo at http://127.0.0.1:8030/.
.DESCRIPTION
  Starts the owned portable MariaDB (127.0.0.1:3307) if needed and the demo web worker (PHP 8.5 built-in server).
  It never resets, reseeds or migrates anything: the demo keeps whatever state it had when it was stopped.
  The launcher's memory/disk gates and its two-web-instance limit still apply.
.PARAMETER NoBrowser
  Do not open the browser after starting.
#>
param([switch]$NoBrowser)

$Root = 'C:\ReFind-Laravel'
$App = Join-Path $Root 'laravel-demo'
$Control = Join-Path $App 'tools\workspace\phase6-control.py'
$State = Join-Path $Root 'state\demo'
$Url = 'http://127.0.0.1:8030/'

if (-not (Test-Path (Join-Path $State 'provisioned.json'))) {
    Write-Host 'The demo is not provisioned yet (state\demo\provisioned.json is missing).'
    exit 1
}
if (-not (Test-Path (Join-Path $State 'demo-baseline.json'))) {
    Write-Warning 'No demo baseline was found: the demo may not be fully seeded.'
}

& python -B $Control start --profile demo
if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'The demo did not start. The reason is shown above (for example the 2 GiB free-memory gate,'
    Write-Host 'two other migration web workers already running, or the database). Nothing was changed.'
    exit $LASTEXITCODE
}
Write-Host ''
Write-Host ('ReFind demo is running: ' + $Url)
Write-Host ('Demo accounts (private): ' + (Join-Path $State 'DEMO-ACCOUNTS.md'))
Write-Host 'Stop it with stop-demo (your data is kept).'
if (-not $NoBrowser) {
    Start-Process $Url
}
