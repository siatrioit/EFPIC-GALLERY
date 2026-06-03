param(
    [Parameter(Mandatory = $true)]
    [string]$RemoteUrl
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if (-not (Test-Path '.git')) {
    Write-Error "Nav .git — vispirms palaidiet: git init && git commit"
}

$existing = git remote get-url origin 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "Remote origin jau ir: $existing"
    $ans = Read-Host "Aizstāt ar $RemoteUrl ? (y/n)"
    if ($ans -eq 'y') {
        git remote set-url origin $RemoteUrl
    }
} else {
    git remote add origin $RemoteUrl
}

git branch -M main
Write-Host "Push uz origin main..."
git push -u origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "Ja push atteicās: GitHub → Settings → Developer settings → Personal access token (repo)."
    Write-Host "Vai piesakieties: git credential manager"
    exit $LASTEXITCODE
}

Write-Host "Gatavs. cPanel → Git Version Control → Clone URL: $RemoteUrl"
