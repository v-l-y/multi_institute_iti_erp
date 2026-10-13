$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$testProject = 'iti-erp-verify-' + [Guid]::NewGuid().ToString('N').Substring(0,12)
Push-Location $repoRoot
try {
    & node --test backend/tests/schema-contracts.test.mjs
    if ($LASTEXITCODE -ne 0) { throw 'Schema contracts failed.' }
    & docker compose -p $testProject -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from tests
    if ($LASTEXITCODE -ne 0) { throw "Backend integration failed ($LASTEXITCODE)." }
    Write-Host 'BACKEND VERIFICATION PASSED' -ForegroundColor Green
    Write-Host 'This verifies isolated code/DB behavior; production TLS, recovery and UAT require separate evidence.'
} finally {
    & docker compose -p $testProject -f compose.test.yaml down
    $cleanupExit = $LASTEXITCODE
    Pop-Location
    if ($cleanupExit -ne 0) { throw "Test cleanup failed ($cleanupExit)." }
}
