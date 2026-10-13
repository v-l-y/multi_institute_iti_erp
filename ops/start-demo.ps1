param([ValidateRange(1024,65535)][int]$Port=8082)
$ErrorActionPreference='Stop'
$repoRoot=Split-Path $PSScriptRoot -Parent
$settings=Join-Path $repoRoot '.demo-private/settings.env'
$accounts=Join-Path $repoRoot '.demo-private/accounts.json'
if (!(Test-Path -LiteralPath $settings)) {
    $bytes=New-Object byte[] 32
    $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes); $appSecret=[Convert]::ToBase64String($bytes)
        $rng.GetBytes($bytes); $rootSecret=[Convert]::ToBase64String($bytes)
    } finally { $rng.Dispose() }
    [IO.File]::WriteAllText($settings,"STAGING_PORT=$Port`nSTAGING_DB_PASSWORD=$appSecret`nSTAGING_ROOT_PASSWORD=$rootSecret`n",[Text.UTF8Encoding]::new($false))
}
$composeArgs=@('compose','-p','iti-erp-demo','--env-file','.demo-private/settings.env','-f','compose.staging.yaml')
Push-Location $repoRoot
try {
    & docker @composeArgs up --build -d --wait
    if ($LASTEXITCODE -ne 0) { throw 'Demo startup failed.' }
    if (!(Test-Path -LiteralPath $accounts)) {
        $seedOutput=& docker @composeArgs --profile audit run -T --rm -e UAT_DEMO_SEED=1 schema-audit php bin/seed-demo.php
        if ($LASTEXITCODE -ne 0) { throw 'Demo seed refused or failed. Existing database was not reset.' }
        $seedJson=$seedOutput -join "`n"
        $first=$seedJson.IndexOf('{'); $last=$seedJson.LastIndexOf('}')
        if ($first -lt 0 -or $last -le $first) { throw 'Missing demo credential JSON.' }
        $seedJson=$seedJson.Substring($first,$last-$first+1)
        $decoded=$seedJson | ConvertFrom-Json
        if (!$decoded.demo_only) { throw 'Invalid demo credential output.' }
        [IO.File]::WriteAllText($accounts,$seedJson,[Text.UTF8Encoding]::new($false))
    }
    Write-Host 'Persistent demo ready. Operator credentials: ignored .demo-private/accounts.json. Existing staging is separate.'
} finally { Pop-Location }
