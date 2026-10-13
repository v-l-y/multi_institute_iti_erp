param([ValidateSet('demo','staging','production')][string]$Environment='demo',[string]$SourceProject='')
$ErrorActionPreference='Stop'
$repoRoot=Split-Path $PSScriptRoot -Parent
if ($Environment -eq 'production' -and !$SourceProject) { throw 'Explicit production SourceProject is required; this command pauses its backend during backup.' }
if ($SourceProject -and $SourceProject -notmatch '^[a-z0-9][a-z0-9_-]{0,63}$') { throw 'Invalid source project identity.' }
$project=if ($SourceProject) {$SourceProject} else {'iti-erp-'+$Environment}
$settings=switch ($Environment) {'demo' {'.demo-private/settings.env'} 'staging' {'.staging.env'} 'production' {'.env'}}
$keyFile=Join-Path $repoRoot ('.demo-private/backup-'+$Environment+'.key')
$restoreProject='iti-erp-restore-'+[Guid]::NewGuid().ToString('N').Substring(0,12)
$restoreSettings=Join-Path $repoRoot ('.demo-private/'+$restoreProject+'.env')
$archive=$Environment+'-'+[Guid]::NewGuid().ToString('N')+'.tar.gpg'
$rng=[Security.Cryptography.RandomNumberGenerator]::Create()
$bytes=New-Object byte[] 32
try {
    if (!(Test-Path -LiteralPath $keyFile)) {
        $rng.GetBytes($bytes)
        $secret=[BitConverter]::ToString($bytes).Replace('-','').ToLowerInvariant()
        [IO.File]::WriteAllText($keyFile,$secret,[Text.UTF8Encoding]::new($false))
    }
    $secret=[IO.File]::ReadAllText($keyFile).Trim()
    if ($secret -notmatch '^[a-f0-9]{64}$') { throw 'Invalid private backup key.' }
    $rng.GetBytes($bytes)
    [IO.File]::WriteAllText($restoreSettings,('RESTORE_DRILL_ROOT_PASSWORD='+[Convert]::ToBase64String($bytes)+"`n"),[Text.UTF8Encoding]::new($false))
} finally { $rng.Dispose() }
$sourceArgs=@('compose','-p',$project,'--env-file',$settings,'-f','compose.staging.yaml','-f','deployment/backup.compose.yaml','--profile','backup')
if ($Environment -eq 'production') {
    $sourceArgs=@('compose','-p',$project,'--env-file',$settings,'-f','compose.yaml','-f','compose.production.yaml','-f','deployment/backup-production.compose.yaml','--profile','backup')
}
$restoreArgs=@('compose','-p',$restoreProject,'--env-file',$restoreSettings,'-f','deployment/restore.compose.yaml','--profile','backup')
$inputJson=@{secret=$secret;archive=$archive} | ConvertTo-Json -Compress
$stopped=$false
Push-Location $repoRoot
try {
    & docker @sourceArgs build backup-worker
    if ($LASTEXITCODE -ne 0) { throw 'Backup worker build failed.' }
    try {
        & docker @sourceArgs stop backend
        if ($LASTEXITCODE -ne 0) { throw 'Could not quiesce source application.' }
        $stopped=$true
        $backupResult=$inputJson | & docker @sourceArgs run -T --rm --no-deps backup-worker --create
        if ($LASTEXITCODE -ne 0) { throw 'Encrypted paired backup failed.' }
    } finally {
        if ($stopped) {
            & docker @sourceArgs up -d --wait backend
            if ($LASTEXITCODE -ne 0) { throw 'Source application restart failed.' }
        }
    }
    & docker @restoreArgs up -d --wait restore-db
    if ($LASTEXITCODE -ne 0) { throw 'Isolated restore database failed to start.' }
    $restoreResult=$inputJson | & docker @restoreArgs run -T --rm --build restore-worker --restore
    if ($LASTEXITCODE -ne 0) { throw 'Independent paired restore verification failed.' }
    # Verify app-readable private files with Apache's unprivileged UID/GID.
    $readResult=& docker @restoreArgs run -T --rm --no-deps --user '33:33' --entrypoint php restore-worker bin/verify-restored-documents.php
    if ($LASTEXITCODE -ne 0) { throw 'Application-user restored document download verification failed.' }
    Write-Host 'ENCRYPTED DATABASE + PRIVATE FILE RESTORE AND APPLICATION-USER READ PASSED'
    Write-Host ('Encrypted archive retained: backups/'+$archive)
    Write-Host 'Recovery key stays in ignored .demo-private. Copy archive and key to separate protected locations.'
    Write-Host ($restoreResult -join "`n")
    Write-Host ($readResult -join "`n")
} finally {
    # Exact unique project created by this script, never source application volumes.
    & docker @restoreArgs down -v --remove-orphans
    $cleanupExit=$LASTEXITCODE
    $restoreImage=$restoreProject+'-restore-worker:latest'
    $imageId=& docker image ls --quiet $restoreImage
    if ($LASTEXITCODE -eq 0 -and $imageId) {
        & docker image rm $restoreImage
        if ($LASTEXITCODE -ne 0) { $cleanupExit=$LASTEXITCODE }
    }
    if ([IO.Path]::GetFullPath($restoreSettings).StartsWith([IO.Path]::GetFullPath((Join-Path $repoRoot '.demo-private'))+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $restoreSettings -ErrorAction SilentlyContinue
    }
    Pop-Location
    if ($cleanupExit -ne 0) { throw 'Restore drill cleanup failed.' }
}
