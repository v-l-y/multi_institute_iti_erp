param([switch]$ExerciseOutage)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$settings = Join-Path $repoRoot '.staging.env'
$portLine = Get-Content -LiteralPath $settings | Where-Object { $_ -match '^STAGING_PORT=\d+$' }
if (@($portLine).Count -ne 1) { throw 'Invalid staging port configuration.' }
$baseUrl = 'http://127.0.0.1:' + $portLine.Split('=')[1]
$composeArgs = @('compose','-p','iti-erp-staging','--env-file','.staging.env','-f','compose.staging.yaml')
function Assert-Status([string]$Path,[int]$Expected) {
    $actual = 0
    try { $actual = (Invoke-WebRequest -Uri ($baseUrl + $Path) -UseBasicParsing -TimeoutSec 10).StatusCode }
    catch {
        if ($null -eq $_.Exception.Response) { throw }
        $actual = [int]$_.Exception.Response.StatusCode
    }
    if ($actual -ne $Expected) { throw "$Path expected $Expected, received $actual." }
}
Push-Location $repoRoot
try {
    Assert-Status '/api/v1/ready' 200
    Assert-Status '/api/v1/students' 401
    & docker @composeArgs exec -T backend php bin/verify-runtime-grants.php
    if ($LASTEXITCODE -ne 0) { throw 'Runtime grants verification failed.' }
    & docker @composeArgs --profile audit run --rm --build schema-audit
    if ($LASTEXITCODE -ne 0) { throw 'Schema audit failed.' }
    if ($ExerciseOutage) {
        try {
            & docker @composeArgs stop database
            if ($LASTEXITCODE -ne 0) { throw 'Could not stop staging database.' }
            Assert-Status '/api/v1/ready' 503
            Assert-Status '/api/v1/health' 200
        } finally {
            & docker @composeArgs up -d --wait database
            if ($LASTEXITCODE -ne 0) { throw 'Staging database recovery failed.' }
        }
        Assert-Status '/api/v1/ready' 200
    }
    Write-Host 'STAGING CHECKS PASSED' -ForegroundColor Green
} finally { Pop-Location }
