param([ValidateRange(1024,65535)][int]$Port = 8081)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$settings = Join-Path $repoRoot '.staging.env'
if (!(Test-Path -LiteralPath $settings)) {
    $secretBytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($secretBytes)
    $appSecret = [Convert]::ToBase64String($secretBytes)
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($secretBytes)
    $rootSecret = [Convert]::ToBase64String($secretBytes)
    [IO.File]::WriteAllText($settings,"STAGING_PORT=$Port`nSTAGING_DB_PASSWORD=$appSecret`nSTAGING_ROOT_PASSWORD=$rootSecret`n",[Text.UTF8Encoding]::new($false))
}
Push-Location $repoRoot
try {
    & docker compose -p iti-erp-staging --env-file .staging.env -f compose.staging.yaml up --build -d --wait
    if ($LASTEXITCODE -ne 0) { throw "Staging startup failed ($LASTEXITCODE)." }
    Write-Host 'Local staging started. Port and credentials are in the ignored .staging.env file.'
    Write-Host 'No accounts or regulatory approvals have been seeded. Existing staging data is retained.'
} finally { Pop-Location }
