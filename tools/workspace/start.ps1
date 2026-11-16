param([ValidateSet('dev','test')][string]$Profile='dev')
python -B C:\ReFind-Laravel\launchers\control.py start --profile $Profile
if ($LASTEXITCODE -ne 0) { throw 'Isolated startup stopped; review the reported gate.' }
