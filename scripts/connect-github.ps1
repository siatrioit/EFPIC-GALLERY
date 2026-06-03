param(
    [Parameter(Mandatory = $true)]
    [string]$RemoteUrl
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$git = @('git', '-c', "safe.directory=$root")

if (-not (Test-Path '.git')) {
    Write-Error 'Nav .git - vispirms: git init, tad git commit'
}

$existing = & $git[0] $git[1] $git[2] remote get-url origin 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "Remote origin jau ir: $existing"
    $ans = Read-Host "Aizstat ar $RemoteUrl ? (y/n)"
    if ($ans -eq 'y') {
        & $git[0] $git[1] $git[2] remote set-url origin $RemoteUrl
    }
} else {
    & $git[0] $git[1] $git[2] remote add origin $RemoteUrl
}

& $git[0] $git[1] $git[2] branch -M main
Write-Host 'Push uz origin main...'
& $git[0] $git[1] $git[2] push -u origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'Ja push atteicas: GitHub Personal Access Token (repo) vai git credential manager.'
    exit $LASTEXITCODE
}

Write-Host "Gatavs. cPanel Git Version Control Clone URL: $RemoteUrl"
