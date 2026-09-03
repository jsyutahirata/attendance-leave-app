<#
  Local development stack helper (PHP + MariaDB bundled with XAMPP).
  For local verification on Windows only -- NOT for production (XServer).

  Usage:
    powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 start
    powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 stop
    powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 status

  Requires XAMPP at C:\xampp, the "attendance" database, and .env configured.
  App URL: http://localhost:8000  (.env should set APP_ENV=local)

  NOTE: ASCII-only on purpose. Windows PowerShell 5.1 reads .ps1 as ANSI
  unless the file has a UTF-8 BOM, so non-ASCII here would be mojibake.
#>
param(
  [ValidateSet('start','stop','status')]
  [string]$Action = 'status'
)

$ErrorActionPreference = 'Stop'
$Xampp      = 'C:\xampp'
$Php        = Join-Path $Xampp 'php\php.exe'
$Mysqld     = Join-Path $Xampp 'mysql\bin\mysqld.exe'
$MysqlAdmin = Join-Path $Xampp 'mysql\bin\mysqladmin.exe'
$MyIni      = Join-Path $Xampp 'mysql\bin\my.ini'
$AppRoot    = Split-Path -Parent $PSScriptRoot
$Port       = 8000
$LogDir     = Join-Path $AppRoot 'storage\logs'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Test-Mysql {
  try { (& $MysqlAdmin -u root ping 2>&1) -match 'mysqld is alive' } catch { $false }
}

function Start-Stack {
  if (Test-Mysql) {
    Write-Host 'MariaDB     : already running'
  } else {
    Start-Process -FilePath $Mysqld -ArgumentList "--defaults-file=$MyIni" -WindowStyle Hidden
    for ($i=0; $i -lt 30 -and -not (Test-Mysql); $i++) { Start-Sleep -Milliseconds 500 }
    if (Test-Mysql) { Write-Host 'MariaDB     : started' } else { Write-Host 'MariaDB     : FAILED to start' }
  }

  if (Get-Process php -ErrorAction SilentlyContinue) {
    Write-Host 'PHP server  : already running'
  } else {
    Start-Process -FilePath $Php `
      -ArgumentList '-d','display_errors=0','-S',"localhost:$Port",'-t','public' `
      -WorkingDirectory $AppRoot -WindowStyle Hidden `
      -RedirectStandardError  (Join-Path $LogDir 'php-server.err.log') `
      -RedirectStandardOutput (Join-Path $LogDir 'php-server.out.log')
    Start-Sleep -Seconds 1
    if (Get-Process php -ErrorAction SilentlyContinue) {
      Write-Host "PHP server  : http://localhost:$Port"
    } else {
      Write-Host 'PHP server  : FAILED to start'
    }
  }
}

function Stop-Stack {
  Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force
  Write-Host 'PHP server  : stopped'
  if (Test-Path $MysqlAdmin) {
    try { & $MysqlAdmin -u root shutdown 2>&1 | Out-Null } catch {}
  }
  Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force
  Write-Host 'MariaDB     : stopped'
}

function Show-Status {
  if (Test-Mysql) { Write-Host 'MariaDB     : running' } else { Write-Host 'MariaDB     : stopped' }
  if (Get-Process php -ErrorAction SilentlyContinue) {
    Write-Host "PHP server  : running (http://localhost:$Port)"
  } else {
    Write-Host 'PHP server  : stopped'
  }
}

switch ($Action) {
  'start'  { Start-Stack }
  'stop'   { Stop-Stack }
  'status' { Show-Status }
}
