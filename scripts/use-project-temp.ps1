# Dot-source this file in a development terminal to keep child-process temp files in this project.
$taskProjectRoot = Split-Path -Parent $PSScriptRoot
$taskTemporaryDirectory = Join-Path $taskProjectRoot 'work\tmp'
New-Item -ItemType Directory -Force -Path $taskTemporaryDirectory | Out-Null
$env:TEMP = $taskTemporaryDirectory
$env:TMP = $taskTemporaryDirectory
$env:NODE_COMPILE_CACHE = Join-Path $taskProjectRoot 'work\node-cache'
$env:PHP_INI_SCAN_DIR = Join-Path $taskProjectRoot 'backend\php-conf'
$env:PYTHONDONTWRITEBYTECODE = '1'
Write-Output "Temporary files for this terminal will use $taskTemporaryDirectory"
