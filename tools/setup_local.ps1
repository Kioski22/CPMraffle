$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'
$phpExe = 'C:\xampp\php\php.exe'
$sqlFile = Join-Path $projectRoot 'rafflev2_event_reset.sql'

if (-not (Test-Path $mysqlExe)) {
    throw "mysql.exe not found at $mysqlExe"
}
if (-not (Test-Path $phpExe)) {
    throw "php.exe not found at $phpExe"
}
if (-not (Test-Path $sqlFile)) {
    throw "SQL file not found at $sqlFile"
}

Write-Host 'Ensuring database exists...' -ForegroundColor Cyan
& $mysqlExe -u root -e "CREATE DATABASE IF NOT EXISTS rafflev2;"

Write-Host 'Importing SQL reset file...' -ForegroundColor Cyan
& $mysqlExe -u root rafflev2 --execute="source c:/xampp/htdocs/CPMraffle/rafflev2_event_reset.sql"

Write-Host 'Running app DB probe...' -ForegroundColor Cyan
& $phpExe (Join-Path $projectRoot 'tools\check_local_db.php')

Write-Host 'Done. Open: http://localhost/CPMraffle/login.php' -ForegroundColor Green
