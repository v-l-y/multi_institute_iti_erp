param([switch]$ExerciseRestart)
$ErrorActionPreference='Stop'
$repoRoot=Split-Path $PSScriptRoot -Parent
$accounts=(Get-Content -LiteralPath (Join-Path $repoRoot '.demo-private/accounts.json') -Raw | ConvertFrom-Json).accounts
$portLine=Get-Content -LiteralPath (Join-Path $repoRoot '.demo-private/settings.env') | Where-Object { $_ -match '^STAGING_PORT=\d+$' }
if (@($portLine).Count -ne 1) { throw 'Invalid demo port.' }
$baseUrl='http://127.0.0.1:'+$portLine.Split('=')[1]
$script:checks=0
function Request-Uat([string]$Method,[string]$Path,[object]$Body=$null,[string]$Token='',[int]$Expected=200) {
    $arguments=@{Uri=($baseUrl+$Path);Method=$Method;UseBasicParsing=$true;TimeoutSec=15;ErrorAction='Stop'}
    if ($Token) { $arguments.Headers=@{Authorization=('Bearer '+$Token)} }
    if ($null -ne $Body) { $arguments.Body=($Body | ConvertTo-Json -Depth 10 -Compress); $arguments.ContentType='application/json' }
    $response=$null; $actual=0
    try { $response=Invoke-WebRequest @arguments; $actual=[int]$response.StatusCode }
    catch { if ($null -eq $_.Exception.Response) { throw }; $actual=[int]$_.Exception.Response.StatusCode }
    if ($actual -ne $Expected) { throw "$Method $Path expected $Expected, received $actual." }
    $script:checks++
    return $response
}
function Login-Uat([string]$Role) {
    $identity=$accounts.$Role
    $response=Request-Uat POST '/api/v1/auth/login' $identity
    $token=($response.Content | ConvertFrom-Json).data.access_token
    if (!$token) { throw 'Missing login token.' }
    return $token
}
$composeArgs=@('compose','-p','iti-erp-demo','--env-file','.demo-private/settings.env','-f','compose.staging.yaml')
Push-Location $repoRoot
try {
    $null=Request-Uat GET '/api/v1/ready'
    $null=Request-Uat GET '/api/v1/students' -Expected 401
    $tokens=@{}
    foreach ($role in @('principal','administrator','instructor','student','other-admin','developer')) {
        $tokens[$role]=Login-Uat $role
        $null=Request-Uat GET '/api/v1/auth/me' -Token $tokens[$role]
    }
    $null=Request-Uat GET '/api/v1/staff' -Token $tokens.administrator
    $null=Request-Uat GET '/api/v1/students' -Token $tokens.principal
    $null=Request-Uat GET '/api/v1/students/me' -Token $tokens.student
    $null=Request-Uat GET '/api/v1/students' -Token $tokens.student -Expected 403
    $null=Request-Uat GET '/api/v1/students' -Token $tokens.developer -Expected 403
    $null=Request-Uat GET '/api/v1/attendance/records/me' -Token $tokens.student
    $null=Request-Uat GET '/api/v1/attendance/records/assigned' -Token $tokens.instructor
    $null=Request-Uat GET '/api/v1/audit/events' -Token $tokens.principal
    $null=Request-Uat GET '/api/v1/formative/records/me' -Token $tokens.student
    $null=Request-Uat GET '/api/v1/fees/statements/me' -Token $tokens.student
    $null=Request-Uat GET '/api/v1/fees/statements?student_id=9101' -Token $tokens.'other-admin' -Expected 404
    $null=Request-Uat GET '/api/v1/students/export' -Token $tokens.administrator
    $null=Request-Uat POST '/api/v1/students/profile/correct' @{student_id=9101;changes=@{full_name='DEMO UAT Student A'};reason='DEMO staff-role UAT correction'} $tokens.administrator
    $pdf="%PDF-1.4`n1 0 obj`n<< /Type /Catalog >>`nendobj`n%%EOF`n"
    $upload=Request-Uat POST '/api/v1/documents/upload' @{student_id=9101;filename='DEMO-UAT.pdf';content_base64=[Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pdf))} $tokens.administrator 201
    $id=($upload.Content | ConvertFrom-Json).data.document_id
    $downloadPath='/api/v1/documents/download/me?document_id='+$id
    $before=Request-Uat GET $downloadPath -Token $tokens.student
    $null=Request-Uat GET ('/api/v1/documents/download?document_id='+$id) -Token $tokens.'other-admin' -Expected 404
    $null=Request-Uat GET ('/api/v1/documents/download?document_id='+$id) -Token $tokens.developer -Expected 403
    if ($ExerciseRestart) {
        & docker @composeArgs restart database backend
        if ($LASTEXITCODE -ne 0) { throw 'Demo restart failed.' }
        & docker @composeArgs up -d --wait
        if ($LASTEXITCODE -ne 0) { throw 'Demo restart readiness failed.' }
        $null=Request-Uat GET '/api/v1/ready'
        $tokens.student=Login-Uat student
        $after=Request-Uat GET $downloadPath -Token $tokens.student
        if ($before.RawContentLength -ne $after.RawContentLength) { throw 'Private document length changed across restart.' }
        if (($before.Content -is [byte[]]) -and ($after.Content -is [byte[]])) {
            if ([Convert]::ToBase64String($before.Content) -ne [Convert]::ToBase64String($after.Content)) { throw 'Document bytes changed across restart.' }
        } elseif ([string]$before.Content -ne [string]$after.Content) { throw 'Document content changed across restart.' }
        $profile=Request-Uat GET '/api/v1/students/me' -Token $tokens.student
        if (($profile.Content | ConvertFrom-Json).data.full_name -ne 'DEMO UAT Student A') { throw 'Profile correction did not persist.' }
        $script:checks+=2
    }
    & docker @composeArgs exec -T backend php bin/verify-runtime-grants.php
    if ($LASTEXITCODE -ne 0) { throw 'Demo runtime grants failed.' }
    & docker @composeArgs --profile audit run --rm schema-audit
    if ($LASTEXITCODE -ne 0) { throw 'Demo schema preflight failed.' }
    Write-Host "PERSISTENT DEMO CHECKS PASSED ($script:checks HTTP/persistence checks). Sample document ID: $id"
    Write-Host 'Human institute staff sign-off is still required. No credentials or tokens were printed.'
} finally { Pop-Location }
