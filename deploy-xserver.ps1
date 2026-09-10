[CmdletBinding()]
param(
    [switch]$SkipChecks
)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$sshKey = Join-Path $env:USERPROFILE '.ssh\xserver_xs894041'
$remote = 'xs894041@xs894041.xsrv.jp'
$port = '10022'
$privateRoot = '/home/xs894041/j-style-tokyo.com/attendance_app'
$publicRoot = '/home/xs894041/j-style-tokyo.com/public_html/attendance.j-style-tokyo.com'

if (-not (Test-Path -LiteralPath $sshKey)) {
    throw "SSH key not found: $sshKey"
}

if (-not $SkipChecks) {
    Write-Host 'Running static checks...'
    & node (Join-Path $projectRoot 'tests\static-check.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Static checks failed. Deployment stopped.' }
}

function Invoke-Scp {
    param([string[]]$Sources, [string]$Destination, [switch]$Recursive)
    $arguments = @('-P', $port, '-i', $sshKey)
    if ($Recursive) { $arguments += '-r' }
    $arguments += $Sources
    $arguments += "${remote}:$Destination"
    & scp @arguments
    if ($LASTEXITCODE -ne 0) { throw "Upload failed: $Destination" }
}

Write-Host 'Uploading private PHP files, views, and scripts...'
Invoke-Scp -Recursive -Sources @(
    (Join-Path $projectRoot 'src'),
    (Join-Path $projectRoot 'views'),
    (Join-Path $projectRoot 'scripts')
) -Destination "$privateRoot/"

Write-Host 'Uploading database migration files...'
Invoke-Scp -Recursive -Sources @(
    (Join-Path $projectRoot 'database\migrations')
) -Destination "$privateRoot/database/"

Write-Host 'Uploading public files...'
# The production index.php has an XServer-specific bootstrap path. Never overwrite it.
$publicFiles = @(Get-ChildItem -LiteralPath (Join-Path $projectRoot 'public') -File | Where-Object { $_.Name -ne 'index.php' } | ForEach-Object { $_.FullName })
Invoke-Scp -Sources $publicFiles -Destination "$publicRoot/"
Invoke-Scp -Recursive -Sources @(
    (Join-Path $projectRoot 'public\assets'),
    (Join-Path $projectRoot 'public\demo')
) -Destination "$publicRoot/"

Write-Host ''
Write-Host 'XServer file deployment completed.' -ForegroundColor Green
Write-Host 'Note: SQL migrations are uploaded but are not executed automatically.'
