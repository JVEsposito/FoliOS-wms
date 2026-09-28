param(
    [string] $Proyecto = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string] $Php
)

$ErrorActionPreference = 'Stop'
Import-Module ScheduledTasks -ErrorAction Stop
foreach ($nombre in @('FoliOS Worker', 'FoliOS Scheduler')) {
    $tarea = Get-ScheduledTask -TaskName $nombre -ErrorAction SilentlyContinue
    if (-not $tarea) {
        Write-Host "$nombre : no instalada"
        continue
    }
    $info = Get-ScheduledTaskInfo -TaskName $nombre
    Write-Host "$nombre : $($tarea.State) · ultima ejecucion $($info.LastRunTime) · resultado $($info.LastTaskResult)"
}

if (-not $Php) {
    $Php = (Get-ScheduledTask -TaskName 'FoliOS Scheduler' -ErrorAction SilentlyContinue).Actions.Execute
}
if (-not $Php) { $Php = 'php.exe' }
Push-Location (Resolve-Path $Proyecto).Path
try {
    & $Php artisan sistema:estado-procesos
    if ($LASTEXITCODE -ne 0) { throw "sistema:estado-procesos termino con codigo $LASTEXITCODE" }
} finally {
    Pop-Location
}
