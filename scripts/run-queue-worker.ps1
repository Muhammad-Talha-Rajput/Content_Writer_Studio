param([Parameter(Mandatory=$true)][ValidateSet('generation','sources')][string]$Queue)

. (Join-Path $PSScriptRoot 'use-project-temp.ps1')
$workerHash = [System.Security.Cryptography.SHA256]::Create()
$workerIdentity = [BitConverter]::ToString($workerHash.ComputeHash([Text.Encoding]::UTF8.GetBytes($taskProjectRoot.ToLowerInvariant()))).Replace('-', '')
$workerHash.Dispose()
$workerName = if ($Queue -eq 'generation') { 'Generation' } else { 'Sources' }
$workerTimeout = if ($Queue -eq 'generation') { 1860 } else { 120 }
$workerMutex = [System.Threading.Mutex]::new($false, ('Local\GrandsDigital' + $workerName + '-' + $workerIdentity))
$workerOwnsMutex = $false
try {
    try { $workerOwnsMutex = $workerMutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $workerOwnsMutex = $true }
    if (-not $workerOwnsMutex) { Write-Output "A $Queue worker is already running for this project."; exit 0 }
    Push-Location (Join-Path $taskProjectRoot 'backend')
    try {
        $workerRetrySeconds = 2
        while ($true) {
            # A new PHP process per job picks up code changes and fresh DB connections.
            & php artisan queue:work $Queue --queue=$Queue --tries=1 --timeout=$workerTimeout --sleep=2 --max-jobs=1
            $workerExitCode = $LASTEXITCODE
            if ($workerExitCode -eq 0) { $workerRetrySeconds = 2; continue }
            Write-Output ('{0:u} {1} worker exited ({2}); retrying in {3} seconds. Pending jobs remain in the database.' -f [DateTime]::UtcNow, $Queue, $workerExitCode, $workerRetrySeconds)
            Start-Sleep -Seconds $workerRetrySeconds
            $workerRetrySeconds = [Math]::Min(30, $workerRetrySeconds * 2)
        }
    } finally { Pop-Location }
} finally {
    if ($workerOwnsMutex) { $workerMutex.ReleaseMutex() }
    $workerMutex.Dispose()
}
