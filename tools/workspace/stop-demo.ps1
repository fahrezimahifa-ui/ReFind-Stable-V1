<#
.SYNOPSIS
  Stop the persistent ReFind localhost demo. Its data is kept.
.DESCRIPTION
  Stops the demo web worker, then stops the owned MariaDB on 127.0.0.1:3307 only if nothing else needs it
  (the launcher refuses while another owned web worker or database session is live, and leaves it running).
.PARAMETER KeepDatabase
  Stop only the demo web worker and leave the owned database running.
#>
param([switch]$KeepDatabase)

$Root = 'C:\ReFind-Laravel'
$Control = Join-Path $Root 'laravel-demo\tools\workspace\phase6-control.py'

& python -B $Control stop --profile demo
if ($LASTEXITCODE -ne 0) {
    Write-Host 'The demo web worker could not be stopped; see the message above.'
    exit $LASTEXITCODE
}
if (-not $KeepDatabase) {
    & python -B $Control stop --profile demo --db
    if ($LASTEXITCODE -ne 0) {
        Write-Host 'The demo is stopped. The shared owned database was left running because something else still uses it (see above).'
        exit 0
    }
}
Write-Host 'The ReFind demo is stopped. Its data is kept; start it again with start-demo.'
